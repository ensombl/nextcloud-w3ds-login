<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
use OCP\ICacheFactory;
use OCP\ICache;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class W3dsAuthService {
    private const SESSION_TTL = 300; // 5 minutes
    private const SESSION_PREFIX = 'w3ds_session_';

    private ICache $cache;

    public function __construct(
        ICacheFactory $cacheFactory,
        private ISecureRandom $secureRandom,
        private LoggerInterface $logger,
    ) {
        $this->cache = $cacheFactory->createDistributed(Application::APP_ID);
    }

    /**
     * Create a new authentication session with a cryptographically random ID.
     *
     * @return array{sessionId: string, uri: string}
     */
    public function createSession(string $callbackUrl, string $platform = 'Nextcloud'): array {
        $sessionId = $this->secureRandom->generate(
            32,
            ISecureRandom::CHAR_ALPHANUMERIC,
        );

        $this->cache->set(self::SESSION_PREFIX . $sessionId, [
            'status' => 'pending',
            'created' => time(),
        ], self::SESSION_TTL);

        $uri = 'w3ds://auth?' . http_build_query([
            'redirect' => $callbackUrl,
            'session' => $sessionId,
            'platform' => $platform,
        ]);

        return [
            'sessionId' => $sessionId,
            'uri' => $uri,
        ];
    }

    /**
     * Get the current status of an authentication session.
     *
     * @return array{status: string, userId?: string}|null
     */
    public function getSessionStatus(string $sessionId): ?array {
        $data = $this->cache->get(self::SESSION_PREFIX . $sessionId);
        if ($data === null) {
            return null;
        }
        return $data;
    }

    /**
     * Mark a session as successfully authenticated.
     */
    public function markSessionComplete(string $sessionId, string $userId): void {
        $data = $this->cache->get(self::SESSION_PREFIX . $sessionId);
        if ($data === null) {
            return;
        }

        $data['status'] = 'authenticated';
        $data['userId'] = $userId;
        $this->cache->set(self::SESSION_PREFIX . $sessionId, $data, self::SESSION_TTL);
    }

    /**
     * Consume a session after the polling client reads the authenticated status.
     * Returns the user ID if the session was authenticated, null otherwise.
     */
    public function consumeSession(string $sessionId): ?string {
        $data = $this->cache->get(self::SESSION_PREFIX . $sessionId);
        if ($data === null || $data['status'] !== 'authenticated') {
            return null;
        }

        $this->cache->remove(self::SESSION_PREFIX . $sessionId);
        return $data['userId'] ?? null;
    }

    /**
     * Verify a W3DS signature against the user's public key.
     *
     * Resolves the W3ID via the W3DS Registry, retrieves the ECDSA P-256 public key,
     * and verifies the signature over the session ID.
     *
     * @return bool True if the signature is valid
     */
    public function verifySignature(string $w3id, string $sessionId, string $signature): bool {
        // TODO: Implement W3DS Registry lookup and ECDSA P-256 verification.
        //
        // Steps:
        // 1. Resolve the W3ID via the W3DS Registry to get the eVault URL
        // 2. Fetch the user's JWKS (public key) from the eVault
        // 3. Decode the base64 signature (64 bytes: r || s)
        // 4. Verify using openssl_verify() with OPENSSL_ALGO_SHA256
        //
        // For development, accept any signature:
        $this->logger->warning(
            'W3DS signature verification not yet implemented, accepting all signatures',
            ['w3id' => $w3id, 'session' => $sessionId],
        );

        return true;
    }
}
