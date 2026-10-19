<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

/**
 * Extra HTTP client options for requests to eVaults.
 *
 * The Registry often hands out eVault URLs whose host is a bare IP address.
 * Nextcloud's DNS pinning runs dns_get_record() on that IP, gets nothing back
 * and refuses the request with "No DNS record found for <ip>", so signature
 * verification and sync fail unless the admin disables the SSRF guard for
 * every app via `allow_local_remote_servers`.
 *
 * Instead, for a host that is a publicly routable IP literal we skip pinning
 * for that one request. Skipping it also skips Nextcloud's redirect check, so
 * redirects are turned off. Hostnames and private / reserved IPs get no extra
 * options and stay under Nextcloud's normal rules.
 */
final class OutboundRequestOptions {
	/**
	 * @return array<string, mixed> options to merge into the request options
	 */
	public static function forUrl(string $url): array {
		if (!self::isPublicIpHost($url)) {
			return [];
		}

		return [
			'nextcloud' => ['allow_local_address' => true],
			'allow_redirects' => false,
		];
	}

	public static function isPublicIpHost(string $url): bool {
		$host = parse_url($url, PHP_URL_HOST);
		if (!is_string($host) || $host === '') {
			return false;
		}
		$host = trim($host, '[]');

		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
			$flags |= FILTER_FLAG_GLOBAL_RANGE;
		}

		return filter_var($host, FILTER_VALIDATE_IP, $flags) !== false;
	}
}
