<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\IdMappingMapper;
use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Telling a genuinely repeated message apart from a copy of one message.
 *
 * A message envelope lives in exactly one participant's eVault; every other
 * participant's vault holds a `reference` pointer to it. Polling each
 * participant therefore surfaces the same message more than once, under a
 * different envelope ID each time, and posting on every sighting is what made
 * inbound messages appear twice.
 *
 * The envelope's inner `id` would settle it exactly, but no peer mapping in
 * the reference adapter emits one, so the content-based path is the live one.
 * That path must not collapse a real repeat: sending "ok" twice is ordinary.
 * Occurrence numbering is what keeps both properties at once -- the Nth "ok"
 * from one vault corresponds to the Nth "ok" from another.
 */
class ChatSyncServiceRepeatedMessageTest extends TestCase {
	private const SENDER = 'alice';
	private const CHAT = 'chat-global-id';

	/**
	 * An in-memory stand-in for the mapping table, enforcing the same two
	 * unique indexes: (entity_type, local_id) and (entity_type, global_id).
	 * The real numbering scheme depends on those constraints holding.
	 */
	private function mapper(): IdMappingMapper {
		$rows = [];

		$mapper = $this->createMock(IdMappingMapper::class);

		$mapper->method('storeMapping')->willReturnCallback(
			function (string $type, string $localId, string $globalId) use (&$rows) {
				foreach ($rows as $row) {
					if ($row['type'] === $type && ($row['local'] === $localId || $row['global'] === $globalId)) {
						throw new \RuntimeException('duplicate key');
					}
				}
				$rows[] = ['type' => $type, 'local' => $localId, 'global' => $globalId];

				return new \OCA\W3dsLogin\Db\IdMapping();
			},
		);

		$mapper->method('getGlobalId')->willReturnCallback(
			function (string $type, string $localId) use (&$rows): ?string {
				foreach ($rows as $row) {
					if ($row['type'] === $type && $row['local'] === $localId) {
						return $row['global'];
					}
				}

				return null;
			},
		);

		$mapper->method('countByGlobalIdPrefix')->willReturnCallback(
			function (string $type, string $prefix) use (&$rows): int {
				$n = 0;
				foreach ($rows as $row) {
					if ($row['type'] === $type && str_starts_with($row['global'], $prefix)) {
						$n++;
					}
				}

				return $n;
			},
		);

		return $mapper;
	}

	private function signature(
		IdMappingMapper $mapper,
		array $data,
		string $content,
		string $ownerW3id = '',
		string $globalId = '',
		string $sender = self::SENDER,
	): string {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();
		$prop = new \ReflectionProperty(ChatSyncService::class, 'idMappingMapper');
		$prop->setAccessible(true);
		$prop->setValue($service, $mapper);

		$method = new \ReflectionMethod(ChatSyncService::class, 'messageIdentitySignature');
		$method->setAccessible(true);

		return (string)$method->invoke($service, $sender, self::CHAT, $data, $content, $ownerW3id, $globalId);
	}

	// -------- the case that matters --------

	/**
	 * The question that prompted this: a user really sends "ok" twice. Two
	 * distinct envelopes in the same vault must stay two messages, even with
	 * identical text and an identical timestamp -- two quick messages can
	 * share a whole-second stamp, so the timestamp cannot be the discriminator.
	 */
	public function testTwoGenuineRepeatsFromOneVaultStayDistinct(): void {
		$mapper = $this->mapper();
		$data = ['createdAt' => '2026-05-01T10:00:00+00:00'];

		$first = $this->signature($mapper, $data, 'ok', '@alice', 'env-1');
		$second = $this->signature($mapper, $data, 'ok', '@alice', 'env-2');

		$this->assertNotSame($first, $second);
	}

