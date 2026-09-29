<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
use OCP\IConfig;

/**
 * What an identity provider says a user's eName is, for users who signed in
 * through OpenID Connect rather than with their wallet.
 *
 * An IdP between the W3DS OIDC connector and Nextcloud replaces `sub` with
 * its own ID, so the eName arrives as the standard `preferred_username`
 * claim. That claim is only as trustworthy as the IdP's username policy: an
 * IdP with local accounts may let anyone pick a username that looks like an
 * eName. So it is kept as a hint that prompts the user to link with their
 * wallet, and never written to the mapping table itself.
 */
class LinkHintService {
	private const HINT_KEY = 'oidc_ename_hint';
	private const DISMISSED_KEY = 'link_prompt_dismissed';

	/** App config key: prompt every unlinked user, not only hinted ones. */
	public const PROMPT_ALL_KEY = 'prompt_unlinked_users';

	/** eNames are UUIDs; anything else is an ordinary username. */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

	public function __construct(
		private IConfig $config,
	) {
	}

	/**
	 * The eName an OIDC token response names, or null.
	 *
	 * The signature is not checked here: the OIDC app has just validated the
	 * token, and the result is only a hint either way.
	 *
	 * @param array<string, mixed> $tokenResponse
	 */
	public static function eNameFromTokenResponse(array $tokenResponse): ?string {
		$idToken = $tokenResponse['id_token'] ?? null;
		if (!is_string($idToken)) {
			return null;
		}
		$parts = explode('.', $idToken);
		if (count($parts) !== 3) {
			return null;
		}
		$payload = strtr($parts[1], '-_', '+/');
		$payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
		$json = base64_decode($payload, true);
		$claims = is_string($json) ? json_decode($json, true) : null;
		if (!is_array($claims)) {
			return null;
		}
		$username = $claims['preferred_username'] ?? null;
		if (!is_string($username)) {
			return null;
		}
		$candidate = strtolower(ltrim(trim($username), '@'));

		return preg_match(self::UUID, $candidate) === 1 ? '@' . $candidate : null;
	}

	/** Compares eNames the way the wallet and the IdP may each spell them. */
	public static function sameEName(string $a, string $b): bool {
		$normalize = static fn (string $v): string => strtolower(ltrim(trim($v), '@'));

		return $normalize($a) === $normalize($b);
	}

	public function remember(string $uid, string $eName): void {
		if ($this->getHint($uid) !== $eName) {
			// A new hint deserves a fresh prompt.
			$this->config->deleteUserValue($uid, Application::APP_ID, self::DISMISSED_KEY);
		}
		$this->config->setUserValue($uid, Application::APP_ID, self::HINT_KEY, $eName);
	}

	public function getHint(string $uid): ?string {
		$hint = $this->config->getUserValue($uid, Application::APP_ID, self::HINT_KEY, '');

		return $hint !== '' ? $hint : null;
	}

	public function clear(string $uid): void {
		$this->config->deleteUserValue($uid, Application::APP_ID, self::HINT_KEY);
		$this->config->deleteUserValue($uid, Application::APP_ID, self::DISMISSED_KEY);
	}

	public function dismiss(string $uid): void {
		$this->config->setUserValue($uid, Application::APP_ID, self::DISMISSED_KEY, '1');
	}

	/** Whether an unlinked user should be asked to connect their wallet. */
	public function shouldPrompt(string $uid): bool {
		if ($this->config->getUserValue($uid, Application::APP_ID, self::DISMISSED_KEY, '') === '1') {
			return false;
		}
		if ($this->getHint($uid) !== null) {
			return true;
		}

		return $this->config->getAppValue(Application::APP_ID, self::PROMPT_ALL_KEY, 'no') === 'yes';
	}
}
