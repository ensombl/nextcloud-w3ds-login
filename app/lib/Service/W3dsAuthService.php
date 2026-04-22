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

/**
 * W3DS signature verification.
 *
 * Port of the canonical TypeScript validator at
 * `metastate/infrastructure/signature-validator/src/index.ts`.
 * Anything in the verification path below should stay behaviour-compatible
 * with that module; if the TS source changes, mirror the change here.
 */
class W3dsAuthService {
	private const SESSION_TTL = 0; // no expiry — QR flow completes in seconds, consumption deletes the entry
	private const SESSION_PREFIX = 'w3ds_session_';
	private const LOGIN_TOKEN_PREFIX = 'w3ds_login_';
	private const LOGIN_TOKEN_TTL = 0;

	/** JWT algorithms accepted for key binding certificates. jose defaults to
	 *  the alg(s) advertised by the JWKS; we accept ES256 explicitly. */
	private const JWT_ALLOWED_ALGS = ['ES256'];

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
	 * @return array<string, mixed>|null
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
	// W3DS signature verification — mirrors verifySignature() in the
	// canonical TS validator.
	// ---------------------------------------------------------------

	public function verifySignature(string $w3id, string $sessionId, string $signature): bool {
		if ($w3id === '' || $sessionId === '' || $signature === '') {
			return false;
		}

		$registryBaseUrl = $this->getRegistryBaseUrl();
		$client = $this->clientService->newClient();

		try {
			$certificates = $this->getKeyBindingCertificates($client, $registryBaseUrl, $w3id);

			// Canonical behaviour: if the eVault exposes no key-binding
			// certificates, the signature is treated as valid. This matches
			// `signature-validator/src/index.ts` and must not change without
			// coordinating with that source of truth.
			if (count($certificates) === 0) {
				$this->logger->warning('W3DS: no key binding certificates returned, accepting per canonical behaviour', [
					'w3id' => $w3id,
				]);
				return true;
			}

			$registryJwks = $this->fetchRegistryJwks($client, $registryBaseUrl);
			if (count($registryJwks) === 0) {
				$this->logger->error('W3DS: failed to fetch Registry JWKS');
				return false;
			}

			// Decode and normalise the signature once for every candidate.
			$signatureBytes = $this->decodeSignature($signature);
			if ($signatureBytes === null) {
				$this->logger->warning('W3DS: could not decode signature');
				return false;
			}
			$derSignature = $this->signatureToDer($signatureBytes);
			if ($derSignature === null) {
				$this->logger->warning('W3DS: signature has unrecognised length', [
					'length' => strlen($signatureBytes),
				]);
				return false;
			}

			$lastError = null;

			foreach ($certificates as $jwt) {
				if (!is_string($jwt)) {
					$lastError = 'non-string key binding certificate';
					continue;
				}

				try {
					$payload = $this->verifyJwt($jwt, $registryJwks);
					if ($payload === null) {
						$lastError = 'JWT verification failed';
						continue;
					}

					if (($payload['ename'] ?? null) !== $w3id) {
						$lastError = sprintf(
							'JWT ename mismatch: expected %s, got %s',
							$w3id,
							(string)($payload['ename'] ?? 'null'),
						);
						continue;
					}

					$publicKeyMultibase = $payload['publicKey'] ?? null;
					if (!is_string($publicKeyMultibase) || $publicKeyMultibase === '') {
						$lastError = 'JWT payload missing publicKey';
						continue;
					}

					$publicKeyBytes = $this->decodeMultibasePublicKey($publicKeyMultibase);
					if ($publicKeyBytes === null) {
						$lastError = 'could not decode multibase publicKey';
						continue;
					}

					$pem = $this->publicKeyToPem($publicKeyBytes);
					if ($pem === null) {
						$lastError = 'could not import publicKey';
						continue;
					}

					$ok = openssl_verify($sessionId, $derSignature, $pem, OPENSSL_ALGO_SHA256);
					if ($ok === 1) {
						return true;
					}
					$lastError = 'signature verification failed';
				} catch (\Throwable $e) {
					$lastError = $e->getMessage();
				}
			}

			$this->logger->warning('W3DS: no certificate yielded a valid signature', [
				'w3id' => $w3id,
				'lastError' => $lastError,
			]);
			return false;
		} catch (\Throwable $e) {
			$this->logger->error('W3DS: signature verification error: ' . $e->getMessage(), [
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
	 * Resolve eName → eVault URL via `/resolve`, then fetch
	 * `/whois` and return the `keyBindingCertificates` array (or []).
	 *
	 * @return list<string>
	 */
	private function getKeyBindingCertificates(mixed $client, string $registryBaseUrl, string $w3id): array {
		$resolveUrl = $registryBaseUrl . '/resolve?' . http_build_query(['w3id' => $w3id]);
		$resolveResponse = $client->get($resolveUrl, ['timeout' => 10]);
		$resolveBody = json_decode($resolveResponse->getBody(), true);

		$evaultUrl = $resolveBody['uri'] ?? null;
		if (!is_string($evaultUrl) || $evaultUrl === '') {
			throw new \RuntimeException('Failed to resolve eVault URL for eName: ' . $w3id);
		}

		$whoisUrl = rtrim($evaultUrl, '/') . '/whois';
		$whoisResponse = $client->get($whoisUrl, [
			'timeout' => 10,
			'headers' => ['X-ENAME' => $w3id],
		]);
		$whoisBody = json_decode($whoisResponse->getBody(), true);

		$certs = $whoisBody['keyBindingCertificates'] ?? null;
		if (!is_array($certs)) {
			return [];
		}

		$out = [];
		foreach ($certs as $c) {
			if (is_string($c)) {
				$out[] = $c;
			}
		}
		return $out;
	}

	private function fetchRegistryJwks(mixed $client, string $registryBaseUrl): array {
		$url = $registryBaseUrl . '/.well-known/jwks.json';
		$response = $client->get($url, ['timeout' => 10]);
		$body = json_decode($response->getBody(), true);
		$keys = $body['keys'] ?? [];
		return is_array($keys) ? $keys : [];
	}

	// ---------------------------------------------------------------
	// JWT verification — analogue of jose.jwtVerify(jwt, JWKS)
	// ---------------------------------------------------------------

	/**
	 * Verify a JWT key binding certificate against Registry JWKS and return
	 * its decoded payload on success.
	 *
	 * @return array<string, mixed>|null
	 */
	private function verifyJwt(string $jwt, array $registryKeys): ?array {
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

		$alg = $header['alg'] ?? null;
		if (!is_string($alg) || !in_array($alg, self::JWT_ALLOWED_ALGS, true)) {
			return null;
		}

		$now = time();
		if (isset($payload['exp']) && is_numeric($payload['exp']) && (int)$payload['exp'] < $now) {
			return null;
		}
		if (isset($payload['nbf']) && is_numeric($payload['nbf']) && (int)$payload['nbf'] > $now) {
			return null;
		}

		// Narrow to keys whose kid matches the header, falling back to all
		// keys — this mirrors jose's behaviour when the header has no kid.
		$kid = $header['kid'] ?? null;
		$candidates = [];
		foreach ($registryKeys as $key) {
			if (!is_array($key)) {
				continue;
			}
			if ($kid === null || ($key['kid'] ?? null) === $kid) {
				$candidates[] = $key;
			}
		}
		if (count($candidates) === 0) {
			$candidates = array_values(array_filter($registryKeys, 'is_array'));
		}

		$signedData = $parts[0] . '.' . $parts[1];
		$derSig = $this->rawToDer($jwtSignature); // ES256 JWT signatures are raw r||s

		foreach ($candidates as $jwk) {
			$pem = $this->jwkToPem($jwk);
			if ($pem === null) {
				continue;
			}
			$ok = openssl_verify($signedData, $derSig, $pem, OPENSSL_ALGO_SHA256);
			if ($ok === 1) {
				return $payload;
			}
		}

		return null;
	}

	// ---------------------------------------------------------------
	// Public key handling — mirrors decodeMultibasePublicKey()
	// and the raw-vs-SPKI import choice in the TS verifier.
	// ---------------------------------------------------------------

	/**
	 * Decode a multibase-encoded public key string. Accepts:
	 *   - "0x..." / "0X..."            → plain hex
	 *   - "z" + hex                    → hex (SoftwareKeyManager format)
	 *   - "z" + base58btc              → standard multibase
	 */
	private function decodeMultibasePublicKey(string $multibaseKey): ?string {
		if ($multibaseKey === '') {
			return null;
		}

		if (str_starts_with($multibaseKey, '0x') || str_starts_with($multibaseKey, '0X')) {
			$hex = substr($multibaseKey, 2);
			if (!ctype_xdigit($hex)) {
				return null;
			}
			$decoded = @hex2bin($hex);
			return $decoded === false ? null : $decoded;
		}

		if (!str_starts_with($multibaseKey, 'z')) {
			return null;
		}

		$encoded = substr($multibaseKey, 1);

		// Try hex first (as used by SoftwareKeyManager: 'z' + hex).
		// The TS validator does the same — decoding hex-as-base58 silently
		// succeeds with garbage bytes, so hex-first is required.
		if (ctype_xdigit($encoded) && strlen($encoded) % 2 === 0) {
			$decoded = @hex2bin($encoded);
			if ($decoded !== false) {
				return $decoded;
			}
		}

		return $this->base58btcDecode($encoded);
	}

	/**
	 * Wrap a raw uncompressed EC public key (65 bytes starting 0x04) or an
	 * SPKI DER blob in a PEM envelope and validate it with OpenSSL.
	 */
	private function publicKeyToPem(string $keyBytes): ?string {
		if ($this->looksLikeRawUncompressedEcKey($keyBytes)) {
			$spkiHeader = hex2bin(
				// SEQUENCE { SEQUENCE { id-ecPublicKey, prime256v1 }, BIT STRING }
				'3059301306072a8648ce3d020106082a8648ce3d030107034200'
			);
			$der = $spkiHeader . $keyBytes;
		} elseif ($this->looksLikeDerSpki($keyBytes)) {
			$der = $keyBytes;
		} else {
			return null;
		}

		$pem = "-----BEGIN PUBLIC KEY-----\n"
			 . chunk_split(base64_encode($der), 64, "\n")
			 . "-----END PUBLIC KEY-----\n";

		if (openssl_pkey_get_public($pem) === false) {
			return null;
		}
		return $pem;
	}

	/**
	 * Convert a JWK (ECDSA P-256) to PEM format.
	 *
	 * @param array<string, mixed> $jwk
	 */
	private function jwkToPem(array $jwk): ?string {
		if (($jwk['kty'] ?? '') !== 'EC' || ($jwk['crv'] ?? '') !== 'P-256') {
			return null;
		}

		$x = $this->base64urlDecode((string)($jwk['x'] ?? ''));
		$y = $this->base64urlDecode((string)($jwk['y'] ?? ''));

		if ($x === false || $y === false || strlen($x) !== 32 || strlen($y) !== 32) {
			return null;
		}

		return $this->publicKeyToPem("\x04" . $x . $y);
	}

	// ---------------------------------------------------------------
	// Signature decoding — mirrors decodeSignature() in the TS verifier.
	// ---------------------------------------------------------------

	/**
	 * Try base64/base64url first, then base58btc (only if the leading char
	 * is 'z' and the remainder is valid base58). Prefer whichever decode
	 * yields DER-looking bytes; otherwise return the first successful
	 * decode. Final fallback: raw base64 without validation.
	 */
	private function decodeSignature(string $signature): ?string {
		if ($signature === '') {
			return null;
		}

		$base64urlPattern = '/^[A-Za-z0-9_\-]+=*$/';
		$base58Alphabet = '/^[123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz]+$/';

		$base64Bytes = null;
		if (preg_match($base64urlPattern, $signature)) {
			$base64Bytes = $this->tryDecodeBase64Like($signature);
		}

		$base58Bytes = null;
		if (str_starts_with($signature, 'z')) {
			$rest = substr($signature, 1);
			if ($rest !== '' && preg_match($base58Alphabet, $rest)) {
				$base58Bytes = $this->base58btcDecode($rest);
			}
		}

		// Prefer DER-looking output if we have one.
		if ($base58Bytes !== null && $this->looksLikeDerEcdsaSignature($base58Bytes)) {
			return $base58Bytes;
		}
		if ($base64Bytes !== null && $this->looksLikeDerEcdsaSignature($base64Bytes)) {
			return $base64Bytes;
		}

		if ($base64Bytes !== null) {
			return $base64Bytes;
		}
		if ($base58Bytes !== null) {
			return $base58Bytes;
		}

		// Final fallback: plain base64 (no character restriction).
		$decoded = base64_decode($signature, false);
		return $decoded === false ? null : $decoded;
	}

	private function tryDecodeBase64Like(string $input): ?string {
		$padded = strlen($input) % 4 === 0
			? $input
			: $input . str_repeat('=', 4 - (strlen($input) % 4));
		$normalised = strtr($padded, '-_', '+/');
		$decoded = base64_decode($normalised, true);
		return $decoded === false ? null : $decoded;
	}

	/**
	 * Normalise a decoded signature to DER for openssl_verify.
	 *   - DER in → DER out
	 *   - Raw 64-byte r||s in → DER out
	 *   - Anything else → null (caller should fail verification)
	 */
	private function signatureToDer(string $signatureBytes): ?string {
		if ($this->looksLikeDerEcdsaSignature($signatureBytes)) {
			return $signatureBytes;
		}
		if (strlen($signatureBytes) === 64) {
			return $this->rawToDer($signatureBytes);
		}
		return null;
	}

	// ---------------------------------------------------------------
	// Byte-shape classifiers — direct PHP equivalents of the TS helpers.
	// ---------------------------------------------------------------

	private function looksLikeRawUncompressedEcKey(string $bytes): bool {
		return strlen($bytes) === 65 && ord($bytes[0]) === 0x04;
	}

	private function looksLikeDerSpki(string $bytes): bool {
		$len = strlen($bytes);
		if ($len <= 2) {
			return false;
		}
		if (ord($bytes[0]) !== 0x30) {
			return false;
		}
		$lenByte = ord($bytes[1]);
		return $lenByte >= 0x20 && $lenByte <= 0x82;
	}

	private function looksLikeDerEcdsaSignature(string $bytes): bool {
		$len = strlen($bytes);
		if ($len < 8) {
			return false;
		}
		if (ord($bytes[0]) !== 0x30) {
			return false;
		}
		$totalLen = ord($bytes[1]);
		if ($totalLen !== $len - 2) {
			return false;
		}
		if (ord($bytes[2]) !== 0x02) {
			return false;
		}
		$rLen = ord($bytes[3]);
		if (4 + $rLen >= $len) {
			return false;
		}
		if (ord($bytes[4 + $rLen]) !== 0x02) {
			return false;
		}
		$sLen = ord($bytes[5 + $rLen]);
		return 6 + $rLen + $sLen === $len;
	}

	// ---------------------------------------------------------------
	// DER ↔ raw signature conversion
	// ---------------------------------------------------------------

	/**
	 * Convert a raw ECDSA signature (r || s, 64 bytes) to DER format
	 * as expected by openssl_verify. If the input is not exactly 64 bytes,
	 * assume it is already DER and return it unchanged.
	 */
	private function rawToDer(string $raw): string {
		if (strlen($raw) !== 64) {
			return $raw;
		}

		$r = substr($raw, 0, 32);
		$s = substr($raw, 32, 32);

		$r = ltrim($r, "\x00");
		if ($r === '') {
			$r = "\x00";
		} elseif (ord($r[0]) & 0x80) {
			$r = "\x00" . $r;
		}
		$s = ltrim($s, "\x00");
		if ($s === '') {
			$s = "\x00";
		} elseif (ord($s[0]) & 0x80) {
			$s = "\x00" . $s;
		}

		$rDer = "\x02" . chr(strlen($r)) . $r;
		$sDer = "\x02" . chr(strlen($s)) . $s;
		$seq = $rDer . $sDer;

		return "\x30" . chr(strlen($seq)) . $seq;
	}

	// ---------------------------------------------------------------
	// Low-level encoding helpers
	// ---------------------------------------------------------------

	private function base64urlDecode(string $input): string|false {
		$remainder = strlen($input) % 4;
		if ($remainder > 0) {
			$input .= str_repeat('=', 4 - $remainder);
		}
		return base64_decode(strtr($input, '-_', '+/'), true);
	}

	/**
	 * Decode a base58btc string (Bitcoin alphabet, no multibase prefix).
	 */
	private function base58btcDecode(string $input): ?string {
		if ($input === '') {
			return null;
		}

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