	/**
	 * The same two messages seen through a second participant's vault must
	 * line up with the first two, first-to-first and second-to-second, so
	 * neither is posted again.
	 */
	public function testCopiesFromAnotherVaultAlignWithTheOriginals(): void {
		$mapper = $this->mapper();
		$data = ['createdAt' => '2026-05-01T10:00:00+00:00'];

		$aliceFirst = $this->signature($mapper, $data, 'ok', '@alice', 'env-1');
		$aliceSecond = $this->signature($mapper, $data, 'ok', '@alice', 'env-2');

		// Bob's vault holds its own envelopes for the same two messages, and
		// its platform re-stamped createdAt on replication.
		$replica = ['createdAt' => '2026-05-01T10:00:04+00:00'];
		$bobFirst = $this->signature($mapper, $replica, 'ok', '@bob', 'env-b1');
		$bobSecond = $this->signature($mapper, $replica, 'ok', '@bob', 'env-b2');

		$this->assertSame($aliceFirst, $bobFirst);
		$this->assertSame($aliceSecond, $bobSecond);
	}

	/**
	 * Re-polling the same vault must reuse the ordinal already assigned,
	 * rather than allocating a new one each time -- otherwise the signature
	 * drifts on every poll and the message is posted again.
	 */
	public function testRepolledEnvelopesKeepTheirOrdinal(): void {
		$mapper = $this->mapper();
		$data = ['createdAt' => '2026-05-01T10:00:00+00:00'];

		$first = $this->signature($mapper, $data, 'ok', '@alice', 'env-1');

		$this->assertSame($first, $this->signature($mapper, $data, 'ok', '@alice', 'env-1'));
		$this->assertSame($first, $this->signature($mapper, $data, 'ok', '@alice', 'env-1'));
	}

	/**
	 * Numbering is per (text, vault), so an unrelated message claiming
	 * occurrence 0 must not push a different message's numbering along.
	 */
	public function testNumberingIsScopedToTheExactText(): void {
		$mapper = $this->mapper();
		$data = ['createdAt' => '2026-05-01T10:00:00+00:00'];

		$ok = $this->signature($mapper, $data, 'ok', '@alice', 'env-1');
		$yes = $this->signature($mapper, $data, 'yes', '@alice', 'env-2');
		$okAgainFromBob = $this->signature($mapper, $data, 'ok', '@bob', 'env-b1');

		$this->assertNotSame($ok, $yes);
		// Bob's first "ok" is still the first "ok", despite "yes" having been
		// numbered in between.
		$this->assertSame($ok, $okAgainFromBob);
	}

	public function testDifferentSendersNeverShareAnIdentity(): void {
		$mapper = $this->mapper();
		$data = ['createdAt' => '2026-05-01T10:00:00+00:00'];

		$this->assertNotSame(
			$this->signature($mapper, $data, 'ok', '@alice', 'env-1', 'alice'),
			$this->signature($mapper, $data, 'ok', '@alice', 'env-2', 'bob'),
		);
	}

	// -------- the exact path, when a peer supplies it --------

	/**
	 * An inner `id` is exact, so it short-circuits the numbering entirely and
	 * needs no database round trip.
	 */
	public function testInnerIdWinsAndIgnoresVaultAndTimestamp(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->expects($this->never())->method('countByGlobalIdPrefix');

		$a = $this->signature($mapper, ['id' => 'msg-1', 'createdAt' => 'x'], 'ok', '@alice', 'env-1');
		$b = $this->signature($mapper, ['id' => 'msg-1', 'createdAt' => 'y'], 'ok', '@bob', 'env-b1');

		$this->assertSame($a, $b);
	}

	public function testDistinctInnerIdsStayDistinct(): void {
		$mapper = $this->createMock(IdMappingMapper::class);

		$this->assertNotSame(
			$this->signature($mapper, ['id' => 'msg-1'], 'ok', '@alice', 'env-1'),
			$this->signature($mapper, ['id' => 'msg-2'], 'ok', '@alice', 'env-2'),
		);
	}
}
