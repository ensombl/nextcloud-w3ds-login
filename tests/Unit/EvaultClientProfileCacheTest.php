<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\EvaultClient;
use OCP\ICache;
use PHPUnit\Framework\TestCase;

/**
 * The profile envelope cache has to stay symmetric.
 *
 * A chat envelope names its participants by User profile envelope ID, so the
 * only way to turn a participant back into a person is the reverse entry
 * (envelope ID -> eName). Any path that writes the forward entry alone leaves
 * that peer unresolvable, and an unresolvable peer is silently dropped from
 * the local room: the chat still syncs, minus a participant.
 */
class EvaultClientProfileCacheTest extends TestCase {
	private const FORWARD_PREFIX = 'w3ds_profile_id_';
	private const REVERSE_PREFIX = 'w3ds_profile_w3id_';

	/** An in-memory stand-in for the distributed cache. */
	private function cache(array &$store): ICache {
		$cache = $this->createMock(ICache::class);
		$cache->method('set')->willReturnCallback(
			static function (string $key, $value) use (&$store): bool {
				$store[$key] = $value;

				return true;
			},
		);
		$cache->method('get')->willReturnCallback(
			static function (string $key) use (&$store) {
				return $store[$key] ?? null;
			},
		);

		return $cache;
	}

	private function client(array &$store): EvaultClient {
		$client = (new \ReflectionClass(EvaultClient::class))->newInstanceWithoutConstructor();

		$cache = new \ReflectionProperty(EvaultClient::class, 'cache');
		$cache->setAccessible(true);
		$cache->setValue($client, $this->cache($store));

		return $client;
	}

	private function primeEntry(EvaultClient $client, string $eName, string $envId): void {
		$m = new \ReflectionMethod(EvaultClient::class, 'primeProfileCacheEntry');
		$m->setAccessible(true);
		$m->invoke($client, $eName, $envId);
	}

	public function testPrimingWritesBothDirections(): void {
		$store = [];
		$client = $this->client($store);

		$this->primeEntry($client, '@alice', 'env-alice');

		$this->assertSame('env-alice', $store[self::FORWARD_PREFIX . '@alice'] ?? null);
		$this->assertSame('@alice', $store[self::REVERSE_PREFIX . 'env-alice'] ?? null);
	}

	public function testReverseLookupResolvesAPrimedEnvelopeId(): void {
		$store = [];
		$client = $this->client($store);

		$this->primeEntry($client, '@alice', 'env-alice');

		$this->assertSame('@alice', $client->resolveW3idFromProfileEnvelopeId('env-alice'));
	}

	public function testReverseLookupReturnsNullForUnknownOrEmptyIds(): void {
		$store = [];
		$client = $this->client($store);

		$this->assertNull($client->resolveW3idFromProfileEnvelopeId('env-nobody'));
		$this->assertNull($client->resolveW3idFromProfileEnvelopeId(''));
	}

	/**
	 * Guards the specific regression: writing only the forward key made
	 * getProfileEnvelopeId() succeed while the reverse lookup returned null,
	 * so a peer named in participantIds could not be resolved.
	 */
	public function testForwardOnlyWriteLeavesTheReverseLookupBlind(): void {
		$store = [self::FORWARD_PREFIX . '@alice' => 'env-alice'];
		$client = $this->client($store);

		$this->assertNull($client->resolveW3idFromProfileEnvelopeId('env-alice'));

		$this->primeEntry($client, '@alice', 'env-alice');

		$this->assertSame('@alice', $client->resolveW3idFromProfileEnvelopeId('env-alice'));
	}
}
