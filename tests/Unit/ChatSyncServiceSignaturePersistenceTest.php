<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\IdMappingMapper;
use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Durability of the cross-replica dedup record.
 *
 * The same logical message exists in every participant's eVault under a
 * different envelope ID, so the envelope ID cannot identify it and a content
 * signature is used instead. That signature used to live only in the cache
 * from `ICacheFactory::createDistributed()`, which silently degrades to a
 * per-request ArrayCache when neither Redis nor memcached is configured -- the
 * default for a single-server install.
 *
 * On those instances the signature was gone by the next request, so every
 * replica of a message posted again, and again on each poll: messages sent
 * from another platform appeared twice. The record has to be persisted.
 */
class ChatSyncServiceSignaturePersistenceTest extends TestCase {
	private function service(IdMappingMapper $mapper): ChatSyncService {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();

		$prop = new \ReflectionProperty(ChatSyncService::class, 'idMappingMapper');
		$prop->setAccessible(true);
		$prop->setValue($service, $mapper);

		return $service;
	}

	private function remember(IdMappingMapper $mapper, string $signature, string $localId): void {
		$m = new \ReflectionMethod(ChatSyncService::class, 'rememberMessageSignature');
		$m->setAccessible(true);
		$m->invoke($this->service($mapper), $signature, $localId, '@owner');
	}

	private function constant(string $name): string {
		return (string)(new \ReflectionClass(ChatSyncService::class))->getConstant($name);
	}

	/**
	 * The signature is the lookup key, so it must land in the column the
	 * reverse lookup reads (`global_id`), with the Talk comment id as the
	 * local side. Swapping the two silently disables dedup entirely.
	 */
	public function testPersistsTheSignatureAsTheLookupKey(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->expects($this->once())
			->method('storeMapping')
			->with(
				$this->constant('MESSAGE_SIG_ENTITY'),
				'4242',
				'sig-abc',
				'@owner',
			);

		$this->remember($mapper, 'sig-abc', '4242');
	}

	/**
	 * Signatures share a table with real envelope mappings. A separate entity
	 * type is what stops a signature being read back as an envelope ID.
	 */
	public function testSignaturesAreNamespacedAwayFromEnvelopeMappings(): void {
		$this->assertNotSame('message', $this->constant('MESSAGE_SIG_ENTITY'));
		$this->assertNotSame('chat', $this->constant('MESSAGE_SIG_ENTITY'));
	}

	/**
	 * Two participants' replicas can be ingested concurrently, so one insert
	 * loses the unique-index race. That is the intended outcome, not an error
	 * worth failing the whole message on.
	 */
	public function testALostInsertRaceIsNotFatal(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('storeMapping')
			->willThrowException(new \RuntimeException('duplicate key'));

		$this->remember($mapper, 'sig-abc', '4242');

		$this->assertTrue(true, 'a duplicate-key insert must not propagate');
	}
}
