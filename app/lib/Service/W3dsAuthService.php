<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

class W3dsAuthService {
	private const SESSION_TTL = 300; // 5 minutes
	private const SESSION_PREFIX = 'w3ds_session_';
	private const LOGIN_TOKEN_PREFIX = 'w3ds_login_';
	private const LOGIN_TOKEN_TTL = 60; // 1 minute

	private ICache $cache;

	public function __construct(
		ICacheFactory $cacheFactory,
		private ISecureRandom $secureRandom,
		private IClientService $clientService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
	}

	// ---------------------------------------------------------------
	// Session management
	// ---------------------------------------------------------------

	/**
	 * @return array{sessionId: string, uri: string}
	 */
	public function createSession(string $callbackUrl, string $platform = 'Nextcloud', ?string $ncUid = null): array {
		$sessionId = $this->secureRandom->generate(32, ISecureRandom::CHAR_ALPHANUMERIC);

		$data = [
			'status' => 'pending',
			'created' => time(),
		];
		if ($ncUid !== null) {
			$data['ncUid'] = $ncUid;
		}
		$this->cache->set(self::SESSION_PREFIX . $sessionId, $data, self::SESSION_TTL);

		$uri = 'w3ds://auth?' . http_build_query([
			'redirect' => $callbackUrl,
			'session' => $sessionId,
			'platform' => $platform,
		]);

		return ['sessionId' => $sessionId, 'uri' => $uri];
	}

	/**
	 * @return array{status: string, userId?: string}|null
	 */
	public function getSessionStatus(string $sessionId): ?array {
		return $this->cache->get(self::SESSION_PREFIX . $sessionId);
	}

	public function markSessionFailed(string $sessionId, string $error): void {
		$data = $this->cache->get(self::SESSION_PREFIX . $sessionId);
		if ($data === null) {
			return;
		}
		$data['status'] = 'failed';
		$data['error'] = $error;
		$this->cache->set(self::SESSION_PREFIX . $sessionId, $data, self::SESSION_TTL);
	}

	public function markSessionComplete(string $sessionId, string $userId): void {
		$data = $this->cache->get(self::SESSION_PREFIX . $sessionId);
		if ($data === null) {
			return;
		}
		$data['status'] = 'authenticated';
		$data['userId'] = $userId;
		$this->cache->set(self::SESSION_PREFIX . $sessionId, $data, self::SESSION_TTL);
	}

	public function consumeSession(string $sessionId): ?string {
		$data = $this->cache->get(self::SESSION_PREFIX . $sessionId);
		if ($data === null || $data['status'] !== 'authenticated') {
			return null;
		}
		$this->cache->remove(self::SESSION_PREFIX . $sessionId);
		return $data['userId'] ?? null;
	}

	// ---------------------------------------------------------------
	// One-time login tokens (used by poll -> completeLogin redirect)
	// ---------------------------------------------------------------

	public function createLoginToken(string $userId): string {
		$token = bin2hex(random_bytes(32));
		$this->cache->set(self::LOGIN_TOKEN_PREFIX . $token, $userId, self::LOGIN_TOKEN_TTL);
		return $token;
	}

	public function consumeLoginToken(string $token): ?string {
		$userId = $this->cache->get(self::LOGIN_TOKEN_PREFIX . $token);
		if ($userId === null) {
			return null;
		}
		$this->cache->remove(self::LOGIN_TOKEN_PREFIX . $token);
		return $userId;
	}

	// ---------------------------------------------------------------
	// W3DS signature verification
	// ---------------------------------------------------------------

