<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\ChatVaults;
use OCA\W3dsLogin\Db\FetchMarks;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\AwarenessPacketProcessor;
use OCA\W3dsLogin\Service\ChatHistoryService;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Reading conversations' messages from the eVaults that hold them.
 */
class ChatHistoryServiceTest extends TestCase {
	/** @var list<array{cursor: ?string, from: ?string, evault: ?string}> */
	private array $calls = [];

	private ChatVaults $vaults;
	private ICache $cache;
	private FetchMarks $marks;

	protected function setUp(): void {
		$this->vaults = $this->createMock(ChatVaults::class);
		$this->cache = $this->createMock(ICache::class);
		$this->marks = $this->createMock(FetchMarks::class);
		$this->marks->method('from')->willReturnCallback(static fn (string $key, ?int $earliest): ?string => $earliest === null ? null : gmdate('Y-m-d\TH:i:s\Z', $earliest));
	}

	/**
	 * @param list<array|null> $pages Returned in order, then empty pages.
	 */
	private function service(array $pages, ?AwarenessPacketProcessor $processor = null): ChatHistoryService {
		$client = $this->createMock(AwarenessClient::class);
		$client->method('isConfigured')->willReturn(true);
		$client->method('fetchPackets')->willReturnCallback(
			function (?string $cursor, array $ontologies = [], ?string $from = null, ?string $evault = null) use (&$pages): ?array {
				$this->calls[] = ['cursor' => $cursor, 'from' => $from, 'evault' => $evault];
				return $pages === [] ? ['packets' => [], 'nextCursor' => null, 'hasMore' => false] : array_shift($pages);
			},
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->cache);

		// Retries pause for real seconds; not here.
		$service = $this->getMockBuilder(ChatHistoryService::class)
			->setConstructorArgs([$client, $processor ?? $this->createMock(AwarenessPacketProcessor::class), $this->vaults, $this->createMock(ChatSyncService::class), $factory, new NullLogger(), $this->marks])
			->onlyMethods(['pause'])
			->getMock();

		return $service;
	}

	private static function page(int $packets, ?string $next): array {
		return ['packets' => array_fill(0, $packets, ['eventId' => 'e']), 'nextCursor' => $next, 'hasMore' => $next !== null];
	}

	public function testEachEVaultIsReadSeparately(): void {
		$result = $this->service([])->fetchFromVaults(['@group', '@bob'], ['m'], 1700000000, 5);

		$this->assertSame(['@group', '@bob'], array_column($this->calls, 'evault'));
		$this->assertTrue($result['complete']);
	}

	/**
	 * One timeout or rate limit used to end the whole fetch.
	 */
	public function testAFailedPageIsRetried(): void {
		$result = $this->service([null, self::page(1, null)])->walk(['m'], null, '@group', 5);

		$this->assertCount(2, $this->calls);
		$this->assertTrue($result['complete']);
	}

	public function testAPageThatKeepsFailingEndsTheWalkAsIncomplete(): void {
		$result = $this->service([null, null, null, null, null])->walk(['m'], null, '@group', 5);

		$this->assertCount(4, $this->calls, 'one read and three retries');
		$this->assertFalse($result['complete']);
	}

	public function testFollowsTheCursorToTheEnd(): void {
		$processor = $this->createMock(AwarenessPacketProcessor::class);
		$processor->method('process')->willReturn(true);

		$result = $this->service([self::page(2, 'c1'), self::page(2, null)], $processor)->walk(['m'], null, '@group', 5);

		$this->assertSame([null, 'c1'], array_column($this->calls, 'cursor'));
		$this->assertSame(4, $result['applied']);
		$this->assertTrue($result['complete']);
	}

	public function testRunningOutOfPagesIsIncomplete(): void {
		$result = $this->service([self::page(1, 'a'), self::page(1, 'b'), self::page(1, 'c')])->walk(['m'], null, '@group', 2);

		$this->assertFalse($result['complete']);
	}

	/**
	 * Opening a conversation reads its own eVault and every participant's.
	 */
	public function testOpeningARoomReadsEveryEVaultOfIt(): void {
		$this->vaults->method('forRoom')->with('room1')->willReturn(['@group', '@alice', '@bob']);

		$this->service([])->fetchOpenRoom('room1');

		$this->assertSame(['@group', '@alice', '@bob'], array_column($this->calls, 'evault'));
		$this->assertNotNull($this->calls[0]['from'], 'recent messages only; the history fetch reads further back');
	}

	public function testARoomOpenedMomentsAgoIsNotReadAgain(): void {
		$this->cache->method('get')->willReturn(1);
		$this->vaults->expects($this->never())->method('forRoom');

		$this->assertNull($this->service([])->fetchOpenRoom('room1'));
	}

	/**
	 * Talk orders a conversation by posting order, so history from several
	 * eVaults is applied in the order it was sent, not eVault by eVault.
	 */
	public function testHistoryFromSeveralEVaultsIsAppliedOldestFirst(): void {
		$applied = [];
		$processor = $this->createMock(AwarenessPacketProcessor::class);
		$processor->method('process')->willReturnCallback(function (array $packet) use (&$applied): bool {
			$applied[] = $packet['id'];
			return true;
		});
		$at = static fn (string $id, string $when): array => ['id' => $id, 'data' => ['createdAt' => $when]];

		$this->service([
			['packets' => [$at('bob-2', '2026-10-02T10:00:00Z'), $at('bob-1', '2026-09-01T10:00:00Z')], 'nextCursor' => null, 'hasMore' => false],
			['packets' => [$at('group-1', '2026-09-15T10:00:00Z'), $at('bob-2', '2026-10-02T10:00:00Z')], 'nextCursor' => null, 'hasMore' => false],
		], $processor)->fetchFromVaults(['@bob', '@group'], ['m'], 1700000000, 5);

		$this->assertSame(['bob-1', 'group-1', 'bob-2'], $applied, 'sorted by time, each envelope once');
	}

	/**
	 * Each eVault is read only from where its last complete read began.
	 */
	public function testAnEVaultIsReadFromItsMark(): void {
		$this->marks = $this->createMock(FetchMarks::class);
		$this->marks->method('from')->willReturnMap([
			['vault:@group', 1700000000, '2026-10-19T12:00:00Z'],
			['vault:@bob', 1700000000, '2023-11-14T22:13:20Z'],
		]);
		$this->marks->expects($this->exactly(2))->method('set');

		$this->service([])->fetchFromVaults(['@group', '@bob'], ['m'], 1700000000, 5);

		$this->assertSame(['2026-10-19T12:00:00Z', '2023-11-14T22:13:20Z'], array_column($this->calls, 'from'));
	}

	/**
	 * A read that stopped short leaves the mark where it was, so the next
	 * read repeats it rather than skipping what was missed.
	 */
	public function testAnIncompleteReadSetsNoMark(): void {
		$this->marks->expects($this->never())->method('set');

		$this->service([null, null, null, null])->fetchFromVaults(['@group'], ['m'], 1700000000, 5);
	}

	/**
	 * Asked for in full, the whole window is read regardless of marks.
	 */
	public function testAFullReadIgnoresMarks(): void {
		$this->marks->expects($this->never())->method('from');

		$this->service([])->fetchFromVaults(['@group'], ['m'], 1700000000, 5, true);

		$this->assertSame(gmdate('Y-m-d\TH:i:s\Z', 1700000000), $this->calls[0]['from']);
	}
}
