<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Db\TentativeUserMapper;
use OCA\W3dsLogin\Db\W3dsMapping;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class UserProvisioningService {
	/** Per-user config key recording the last avatar refresh attempt. */
	private const AVATAR_CHECKED_AT_KEY = 'avatar_checked_at';

	/** Avatars are cosmetic; one eVault round trip per user per day is plenty. */
	private const AVATAR_REFRESH_INTERVAL = 86400;

	public function __construct(
		private W3dsMappingMapper $mapper,
		private IUserManager $userManager,
		private ISecureRandom $secureRandom,
		private EvaultClient $evaultClient,
		private IConfig $config,
		private TentativeUserMapper $tentativeUserMapper,
		private LoggerInterface $logger,
		private AvatarSyncService $avatarSync,
	) {
	}

	/**
	 * Find an existing user by W3ID, or create a new Nextcloud user.
	 *
	 * When $tentative is true and a fresh account is created (collaborator
	 * search flow), the new account is flagged tentative. The flag is
	 * cleared by AttendeesAddedTentativeFlipListener when the user is
	 * actually added to a Talk room, otherwise garbage-collected by
	 * TentativeUserCleanupJob after expiry.
	 *
	 * If the caller already has the parsed profile fields (e.g. from a
	 * by-ontology list response), pass them as $prefetchedProfile to skip
	 * the secondary eVault fetches inside hydrateProfileFromEvault. This
	 * matters during collaborator search where many w3ids resolve at once.
	 *
	 * @param array<string, mixed>|null $prefetchedProfile
	 */
	public function findOrCreateUser(string $w3id, bool $tentative = false, ?array $prefetchedProfile = null): ?IUser {
		// Fast path: already mapped.
		$existing = $this->findMappedUser($w3id);
		if ($existing !== null) {
			// The display name is only ever written at creation time, so an
			// account created while the eVault was unreachable keeps showing
			// the raw eName forever -- there is no other moment that would
			// ever correct it. Retry the hydration now that the user is
			// clearly reachable again.
			$this->rehydrateIfPlaceholderName($existing, $w3id, $prefetchedProfile);

			return $existing;
		}

		return $this->createMappedUser($w3id, $tentative, $prefetchedProfile);
	}

	/**
	 * Re-run profile hydration for a user still carrying the eName as their
	 * display name.
	 *
	 * Hydration is best-effort by design: a rate-limited or briefly
	 * unreachable eVault must not block login. But the placeholder it leaves
	 * behind is permanent, and an eName reads as a raw UUID, so the user (and
	 * everyone mentioning them) sees `@16894677-6b61-...` instead of a name.
	 * Cheap to retry, and a no-op once a real name is set.
	 *
	 * @param array<string, mixed>|null $prefetchedProfile
	 */
	private function rehydrateIfPlaceholderName(IUser $user, string $w3id, ?array $prefetchedProfile = null): void {
		if ($user->getDisplayName() !== $w3id) {
			return;
		}

		$this->hydrateProfileFromEvault($user, $w3id, $prefetchedProfile);
	}

	/**
	 * Refresh a linked user's avatar from their eVault profile.
	 *
	 * The provisioning path only runs once, so without this a picture
	 * changed on another platform would never propagate. Throttled via a
	 * per-user config timestamp: a login is a hot path and the avatar is
	 * cosmetic, so at most one eVault round trip per user per interval.
	 *
	 * Silent on every failure -- this must never obstruct a login.
	 */
	public function refreshAvatarIfStale(IUser $user, string $w3id): void {
		try {
			$uid = $user->getUID();

			// An avatar Nextcloud cannot resize renders as initials in every
			// place that asks for a size it has not already cached, and the
			// throttle below would otherwise leave it that way for a day
			// after the fix that would repair it. Bypass the throttle for
			// that specific, self-diagnosing case.
			$needsRepair = $this->avatarSync->storedAvatarNeedsRepair($uid);

			$last = (int)$this->config->getUserValue($uid, Application::APP_ID, self::AVATAR_CHECKED_AT_KEY, '0');
			if (!$needsRepair && $last > 0 && (time() - $last) < self::AVATAR_REFRESH_INTERVAL) {
				return;
			}

			// Record the attempt before doing the work, so a persistently
			// failing eVault doesn't re-trigger a fetch on every login.
			$this->config->setUserValue($uid, Application::APP_ID, self::AVATAR_CHECKED_AT_KEY, (string)time());

			$profileId = $this->evaultClient->getProfileEnvelopeId($w3id);
			if ($profileId === null) {
				return;
			}
			$envelope = $this->evaultClient->fetchMetaEnvelopeById($w3id, $profileId);
			$parsed = $envelope['parsed'] ?? null;
			if (is_array($parsed)) {
				$this->avatarSync->syncFromProfile($uid, $parsed);
			}
		} catch (\Throwable $e) {
			$this->logger->info('Avatar refresh failed; keeping existing avatar', [
				'w3id' => $w3id,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Return the NC user already mapped to this W3ID, or null. If a mapping
	 * exists but its NC account was deleted, the stale mapping is removed.
	 */
	private function findMappedUser(string $w3id): ?IUser {
		try {
			$mapping = $this->mapper->findByW3id($w3id);
			$user = $this->userManager->get($mapping->getNcUid());
			if ($user !== null) {
				return $user;
			}

			// Mapping exists but user was deleted; clean up and re-create
			$this->mapper->delete($mapping);
		} catch (DoesNotExistException) {
			// No mapping yet
		}
		return null;
	}

	/**
	 * @param array<string, mixed>|null $prefetchedProfile
	 */
	private function createMappedUser(string $w3id, bool $tentative, ?array $prefetchedProfile): ?IUser {
		// deriveUsername() is deterministic per w3id, so concurrent picker
		// requests for the same identity all derive the SAME username.
		$username = $this->deriveUsername($w3id);

		// Generate a random password (user will never need it; they auth via W3DS)
		$password = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

		try {
			$user = $this->userManager->createUser($username, $password);
		} catch (\Throwable $createErr) {
			// The username is taken. Because deriveUsername() is deterministic
			// this almost always means a concurrent request is provisioning the
			// SAME w3id right now (Talk fires the picker once per keystroke).
			// Resolve to that winner instead of leaking a second account.
			$winner = $this->resolveProvisioningRaceWinner($w3id);
			if ($winner !== null) {
				return $winner;
			}

			// No mapping ever appeared, so this is not a race: the account
			// exists from an earlier attempt whose mapping insert never
			// landed. Because deriveUsername() is deterministic, that
			// account belongs to this very w3id and nothing else can claim
			// it. Adopt it instead of returning null, which would otherwise
			// leave the identity permanently unusable -- unable to log in,
			// unable to be mentioned, and stuck displaying its raw eName.
			$adopted = $this->adoptUnmappedAccount($w3id, $username, $prefetchedProfile);
			if ($adopted !== null) {
				return $adopted;
			}

			$this->logger->error('Failed to create Nextcloud user (username taken, no mapping resolved)', [
				'w3id' => $w3id,
				'username' => $username,
				'exception' => $createErr->getMessage(),
			]);
			return null;
		}
		if ($user === null) {
			$this->logger->error('Failed to create Nextcloud user', [
				'w3id' => $w3id,
				'username' => $username,
			]);
			return null;
		}

		$user->setDisplayName($w3id);

		// Store the W3ID mapping. The unique index on w3id can throw if a
		// concurrent picker request just inserted the same mapping (each
		// keystroke runs the plugin and they race past findByW3id). In that
		// case, delete our just-created orphan NC user and return the
		// winner's user instead -- otherwise we leak a clone account.
		$mapping = new W3dsMapping();
		$mapping->setW3id($w3id);
		$mapping->setNcUid($user->getUID());
		$mapping->setCreatedAt(time());
		try {
			$this->mapper->insert($mapping);
		} catch (\Throwable $insertErr) {
			try {
				$existing = $this->mapper->findByW3id($w3id);
				$existingUser = $this->userManager->get($existing->getNcUid());
				if ($existingUser !== null) {
					$this->logger->info('findOrCreateUser: lost race, deleting orphan NC user', [
						'w3id' => $w3id,
						'orphanUid' => $user->getUID(),
						'winnerUid' => $existing->getNcUid(),
					]);
					try {
						$user->delete();
					} catch (\Throwable) {
					}
					return $existingUser;
				}
			} catch (DoesNotExistException) {
				// the unique violation was for nc_uid (someone else made an account
				// with the same derived username). Re-raise the original failure.
			}
			throw $insertErr;
		}

		// Best-effort: replace the W3ID-based display name and empty email
		// with whatever the user's eVault profile has.
		$this->hydrateProfileFromEvault($user, $w3id, $prefetchedProfile);

		// Tag the account so the dedupe command can identify W3DS-provisioned
		// race-loser orphans (NC user exists, mapping insert lost the unique
		// index contest). The random password from above stays in place; the
		// session token scope set in AuthController::completeLogin suppresses
		// the sudo-mode prompts that would otherwise demand it.
		$this->config->setUserValue(
			$user->getUID(),
			Application::APP_ID,
			'provisioned',
			'1',
		);

		if ($tentative) {
			try {
				$this->tentativeUserMapper->markTentative($user->getUID(), time() + 1800);
			} catch (\Throwable $e) {
				$this->logger->warning('Failed to mark user tentative on creation', [
					'uid' => $user->getUID(),
					'exception' => $e->getMessage(),
				]);
			}
		}

		$this->logger->info('Provisioned new user from W3DS', [
			'w3id' => $w3id,
			'uid' => $user->getUID(),
			'tentative' => $tentative,
		]);

		return $user;
	}

	/**
	 * Resolve the winner of a concurrent first-time provisioning race. The
	 * winner's createUser() has succeeded but its mapping insert may still
	 * be in flight, so we retry findMappedUser a few times before giving up.
	 */
	private function resolveProvisioningRaceWinner(string $w3id): ?IUser {
		for ($i = 0; $i < 6; $i++) {
			$winner = $this->findMappedUser($w3id);
			if ($winner !== null) {
				return $winner;
			}
			usleep(50000); // 50ms; ~300ms total before giving up
		}
		return null;
	}

	/**
	 * Claim an existing NC account that was provisioned for this W3ID but
	 * never got its mapping row.
	 *
	 * deriveUsername() is a pure function of the w3id, so an account holding
	 * that exact username was created for this identity and no other. Writing
	 * the missing mapping is therefore a repair, not a guess.
	 *
	 * Returns null if the account cannot be adopted, including when another
	 * mapping already claims it, so a mismatch is never papered over.
	 *
	 * @param array<string, mixed>|null $prefetchedProfile
	 */
	private function adoptUnmappedAccount(string $w3id, string $username, ?array $prefetchedProfile): ?IUser {
		$user = $this->userManager->get($username);
		if ($user === null) {
			return null;
		}

		try {
			$existingMapping = $this->mapper->findByNcUid($username);

			// Mapped to the very w3id we were asked for. The lookup at the
			// top of findOrCreateUser missed it because the mapping landed
			// concurrently, so this is simply the winner of a race we lost:
			// return it. Treating this as a conflict is what left a peer
			// unprovisioned and therefore missing from their conversations.
			if ($existingMapping->getW3id() === $w3id) {
				return $user;
			}

			// Already mapped, to a different w3id than the one we were asked
			// for. Two identities deriving one username would be a hash
			// collision; refuse rather than retarget someone's account.
			$this->logger->error('Refusing to adopt an account mapped to another W3ID', [
				'w3id' => $w3id,
				'uid' => $username,
				'mappedW3id' => $existingMapping->getW3id(),
			]);

			return null;
		} catch (DoesNotExistException) {
			// Unmapped, as expected for the repair case.
		}

		$mapping = new W3dsMapping();
		$mapping->setW3id($w3id);
		$mapping->setNcUid($username);
		$mapping->setCreatedAt(time());
		try {
			$this->mapper->insert($mapping);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to adopt orphaned W3DS account', [
				'w3id' => $w3id,
				'uid' => $username,
				'exception' => $e->getMessage(),
			]);

			return null;
		}

		$this->logger->info('Adopted orphaned W3DS account missing its mapping', [
			'w3id' => $w3id,
			'uid' => $username,
		]);

		$this->rehydrateIfPlaceholderName($user, $w3id, $prefetchedProfile);

		return $user;
	}

	/**
	 * Copy the user's displayName and email from their eVault User profile
	 * onto the freshly-created NC account. Never throws: a broken eVault
	 * must not prevent login.
	 *
	 * If $prefetchedParsed is provided, skips the two eVault round-trips
	 * (getProfileEnvelopeId + fetchMetaEnvelopeById) and uses the supplied
	 * parsed fields directly.
	 *
	 * @param array<string, mixed>|null $prefetchedParsed
	 */
	private function hydrateProfileFromEvault(IUser $user, string $w3id, ?array $prefetchedParsed = null): void {
		try {
			$parsed = $prefetchedParsed;
			if ($parsed === null) {
				$profileId = $this->evaultClient->getProfileEnvelopeId($w3id);
				if ($profileId === null) {
					return;
				}
				$envelope = $this->evaultClient->fetchMetaEnvelopeById($w3id, $profileId);
				$parsed = $envelope['parsed'] ?? null;
			}
			if (!is_array($parsed)) {
				return;
			}

			$displayName = $this->pickDisplayName($parsed, $w3id);
			if ($displayName !== '' && $displayName !== $w3id) {
				$user->setDisplayName($displayName);
			}

			$email = $parsed['email'] ?? null;
			if (is_string($email) && $email !== '') {
				$user->setEMailAddress($email);
			}

			// Auto-provisioned accounts otherwise land with no visual identity
			// at all, so a busy group chat renders as a wall of identical
			// initials. Failures here are logged and swallowed inside the
			// avatar service; the account is still perfectly usable without one.
			$this->avatarSync->syncFromProfile($user->getUID(), $parsed);
		} catch (\Throwable $e) {
			$this->logger->info('Could not hydrate profile from eVault; continuing with defaults', [
				'w3id' => $w3id,
				'uid' => $user->getUID(),
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Best display name a profile can offer, falling back to the eName.
	 *
	 * Profiles are written by several platforms and are not consistent about
	 * name fields: some send `displayName`, some the schema.org
	 * `givenName`/`familyName` pair, some plain `firstName`/`lastName`.
	 * A profile that only carries the latter is a real person with a real
	 * name, so ignoring that shape shows them a raw UUID for no reason.
	 *
	 * A `displayName` equal to the eName is a placeholder the profile itself
	 * never filled in, so the name parts are preferred over it.
	 *
	 * @param array<string, mixed> $parsed
	 */
	private function pickDisplayName(array $parsed, string $w3id): string {
		$candidate = $parsed['displayName'] ?? null;
		if (is_string($candidate) && trim($candidate) !== '' && trim($candidate) !== $w3id) {
			return trim($candidate);
		}

		foreach ([['givenName', 'familyName'], ['firstName', 'lastName']] as [$first, $last]) {
			$a = is_string($parsed[$first] ?? null) ? trim($parsed[$first]) : '';
			$b = is_string($parsed[$last] ?? null) ? trim($parsed[$last]) : '';
			$joined = trim($a . ' ' . $b);
			if ($joined !== '') {
				return $joined;
			}
		}

		return $w3id;
	}

	/**
	 * Link an existing Nextcloud user to a W3ID.
	 *
	 * @throws \RuntimeException If the W3ID is already linked to another user
	 */
	public function linkUser(string $w3id, string $ncUid): void {
		try {
			$existing = $this->mapper->findByW3id($w3id);
			if ($existing->getNcUid() === $ncUid) {
				return; // Already linked to this user, nothing to do
			}
			throw new \RuntimeException('This W3DS identity is already linked to another account');
		} catch (DoesNotExistException) {
			// Not linked yet, proceed
		}

		// Remove any existing mapping for this NC user
		$this->mapper->deleteByNcUid($ncUid);

		$mapping = new W3dsMapping();
		$mapping->setW3id($w3id);
		$mapping->setNcUid($ncUid);
		$mapping->setCreatedAt(time());
		$this->mapper->insert($mapping);
	}

	/**
	 * Unlink a Nextcloud user from their W3ID.
	 */
	public function unlinkUser(string $ncUid): void {
		$this->mapper->deleteByNcUid($ncUid);
	}

	/**
	 * Get the linked W3ID for a Nextcloud user, or null if not linked.
	 */
	public function getLinkedW3id(string $ncUid): ?string {
		try {
			$mapping = $this->mapper->findByNcUid($ncUid);
			return $mapping->getW3id();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Derive a Nextcloud username from a W3ID.
	 * Example: "@alice.w3id" becomes "alice_w3id_1a2b3c4d".
	 *
	 * The result is DETERMINISTIC: the same w3id always yields the same
	 * username. This is what keeps concurrent provisioning safe -- racing
	 * createUser() calls for the same identity collide on the username, so
	 * at most one account can ever be created per w3id and no orphan clones
	 * leak. The trailing hash also makes a collision with an unrelated,
	 * human-chosen username effectively impossible, which is why there is
	 * no uniqueness loop here.
	 */
	private function deriveUsername(string $w3id): string {
		$name = ltrim($w3id, '@');
		$name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
		$name = trim($name ?? '', '_');

		if ($name === '') {
			$name = 'w3ds_user';
		}

		// nc_uid column is 64 chars; keep room for "_" + 8 hex.
		$suffix = substr(hash('sha256', $w3id), 0, 8);
		return substr($name, 0, 55) . '_' . $suffix;
	}
}