	/**
	 * Verify a W3DS signature following the protocol spec:
	 *
	 * 1. Resolve eVault URL via Registry
	 * 2. Fetch key binding certificates from eVault /whois
	 * 3. Verify JWT certificates against Registry JWKS
	 * 4. Extract and decode the public key
	 * 5. Verify the ECDSA P-256 signature over the session ID
	 */
	public function verifySignature(string $w3id, string $sessionId, string $signature): bool {
		$registryBaseUrl = $this->getRegistryBaseUrl();
		$client = $this->clientService->newClient();

		try {
			// Step 1: Resolve W3ID to eVault URL
			$evaultUrl = $this->resolveEvault($client, $registryBaseUrl, $w3id);
			if ($evaultUrl === null) {
				$this->logger->error('Failed to resolve eVault for W3ID', ['w3id' => $w3id]);
				return false;
			}

			// Step 2: Fetch key binding certificates from eVault
			$certificates = $this->fetchCertificates($client, $evaultUrl, $w3id);
			if (empty($certificates)) {
				$this->logger->error('No certificates returned from eVault', ['w3id' => $w3id]);
				return false;
			}

			// Step 3: Fetch Registry JWKS for JWT verification
			$registryJwks = $this->fetchRegistryJwks($client, $registryBaseUrl);
			if (empty($registryJwks)) {
				$this->logger->error('Failed to fetch Registry JWKS');
				return false;
			}

			// Step 4: Verify certificates and extract public keys
			foreach ($certificates as $jwt) {
				$publicKeyDer = $this->verifyAndExtractKey($jwt, $registryJwks);
				if ($publicKeyDer === null) {
					continue;
				}

				// Step 5: Verify the signature
				if ($this->verifyEcdsaSignature($publicKeyDer, $sessionId, $signature)) {
					return true;
				}
			}

			$this->logger->warning('No certificate yielded a valid signature', ['w3id' => $w3id]);
			return false;
		} catch (\Throwable $e) {
			$this->logger->error('Signature verification error: ' . $e->getMessage(), [
				'w3id' => $w3id,
				'exception' => $e,
			]);
			return false;
		}
	}

	// ---------------------------------------------------------------
	// Registry / eVault HTTP calls
	// ---------------------------------------------------------------

	private function getRegistryBaseUrl(): string {
		return rtrim(
			$this->config->getAppValue(Application::APP_ID, 'registry_base_url', 'https://registry.w3ds.metastate.foundation'),
			'/',
		);
	}

	/**
	 * GET {registryBaseUrl}/resolve?w3id=@user.w3id
	 * Returns the eVault URL for the given W3ID.
	 */
	private function resolveEvault(mixed $client, string $registryBaseUrl, string $w3id): ?string {
		$url = $registryBaseUrl . '/resolve?' . http_build_query(['w3id' => $w3id]);

		$response = $client->get($url, ['timeout' => 10]);
		$body = json_decode($response->getBody(), true);

		return $body['uri'] ?? $body['evaultUrl'] ?? null;
	}

	/**
	 * GET {evaultUrl}/whois with X-ENAME header.
	 * Returns an array of JWT key binding certificates.
	 */
	private function fetchCertificates(mixed $client, string $evaultUrl, string $w3id): array {
		$url = rtrim($evaultUrl, '/') . '/whois';

		$response = $client->get($url, [
			'timeout' => 10,
			'headers' => ['X-ENAME' => $w3id],
		]);
		$body = json_decode($response->getBody(), true);

		// The response contains an array of JWT strings
		if (is_array($body) && isset($body['keyBindingCertificates'])) {
			return $body['keyBindingCertificates'];
		}

		if (is_array($body) && isset($body['certificates'])) {
			return $body['certificates'];
		}

		// Some implementations return a flat array
		if (is_array($body) && isset($body[0]) && is_string($body[0])) {
			return $body;
		}

		return [];
	}

	/**
	 * GET {registryBaseUrl}/.well-known/jwks.json
	 * Returns the Registry's JWKS keyset for verifying JWT certificates.
	 */
	private function fetchRegistryJwks(mixed $client, string $registryBaseUrl): array {
		$url = $registryBaseUrl . '/.well-known/jwks.json';

		$response = $client->get($url, ['timeout' => 10]);
		$body = json_decode($response->getBody(), true);

		return $body['keys'] ?? [];
	}

	// ---------------------------------------------------------------
	// JWT verification and public key extraction
	// ---------------------------------------------------------------

