<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\OutboundRequestOptions;
use PHPUnit\Framework\TestCase;

/**
 * Only public IP literals may bypass Nextcloud's DNS pinning; everything
 * else must stay under the normal SSRF rules.
 */
class OutboundRequestOptionsTest extends TestCase {
	public function testPublicIpv4EvaultBypassesPinningWithoutRedirects(): void {
		$this->assertSame(
			['nextcloud' => ['allow_local_address' => true], 'allow_redirects' => false],
			OutboundRequestOptions::forUrl('http://64.227.64.55:4000/whois'),
		);
	}

	public function testPublicIpv6Literal(): void {
		$this->assertTrue(OutboundRequestOptions::isPublicIpHost('http://[2606:4700:4700::1111]:4000/whois'));
	}

	public function testHostnameKeepsNormalRules(): void {
		$this->assertSame([], OutboundRequestOptions::forUrl('https://registry.w3ds.metastate.foundation/resolve'));
	}

	/**
	 * @dataProvider localUrls
	 */
	public function testLocalAddressesKeepNormalRules(string $url): void {
		$this->assertSame([], OutboundRequestOptions::forUrl($url));
	}

	public static function localUrls(): array {
		return [
			'loopback' => ['http://127.0.0.1:8090/'],
			'private 10/8' => ['http://10.0.0.5/'],
			'private 172.16/12' => ['http://172.18.0.4/'],
			'private 192.168/16' => ['http://192.168.1.1/'],
			'link-local metadata' => ['http://169.254.169.254/latest/meta-data'],
			'unspecified' => ['http://0.0.0.0/'],
			'ipv6 loopback' => ['http://[::1]/'],
			'ipv6 unique local' => ['http://[fd00::1]/'],
			'no host' => ['not a url'],
		];
	}
}
