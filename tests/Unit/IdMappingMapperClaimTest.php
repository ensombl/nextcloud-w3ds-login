<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\IdMapping;
use OCA\W3dsLogin\Db\IdMappingMapper;
use PHPUnit\Framework\TestCase;

/**
 * Serialising work that several requests can start at once.
 *
 * The "have we handled this already?" checks are reads, and the work that
 * follows is slow: resolving a sender, downloading an attachment, posting
 * into Talk. Meanwhile pollRoom() is driven by *every open browser tab* every
 * 15s, alongside the cron PullSyncJob and the inbound webhook. Those overlap
 * constantly, so several requests each read "not yet handled" for the same
 * envelope and all of them went on to post it -- which is how one message
 * arrived three or four times rather than merely twice.
 *
 * A cache-based lock cannot fix this: without Redis configured
 * `createDistributed()` is a per-request store and each of these requests is
 * a different process. The mapping table's unique index can, because the
 * database is the one thing they genuinely share.
 */
class IdMappingMapperClaimTest extends TestCase {
	/**
	 * A claim is an insert, so exactly one concurrent caller can win it and
	 * every other must be told it lost rather than proceeding.
	 */
	public function testOnlyOneConcurrentClaimSucceeds(): void {
		$mapper = $this->getMockBuilder(IdMappingMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['storeMapping'])
			->getMock();

		$taken = [];
		$mapper->method('storeMapping')->willReturnCallback(
			function (string $type, string $localId) use (&$taken) {
				if (isset($taken[$type . '|' . $localId])) {
					// What the unique index raises for the losing inserter.
					throw new \RuntimeException('duplicate key');
				}
				$taken[$type . '|' . $localId] = true;

				return new IdMapping();
			},
		);

		$this->assertTrue($mapper->tryClaim('ingest_claim', 'ingest|env-1', '@alice'));

		// Three more pollers arrive while the first is still working.
		$this->assertFalse($mapper->tryClaim('ingest_claim', 'ingest|env-1', '@alice'));
		$this->assertFalse($mapper->tryClaim('ingest_claim', 'ingest|env-1', '@alice'));
		$this->assertFalse($mapper->tryClaim('ingest_claim', 'ingest|env-1', '@alice'));
	}

	/**
	 * Claims are per envelope, so unrelated messages are never serialised
	 * against each other.
	 */
	public function testDifferentEnvelopesClaimIndependently(): void {
		$mapper = $this->getMockBuilder(IdMappingMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['storeMapping'])
			->getMock();

		$taken = [];
		$mapper->method('storeMapping')->willReturnCallback(
			function (string $type, string $localId) use (&$taken) {
				if (isset($taken[$type . '|' . $localId])) {
					throw new \RuntimeException('duplicate key');
				}
				$taken[$type . '|' . $localId] = true;

				return new IdMapping();
			},
		);

		$this->assertTrue($mapper->tryClaim('ingest_claim', 'ingest|env-1', '@alice'));
		$this->assertTrue($mapper->tryClaim('ingest_claim', 'ingest|env-2', '@alice'));
		// Inbound and outbound claims for the same id are separate concerns.
		$this->assertTrue($mapper->tryClaim('ingest_claim', 'push|env-1', '@alice'));
	}

	/**
	 * A claim marks work in progress, not work completed. If an attempt
	 * fails, releasing it has to let the next poll retry; otherwise a single
	 * transient error would strand the message forever.
	 */
	public function testAReleasedClaimCanBeTakenAgain(): void {
		$mapper = $this->getMockBuilder(IdMappingMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['storeMapping', 'releaseClaim'])
			->getMock();

		$taken = [];
		$mapper->method('storeMapping')->willReturnCallback(
			function (string $type, string $localId) use (&$taken) {
				if (isset($taken[$type . '|' . $localId])) {
					throw new \RuntimeException('duplicate key');
				}
				$taken[$type . '|' . $localId] = true;

				return new IdMapping();
			},
		);
		$mapper->method('releaseClaim')->willReturnCallback(
			function (string $type, string $localId) use (&$taken): void {
				unset($taken[$type . '|' . $localId]);
			},
		);

		$this->assertTrue($mapper->tryClaim('ingest_claim', 'ingest|env-1', '@alice'));
		$this->assertFalse($mapper->tryClaim('ingest_claim', 'ingest|env-1', '@alice'));

		$mapper->releaseClaim('ingest_claim', 'ingest|env-1');

		$this->assertTrue($mapper->tryClaim('ingest_claim', 'ingest|env-1', '@alice'));
	}
}