	/**
	 * Verify a JWT key binding certificate against Registry JWKS
	 * and extract the user's public key from the payload.
	 *
	 * @return string|null DER-encoded public key, or null on failure
	 */
	private function verifyAndExtractKey(string $jwt, array $registryKeys): ?string {
		$parts = explode('.', $jwt);
		if (count($parts) !== 3) {
			return null;
		}

		$headerJson = $this->base64urlDecode($parts[0]);
		$payloadJson = $this->base64urlDecode($parts[1]);
		$jwtSignature = $this->base64urlDecode($parts[2]);

		if ($headerJson === false || $payloadJson === false || $jwtSignature === false) {
			return null;
		}

		$header = json_decode($headerJson, true);
		$payload = json_decode($payloadJson, true);

		if (!is_array($header) || !is_array($payload)) {
			return null;
		}

		// Check expiration
		if (isset($payload['exp']) && $payload['exp'] < time()) {
			return null;
		}

		// Find matching Registry key by kid
		$kid = $header['kid'] ?? null;
		$registryKey = null;
		foreach ($registryKeys as $key) {
			if (($kid !== null && ($key['kid'] ?? null) === $kid) || $kid === null) {
				$registryKey = $key;
				break;
			}
		}

		if ($registryKey === null) {
			return null;
		}

		// Build PEM from JWK for the Registry's key (ECDSA P-256)
		$registryPem = $this->jwkToPem($registryKey);
		if ($registryPem === null) {
			return null;
		}

		// Verify the JWT signature
		$signedData = $parts[0] . '.' . $parts[1];
		$derSig = $this->rawToDer($jwtSignature);

		$valid = openssl_verify($signedData, $derSig, $registryPem, OPENSSL_ALGO_SHA256);
		if ($valid !== 1) {
			return null;
		}

		// Extract user's public key from payload
		$multibaseKey = $payload['publicKey'] ?? null;
		if (!is_string($multibaseKey) || empty($multibaseKey)) {
			return null;
		}

		return $this->decodeMultibaseKey($multibaseKey);
	}

	// ---------------------------------------------------------------
	// ECDSA P-256 signature verification
	// ---------------------------------------------------------------

	/**
	 * Verify an ECDSA P-256 signature over a payload string.
	 *
	 * @param string $publicKeyDer DER (SPKI) or raw uncompressed public key bytes
	 * @param string $payload The session ID string to verify
	 * @param string $signature Base64 or multibase-encoded raw signature (64 bytes: r || s)
	 */
	private function verifyEcdsaSignature(string $publicKeyDer, string $payload, string $signature): bool {
		// Decode the signature from base64 or multibase
		$rawSig = $this->decodeSignature($signature);
		if ($rawSig === null || strlen($rawSig) !== 64) {
			$this->logger->warning('Invalid signature length', [
				'expected' => 64,
				'actual' => $rawSig !== null ? strlen($rawSig) : 'null',
			]);
			return false;
		}

		// Build PEM from the raw/DER public key
		$pem = $this->publicKeyToPem($publicKeyDer);
		if ($pem === null) {
			return false;
		}

		// Convert raw signature (r || s) to DER format for openssl_verify
		$derSig = $this->rawToDer($rawSig);

		// openssl_verify expects the original data, not the hash.
		// OPENSSL_ALGO_SHA256 tells it to hash with SHA-256 before verifying.
		$result = openssl_verify($payload, $derSig, $pem, OPENSSL_ALGO_SHA256);

		return $result === 1;
	}

	// ---------------------------------------------------------------
	// Key format conversions
	// ---------------------------------------------------------------

	/**
	 * Convert a JWK (ECDSA P-256) to PEM format.
	 */
	private function jwkToPem(array $jwk): ?string {
		if (($jwk['kty'] ?? '') !== 'EC' || ($jwk['crv'] ?? '') !== 'P-256') {
			return null;
		}

		$x = $this->base64urlDecode($jwk['x'] ?? '');
		$y = $this->base64urlDecode($jwk['y'] ?? '');

		if ($x === false || $y === false || strlen($x) !== 32 || strlen($y) !== 32) {
			return null;
		}

		// Build uncompressed point: 0x04 || x || y
		$uncompressed = "\x04" . $x . $y;

		return $this->publicKeyToPem($uncompressed);
	}

