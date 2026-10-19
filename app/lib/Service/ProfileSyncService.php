<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCP\Accounts\IAccountManager;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;

/**
 * Copies a linked user's eVault profile onto their Nextcloud account.
 *
 * The eVault is the source of truth for who someone is, so this runs on
 * every login, after any identity provider has written its own claims, and
 * a non-empty profile value always wins. An empty or missing field leaves
 * whatever Nextcloud already holds: profiles are written by many platforms
 * and most of them only fill a few fields.
 *
 * Nothing here may obstruct a login. Every failure is logged and swallowed,
 * and an unreachable eVault is backed off so it does not add its timeout to
 * every login while it is down.
 */
class ProfileSyncService {
	/** How long an eName whose profile could not be read is left alone. */
	private const FAILURE_BACKOFF = 300;

	private const MAX_FIELD_LENGTH = 2000;

	/**
	 * Account properties written from the profile. Language, social handles
	 * and birthdate are left out: the eVault does not carry the last two,
	 * user_oidc already handles the first, and Nextcloud verifies fediverse
	 * handles with a remote lookup that has no place in a login request.
	 */
	private const ACCOUNT_PROPERTIES = [
		IAccountManager::PROPERTY_PHONE,
		IAccountManager::PROPERTY_ADDRESS,
		IAccountManager::PROPERTY_WEBSITE,
		IAccountManager::PROPERTY_BIOGRAPHY,
		IAccountManager::PROPERTY_HEADLINE,
		IAccountManager::PROPERTY_ORGANISATION,
		IAccountManager::PROPERTY_ROLE,
	];

	private const OIDC_USER_MAPPER = 'OCA\\UserOIDC\\Db\\UserMapper';
	private const OIDC_USER_BACKEND = 'OCA\\UserOIDC\\User\\Backend';

	private ICache $cache;

	/**
	 * Profiles already read in this request, keyed by eName. Creating an
	 * account and syncing it at login can happen in one request; the second
	 * pass must not pay for the eVault again.
	 *
	 * @var array<string, array{user: array<string, mixed>|null, professional: array<string, mixed>|null}>
	 */
	private array $memo = [];

	public function __construct(
		private EvaultClient $evaultClient,
		private AvatarSyncService $avatarSync,
		private IAccountManager $accountManager,
		private IEventDispatcher $eventDispatcher,
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed('w3ds_login_profile_sync');
	}

