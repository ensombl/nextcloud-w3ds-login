<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\Db\W3dsMapping;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class UserProvisioningService {
    public function __construct(
        private W3dsMappingMapper $mapper,
        private IUserManager $userManager,
        private ISecureRandom $secureRandom,
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

        $this->logger->info('Provisioned new user from W3DS', [
            'w3id' => $w3id,
            'uid' => $user->getUID(),
        ]);

        return $user;
    }

    /**
     * Link an existing Nextcloud user to a W3ID.
     *
     * @throws \RuntimeException If the W3ID is already linked to another user
     */
    public function linkUser(string $w3id, string $ncUid): void {
        if ($this->mapper->existsByW3id($w3id)) {
            throw new \RuntimeException('This W3DS identity is already linked to another account');
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