	/**
	 * Wrap a raw uncompressed EC public key (65 bytes) or SPKI DER in PEM.
	 */
	private function publicKeyToPem(string $keyBytes): ?string {
		// If it's already SPKI DER (starts with 0x30), wrap directly
		if (strlen($keyBytes) > 65 && ord($keyBytes[0]) === 0x30) {
			$der = $keyBytes;
		} elseif (strlen($keyBytes) === 65 && ord($keyBytes[0]) === 0x04) {
			// Raw uncompressed point: wrap in SPKI DER structure
			// SPKI header for EC P-256 (OID 1.2.840.10045.2.1 + 1.2.840.10045.3.1.7)
			$spkiHeader = hex2bin(
				'3059301306072a8648ce3d020106082a8648ce3d030107034200'
			);
			$der = $spkiHeader . $keyBytes;
		} else {
			return null;
		}

		$pem = "-----BEGIN PUBLIC KEY-----\n"
			 . chunk_split(base64_encode($der), 64, "\n")
			 . "-----END PUBLIC KEY-----\n";

		$key = openssl_pkey_get_public($pem);
		if ($key === false) {
			return null;
		}

		return $pem;
	}

	// ---------------------------------------------------------------
	// Encoding helpers
	// ---------------------------------------------------------------

	/**
	 * Decode a multibase-encoded public key.
	 * Prefix 'z' = base58btc, 'm' = base64, 'f' = hex.
	 */
	private function decodeMultibaseKey(string $encoded): ?string {
		if (empty($encoded)) {
			return null;
		}

		$prefix = $encoded[0];
		$data = substr($encoded, 1);

		return match ($prefix) {
			'z' => $this->base58btcDecode($data) ?? (ctype_xdigit($data) ? hex2bin($data) ?: null : null),
			'm' => base64_decode($data, true) ?: null,
			'f' => hex2bin($data) ?: null,
			default => null,
		};
	}

	/**
	 * Decode a signature from base64 or multibase base58btc.
	 */
	private function decodeSignature(string $encoded): ?string {
		if (empty($encoded)) {
			return null;
		}

		// Multibase base58btc prefix
		if ($encoded[0] === 'z') {
			return $this->base58btcDecode(substr($encoded, 1));
		}

		// Standard base64
		$decoded = base64_decode($encoded, true);
		return $decoded !== false ? $decoded : null;
	}

	/**
	 * Convert a raw ECDSA signature (r || s, 64 bytes) to DER format
	 * as expected by openssl_verify.
	 */
	private function rawToDer(string $raw): string {
		if (strlen($raw) !== 64) {
			return $raw; // assume already DER
		}

		$r = substr($raw, 0, 32);
		$s = substr($raw, 32, 32);

		// Trim leading zero bytes, then prepend 0x00 if high bit set
		$r = ltrim($r, "\x00");
		if (ord($r[0]) & 0x80) {
			$r = "\x00" . $r;
		}
		$s = ltrim($s, "\x00");
		if (ord($s[0]) & 0x80) {
			$s = "\x00" . $s;
		}

		$rDer = "\x02" . chr(strlen($r)) . $r;
		$sDer = "\x02" . chr(strlen($s)) . $s;
		$seq = $rDer . $sDer;

		return "\x30" . chr(strlen($seq)) . $seq;
	}

	private function base64urlDecode(string $input): string|false {
		$remainder = strlen($input) % 4;
		if ($remainder > 0) {
			$input .= str_repeat('=', 4 - $remainder);
		}
		return base64_decode(strtr($input, '-_', '+/'), true);
	}

	/**
	 * Decode a base58btc string (Bitcoin alphabet).
	 */
	private function base58btcDecode(string $input): ?string {
		$alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

		$result = gmp_init(0);
		$base = gmp_init(58);

		for ($i = 0; $i < strlen($input); $i++) {
			$pos = strpos($alphabet, $input[$i]);
			if ($pos === false) {
				return null;
			}
			$result = gmp_add(gmp_mul($result, $base), gmp_init($pos));
		}

		$hex = gmp_strval($result, 16);
		if (strlen($hex) % 2 !== 0) {
			$hex = '0' . $hex;
		}

		// Preserve leading zeros
		$leadingZeros = 0;
		for ($i = 0; $i < strlen($input); $i++) {
			if ($input[$i] === '1') {
				$leadingZeros++;
			} else {
				break;
			}
		}

		$decoded = hex2bin($hex);
		if ($decoded === false) {
			return null;
		}

		return str_repeat("\x00", $leadingZeros) . $decoded;
	}
}