	/**
	 * Read the user's eVault profiles and apply them. Never throws.
	 */
	public function syncProfile(IUser $user, string $w3id): void {
		try {
			$profiles = $this->readProfiles($w3id);
			if ($profiles === null) {
				return;
			}

			$this->applyProfile($user, $w3id, $profiles['user'] ?? [], $profiles['professional']);
		} catch (\Throwable $e) {
			$this->logger->info('Could not sync profile from eVault; keeping current profile', [
				'w3id' => $w3id,
				'uid' => $user->getUID(),
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * Apply already-read profile bodies to the user. Never throws.
	 *
	 * The User profile is the primary source; the ProfessionalProfile fills
	 * what it leaves empty and is the only source of a headline and a job.
	 *
	 * @param array<string, mixed> $parsed The User profile body
	 * @param array<string, mixed>|null $professional The ProfessionalProfile body
	 */
	public function applyProfile(IUser $user, string $w3id, array $parsed, ?array $professional = null): void {
		$professional ??= [];
		$uid = $user->getUID();

		try {
			$displayName = self::pickDisplayName($parsed, $w3id);
			if ($displayName === $w3id) {
				$displayName = self::text($professional['displayName'] ?? null) ?? $w3id;
			}
			if ($displayName !== $w3id) {
				$this->applyDisplayName($user, $displayName);
			}
		} catch (\Throwable $e) {
			$this->logFieldFailure($uid, 'displayName', $e);
		}

		try {
			$email = self::email($parsed['email'] ?? null) ?? self::email($professional['email'] ?? null);
			if ($email !== null && $email !== $user->getSystemEMailAddress()) {
				$user->setSystemEMailAddress($email);
			}
		} catch (\Throwable $e) {
			$this->logFieldFailure($uid, 'email', $e);
		}

		// Auto-provisioned accounts otherwise land with no visual identity
		// at all, so a busy group chat renders as a wall of identical
		// initials. The avatar service swallows its own failures.
		$avatarProfile = $parsed;
		if (!self::hasAvatarSource($avatarProfile) && is_string($professional['avatar'] ?? null)) {
			$avatarProfile = ['avatar' => $professional['avatar']];
		}
		$this->avatarSync->syncIfChanged($uid, $avatarProfile);

		try {
			$this->applyAccountProperties($user, self::accountValues($parsed, $professional));
		} catch (\Throwable $e) {
			$this->logFieldFailure($uid, 'account properties', $e);
		}
	}

	/**
	 * The display name the eVault profile gives this eName, or null when it
	 * has none or cannot be read. Shares this request's read with
	 * {@see syncProfile()}.
	 */
	public function displayNameFor(string $w3id): ?string {
		try {
			$profiles = $this->readProfiles($w3id);
		} catch (\Throwable) {
			return null;
		}
		if ($profiles === null) {
			return null;
		}
		$name = self::pickDisplayName($profiles['user'] ?? [], $w3id);
		if ($name === $w3id) {
			$name = self::text($profiles['professional']['displayName'] ?? null) ?? $w3id;
		}

		return $name !== $w3id ? $name : null;
	}

	/**
	 * The email address the eVault profile gives this eName, or null.
	 */
	public function emailFor(string $w3id): ?string {
		try {
			$profiles = $this->readProfiles($w3id);
		} catch (\Throwable) {
			return null;
		}

		return $profiles === null ? null
			: (self::email($profiles['user']['email'] ?? null) ?? self::email($profiles['professional']['email'] ?? null));
	}

	/**
	 * Best display name a profile can offer, falling back to the eName.
	 *
	 * Profiles are written by several platforms and are not consistent about
	 * name fields: some send `displayName`, some the schema.org
	 * `givenName`/`familyName` pair, some plain `firstName`/`lastName`, and
	 * a few only `name`. A profile that only carries one of the latter is a
	 * real person with a real name, so ignoring that shape shows them a raw
	 * UUID for no reason.
	 *
	 * A `displayName` equal to the eName is a placeholder the profile itself
	 * never filled in, so the name parts are preferred over it.
	 *
	 * @param array<string, mixed> $parsed
	 */
	public static function pickDisplayName(array $parsed, string $w3id): string {
		foreach (['displayName', 'name'] as $field) {
			$candidate = $parsed[$field] ?? null;
			if (is_string($candidate) && trim($candidate) !== '' && trim($candidate) !== $w3id) {
				return trim($candidate);
			}
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
	 * The account property values a profile asks for, keyed by property.
	 * Properties the profile has nothing for are absent, never empty.
	 *
	 * @param array<string, mixed> $parsed
	 * @param array<string, mixed> $professional
	 * @return array<string, string>
	 */
	public static function accountValues(array $parsed, array $professional): array {
		$job = self::currentJob($professional['workExperience'] ?? null);

		$values = [
			IAccountManager::PROPERTY_PHONE => self::text($parsed['telephone'] ?? null) ?? self::text($professional['phone'] ?? null),
			IAccountManager::PROPERTY_ADDRESS => self::formatAddress($parsed['address'] ?? null)
				?? self::text($parsed['location'] ?? null)
				?? self::text($professional['location'] ?? null),
			IAccountManager::PROPERTY_WEBSITE => self::website($parsed['website'] ?? null) ?? self::website($professional['website'] ?? null),
			IAccountManager::PROPERTY_BIOGRAPHY => self::text($parsed['bio'] ?? null) ?? self::text($professional['bio'] ?? null),
			IAccountManager::PROPERTY_HEADLINE => self::text($professional['headline'] ?? null),
			IAccountManager::PROPERTY_ORGANISATION => $job !== null ? self::text($job['company'] ?? null) : null,
			IAccountManager::PROPERTY_ROLE => $job !== null ? self::text($job['role'] ?? null) : null,
		];

		return array_filter($values, static fn (?string $v): bool => $v !== null);
	}

	/**
	 * The schema.org postal address as one line, or null when it has none
	 * of its parts.
	 */
	public static function formatAddress(mixed $address): ?string {
		if (!is_array($address)) {
			return null;
		}

		$locality = trim((self::text($address['postalCode'] ?? null) ?? '') . ' ' . (self::text($address['addressLocality'] ?? null) ?? ''));
		$parts = array_filter([
			self::text($address['streetAddress'] ?? null),
			$locality !== '' ? $locality : null,
			self::text($address['addressCountry'] ?? null),
		], static fn (?string $v): bool => $v !== null);

		return $parts === [] ? null : implode(', ', $parts);
	}

	/**
	 * A website Nextcloud will accept: http(s) only, with the scheme added
	 * when a profile carries a bare host.
	 */
	public static function website(mixed $value): ?string {
		$url = self::text($value);
		if ($url === null) {
			return null;
		}
		if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
			$url = 'https://' . $url;
		}
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		if (!in_array($scheme, ['http', 'https'], true) || !is_string(parse_url($url, PHP_URL_HOST))) {
			return null;
		}

		return $url;
	}

	/**
	 * The job someone holds now: an entry without an end date, first in the
	 * profile's own display order, else the most recently started.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function currentJob(mixed $workExperience): ?array {
		if (!is_array($workExperience)) {
			return null;
		}

		$current = array_values(array_filter(
			$workExperience,
			static fn ($e): bool => is_array($e) && self::text($e['endDate'] ?? null) === null
				&& (self::text($e['company'] ?? null) !== null || self::text($e['role'] ?? null) !== null),
		));
		if ($current === []) {
			return null;
		}

		usort($current, static function (array $a, array $b): int {
			$order = ($a['sortOrder'] ?? PHP_INT_MAX) <=> ($b['sortOrder'] ?? PHP_INT_MAX);
			return $order !== 0 ? $order : strcmp((string)($b['startDate'] ?? ''), (string)($a['startDate'] ?? ''));
		});

		return $current[0];
	}

	/**
	 * Both profile bodies for an eName, or null when the User profile could
	 * not be read and nothing should be applied.
	 *
	 * @return array{user: array<string, mixed>|null, professional: array<string, mixed>|null}|null
	 */
	private function readProfiles(string $w3id): ?array {
		if (isset($this->memo[$w3id])) {
			return $this->memo[$w3id];
		}

		$backoffKey = 'backoff_' . md5($w3id);
		if ($this->cache->get($backoffKey) !== null) {
			return null;
		}

		$user = null;
		$profileId = $this->evaultClient->getProfileEnvelopeId($w3id);
		if ($profileId !== null) {
			$envelope = $this->evaultClient->fetchMetaEnvelopeById($w3id, $profileId);
			$user = is_array($envelope['parsed'] ?? null) ? $envelope['parsed'] : null;
		}

		if ($user === null) {
			// Either the eVault is unreachable or the profile is missing;
			// both mean the next few logins would learn nothing either.
			$this->cache->set($backoffKey, 1, self::FAILURE_BACKOFF);
			return null;
		}

		$professional = null;
		try {
			$professional = $this->evaultClient->fetchProfessionalProfile($w3id);
		} catch (\Throwable) {
			// Optional by nature; the User profile alone is still worth applying.
		}

		return $this->memo[$w3id] = ['user' => $user, 'professional' => $professional];
	}

	/**
	 * Set the display name, including for accounts whose backend refuses.
	 *
	 * Accounts created by user_oidc live in a backend without a setter, so
	 * IUser::setDisplayName() returns false and changes nothing. user_oidc
	 * keeps the name in its own table and writes it there itself on every
	 * login from the IdP's claim, which is exactly the value this sync must
	 * override. Writing it the same way is the only route in.
	 */
	private function applyDisplayName(IUser $user, string $displayName): void {
		$old = $user->getDisplayName();
		if ($old === $displayName) {
			return;
		}

		if ($user->setDisplayName($displayName)) {
			return;
		}

		if (!$this->isOidcUser($user) || !class_exists(self::OIDC_USER_MAPPER)) {
			$this->logger->info('User backend does not allow setting the display name from the eVault', [
				'uid' => $user->getUID(),
				'backend' => $user->getBackendClassName(),
			]);
			return;
		}

		/** @psalm-suppress UndefinedClass */
		$mapper = \OCP\Server::get(self::OIDC_USER_MAPPER);
		$entity = $mapper->getUser($user->getUID());
		$entity->setDisplayName($displayName);
		$mapper->update($entity);

		// Core caches display names; this event is what clears that cache,
		// and it is what user_oidc itself dispatches after the same write.
		$this->eventDispatcher->dispatchTyped(new UserChangedEvent($user, 'displayName', $displayName, $old));
	}

	private function isOidcUser(IUser $user): bool {
		return $user->getBackendClassName() === 'user_oidc'
			|| is_a($user->getBackend(), self::OIDC_USER_BACKEND);
	}

	/**
	 * Write the account properties the profile has values for, leaving
	 * every other property, and every visibility scope, untouched.
	 *
	 * Nextcloud validates some properties on save -- a phone number it
	 * cannot parse for the configured region, a website it does not like --
	 * and rejects the whole account with the property's name as the message.
	 * One bad value must not cost the user the rest, so that property is
	 * dropped and the save retried.
	 *
	 * @param array<string, string> $values
	 */
	private function applyAccountProperties(IUser $user, array $values): void {
		if ($values === []) {
			return;
		}

		$rejected = [];
		for ($attempt = 0; $attempt <= count(self::ACCOUNT_PROPERTIES); $attempt++) {
			$account = $this->accountManager->getAccount($user);
			$changed = false;
			foreach ($values as $property => $value) {
				if (isset($rejected[$property])) {
					continue;
				}
				try {
					$current = $account->getProperty($property);
				} catch (\Throwable) {
					continue; // Property unknown to this Nextcloud version.
				}
				if ($current->getValue() !== $value) {
					$current->setValue($value);
					$changed = true;
				}
			}
			if (!$changed) {
				return;
			}

			try {
				$this->accountManager->updateAccount($account);
				return;
			} catch (\InvalidArgumentException $e) {
				$property = $e->getMessage();
				if (!isset($values[$property]) || isset($rejected[$property])) {
					throw $e;
				}
				$rejected[$property] = true;
				$this->logger->info('Nextcloud rejected a profile value from the eVault; skipping it', [
					'uid' => $user->getUID(),
					'property' => $property,
				]);
			}
		}
	}

	/**
	 * @param array<string, mixed> $parsed
	 */
	private static function hasAvatarSource(array $parsed): bool {
		foreach (['avatarUrl', 'avatar', 'picture', 'image', 'photo'] as $field) {
			if (self::text($parsed[$field] ?? null) !== null) {
				return true;
			}
		}

		return false;
	}

	private static function email(mixed $value): ?string {
		$email = self::text($value);

		return $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
	}

	private static function text(mixed $value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$value = trim($value);

		return $value !== '' && mb_strlen($value) <= self::MAX_FIELD_LENGTH ? $value : null;
	}

	private function logFieldFailure(string $uid, string $field, \Throwable $e): void {
		$this->logger->info('Could not apply a profile field from the eVault', [
			'uid' => $uid,
			'field' => $field,
			'exception' => $e->getMessage(),
		]);
	}
}
