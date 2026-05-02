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
	public function __construct(
		private W3dsMappingMapper $mapper,
		private IUserManager $userManager,
		private ISecureRandom $secureRandom,
		private EvaultClient $evaultClient,
		private IConfig $config,
		private TentativeUserMapper $tentativeUserMapper,
		private LoggerInterface $logger,
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
		// Check for existing mapping
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

		// Derive a username from the W3ID (strip the leading @, replace dots)
		$username = $this->deriveUsername($w3id);

		// Generate a random password (user will never need it; they auth via W3DS)
		$password = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

		$user = $this->userManager->createUser($username, $password);
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
					try { $user->delete(); } catch (\Throwable) {}
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

		// The random password above is a throwaway -- force the user to set
		// a real one on first login so WebDAV / desktop clients / mobile
		// apps can still authenticate.
		$this->config->setUserValue(
			$user->getUID(),
			Application::APP_ID,
			'must_set_password',
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
		} catch (\Throwable $e) {
			$this->logger->info('Could not hydrate profile from eVault; continuing with defaults', [
				'w3id' => $w3id,
				'uid' => $user->getUID(),
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * @param array<string, mixed> $parsed
	 */
	private function pickDisplayName(array $parsed, string $w3id): string {
		$candidate = $parsed['displayName'] ?? null;
		if (is_string($candidate) && trim($candidate) !== '') {
			return trim($candidate);
		}

		$given = is_string($parsed['givenName'] ?? null) ? trim($parsed['givenName']) : '';
		$family = is_string($parsed['familyName'] ?? null) ? trim($parsed['familyName']) : '';
		$joined = trim($given . ' ' . $family);
		if ($joined !== '') {
			return $joined;
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
	 * Example: "@alice.w3id" becomes "alice_w3id"
	 */
	private function deriveUsername(string $w3id): string {
		$name = ltrim($w3id, '@');
		$name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $name);
		$name = trim($name, '_');

		if (empty($name)) {
			$name = 'w3ds_user';
		}

		// Ensure uniqueness
		$candidate = $name;
		$suffix = 1;
		while ($this->userManager->userExists($candidate)) {
			$candidate = $name . '_' . $suffix;
			$suffix++;
		}

		return $candidate;
	}
}
