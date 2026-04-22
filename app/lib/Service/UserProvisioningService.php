<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
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
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Find an existing user by W3ID, or create a new Nextcloud user.
	 */
	public function findOrCreateUser(string $w3id): ?IUser {
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

		// Store the W3ID mapping
		$mapping = new W3dsMapping();
		$mapping->setW3id($w3id);
		$mapping->setNcUid($user->getUID());
		$mapping->setCreatedAt(time());
		$this->mapper->insert($mapping);

		// Best-effort: replace the W3ID-based display name and empty email
		// with whatever the user's eVault profile has.
		$this->hydrateProfileFromEvault($user, $w3id);

		// The random password above is a throwaway -- force the user to set
		// a real one on first login so WebDAV / desktop clients / mobile
		// apps can still authenticate.
		$this->config->setUserValue(
			$user->getUID(),
			Application::APP_ID,
			'must_set_password',
			'1',
		);

		$this->logger->info('Provisioned new user from W3DS', [
			'w3id' => $w3id,
			'uid' => $user->getUID(),
		]);

		return $user;
	}

	/**
	 * Copy the user's displayName and email from their eVault User profile
	 * onto the freshly-created NC account. Never throws: a broken eVault
	 * must not prevent login.
	 */
	private function hydrateProfileFromEvault(IUser $user, string $w3id): void {
		try {
			$profileId = $this->evaultClient->getProfileEnvelopeId($w3id);
			if ($profileId === null) {
				return;
			}

			$envelope = $this->evaultClient->fetchMetaEnvelopeById($w3id, $profileId);
			$parsed = $envelope['parsed'] ?? null;
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
