<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCP\Files\NotPermittedException;
use OCP\Http\Client\IClientService;
use OCP\IAvatarManager;
use Psr\Log\LoggerInterface;

/**
 * Copies a user's avatar from their eVault User profile onto their Nextcloud
 * account.
 *
 * One-way (eVault → Nextcloud) by design for this first pass. The eVault is
 * treated as authoritative for identity, matching how displayName and email
 * are already hydrated during provisioning; pushing Nextcloud-side changes
 * back is a separate decision and a separate round trip.
 *
 * The `User` schema is not consistent about how the picture is carried --
 * different platforms populate different fields, and the value may be a URL
 * or an inline data URI -- so both shapes are accepted.
 *
 * Nothing here is allowed to be fatal. A missing, oversized, unreachable or
 * malformed avatar leaves the user with Nextcloud's generated initials
 * avatar, which is exactly the status quo.
 */
class AvatarSyncService {
	/**
	 * Profile fields that may carry a picture, in order of preference.
	 * `avatarUrl`/`avatar` are what the issue anticipates; the others are
	 * the conventional spellings used across the wider ecosystem.
	 */
	private const AVATAR_FIELDS = ['avatarUrl', 'avatar', 'picture', 'image', 'photo'];

	/** Refuse anything larger than this. Avatars are small; this is a guard
	 * against a hostile or misconfigured host streaming us something huge. */
	private const MAX_BYTES = 5 * 1024 * 1024;

	private const REQUEST_TIMEOUT = 10;

	/**
	 * Nextcloud's avatar storage accepts PNG and JPEG only -- `IAvatar::set()`
	 * throws "Unknown filetype" for GIF and WebP (verified against Nextcloud
	 * 33). Accepting them here would mean fetching bytes we can never store,
	 * so the allowlist matches what Nextcloud will actually take.
	 *
	 * SVG is deliberately excluded regardless: it is script-bearing markup,
	 * and storing a remote one as a user avatar is a stored-XSS vector.
	 */
	private const ALLOWED_MIME = [
		'image/png',
		'image/jpeg',
	];

	public function __construct(
		private IAvatarManager $avatarManager,
		private IClientService $clientService,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Set the user's Nextcloud avatar from their parsed profile envelope.
	 *
	 * @param array<string, mixed> $parsedProfile
	 * @return bool True when an avatar was actually written.
	 */
	public function syncFromProfile(string $uid, array $parsedProfile): bool {
		try {
			$source = $this->pickAvatarSource($parsedProfile);
			if ($source === null) {
				return false;
			}

			$bytes = $this->resolveBytes($source);
			if ($bytes === null) {
				return false;
			}

			return $this->writeAvatar($uid, $bytes);
		} catch (\Throwable $e) {
			// Never let an avatar problem escalate: the caller is a login or
			// provisioning path.
			$this->logger->info('[W3DS Avatar] Avatar sync failed, keeping default', [
				'uid' => $uid,
				'exception' => $e->getMessage(),
			]);
			return false;
		}
	}

	/**
	 * First non-empty string among the known avatar fields.
	 *
	 * @param array<string, mixed> $parsedProfile
	 */
	private function pickAvatarSource(array $parsedProfile): ?string {
		foreach (self::AVATAR_FIELDS as $field) {
			$value = $parsedProfile[$field] ?? null;
			if (is_string($value) && trim($value) !== '') {
				return trim($value);
			}
		}
		return null;
	}

	/**
	 * Turn an avatar reference into raw image bytes. Accepts an inline
	 * `data:` URI (no network) or an http(s) URL (one fetch).
	 */
	private function resolveBytes(string $source): ?string {
		if (str_starts_with($source, 'data:')) {
			return $this->decodeDataUri($source);
		}

		if (str_starts_with($source, 'http://') || str_starts_with($source, 'https://')) {
			return $this->fetchUrl($source);
		}

		$this->logger->info('[W3DS Avatar] Unsupported avatar reference, ignoring', [
			'prefix' => substr($source, 0, 16),
		]);
		return null;
	}

	/**
	 * Decode `data:image/png;base64,...`. Only base64 image payloads are
	 * accepted; anything else is treated as malformed.
	 */
	private function decodeDataUri(string $uri): ?string {
		if (!preg_match('#^data:([^;,]+)(;base64)?,(.*)$#s', $uri, $m)) {
			return null;
		}

		$mime = strtolower(trim($m[1]));
		if (!in_array($mime, self::ALLOWED_MIME, true)) {
			$this->logger->info('[W3DS Avatar] Rejecting data URI with unexpected type', ['mime' => $mime]);
			return null;
		}

		$payload = $m[3];
		$bytes = ($m[2] ?? '') !== ''
			? base64_decode($payload, true)
			: rawurldecode($payload);

		if (!is_string($bytes) || $bytes === '') {
			return null;
		}

		if (strlen($bytes) > self::MAX_BYTES) {
			$this->logger->info('[W3DS Avatar] Inline avatar exceeds size limit, ignoring', [
				'bytes' => strlen($bytes),
			]);
			return null;
		}

		return $bytes;
	}

	/**
	 * Fetch an avatar over HTTP. Uses Nextcloud's HTTP client so the admin's
	 * proxy and SSRF settings apply -- an eVault host may legitimately be an
	 * IP address, which is governed by `allow_local_remote_servers`.
	 */
	private function fetchUrl(string $url): ?string {
		$response = $this->clientService->newClient()->get($url, [
			'timeout' => self::REQUEST_TIMEOUT,
		]);

		$contentType = $response->getHeader('Content-Type');
		if ($contentType !== '') {
			$mime = strtolower(trim(explode(';', $contentType)[0]));
			if (!in_array($mime, self::ALLOWED_MIME, true)) {
				$this->logger->info('[W3DS Avatar] Remote avatar is not a supported image, ignoring', [
					'contentType' => $contentType,
				]);
				return null;
			}
		}

		$body = (string)$response->getBody();
		if ($body === '') {
			return null;
		}

		if (strlen($body) > self::MAX_BYTES) {
			$this->logger->info('[W3DS Avatar] Remote avatar exceeds size limit, ignoring', [
				'bytes' => strlen($body),
			]);
			return null;
		}

		return $body;
	}

	/**
	 * Hand the bytes to Nextcloud. `IAvatar::set()` validates and crops the
	 * image itself, and throws for anything it can't decode -- a bare
	 * `Exception` with "Unknown filetype" rather than a typed one -- so this
	 * catches broadly and reports failure instead of propagating.
	 */
	private function writeAvatar(string $uid, string $bytes): bool {
		try {
			$this->avatarManager->getAvatar($uid)->set($bytes);
			$this->logger->info('[W3DS Avatar] Set avatar from eVault profile', [
				'uid' => $uid,
				'bytes' => strlen($bytes),
			]);
			return true;
		} catch (NotPermittedException $e) {
			// Avatar changes disabled server-wide.
			$this->logger->info('[W3DS Avatar] Nextcloud refused the avatar', [
				'uid' => $uid,
				'reason' => $e->getMessage(),
			]);
			return false;
		} catch (\Throwable $e) {
			// Undecodable or corrupt image data.
			$this->logger->info('[W3DS Avatar] Nextcloud could not store the avatar', [
				'uid' => $uid,
				'reason' => $e->getMessage(),
			]);
			return false;
		}
	}
}
