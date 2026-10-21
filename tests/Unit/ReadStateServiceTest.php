<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\ChatVaults;
use OCA\W3dsLogin\Db\IdMappingMapper;
use OCA\W3dsLogin\Db\TalkHistory;
use OCA\W3dsLogin\Service\ChatHistoryService;
use OCA\W3dsLogin\Service\ReadStateService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Read markers are set to exactly what the envelopes' receipts say: forward
 * or back, and to nothing read for someone no receipt names.
 */
class ReadStateServiceTest extends TestCase {
	/** @var array<string, int> uid => marker set */
	private array $set = [];

	/**
	 * @param array<string, list<array<string, mixed>>> $packetsByVault
	 * @param array<string, bool> $completeByVault
	 */
	private function rebuild(array $packetsByVault, array $completeByVault = []): array {
		$history = $this->createMock(ChatHistoryService::class);
		$history->method('walk')->willReturnCallback(
			function (array $ontologies, ?string $from, ?string $vault, int $maxPages, bool $retry = true, ?callable $collect = null) use ($packetsByVault, $completeByVault): array {
				foreach ($packetsByVault[$vault] ?? [] as $packet) {
					$collect($packet);
				}

				return ['applied' => 0, 'complete' => $completeByVault[$vault] ?? true];
			},
		);

		$vaults = $this->createMock(ChatVaults::class);
		$vaults->method('forRoom')->willReturn(['@alice', '@bob']);

		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('getLocalId')->willReturnCallback(fn (string $type, string $id) => $type === 'chat' && $id === 'chat-1' ? 'room' : null);

		// Querying it needs a database; readMarkerUpTo() is stubbed instead.
		$talkHistory = (new \ReflectionClass(TalkHistory::class))->newInstanceWithoutConstructor();

		// IDBConnection cannot be mocked against the OCP stubs, so the
		// collaborators are set directly; what reads the database is stubbed.
		$service = $this->getMockBuilder(ReadStateService::class)
			->disableOriginalConstructor()
			->onlyMethods(['syncedRooms', 'membersWithEName', 'setReadMarker', 'readMarkerUpTo'])
			->getMock();
		foreach (['history' => $history, 'chatVaults' => $vaults, 'idMappingMapper' => $mapper, 'talkHistory' => $talkHistory, 'logger' => new NullLogger()] as $name => $value) {
			(new \ReflectionProperty(ReadStateService::class, $name))->setValue($service, $value);
		}
		$service->method('syncedRooms')->willReturn(['room']);
		// Notices up to id 9, then messages at 10:00 (id 10), 11:00 (id 11)
		// and 12:00 (id 12).
		$service->method('readMarkerUpTo')->willReturnCallback(
			fn (string $token, ?\DateTimeImmutable $until): ?int => $until === null ? 9 : max(9, min(12, (int)$until->format('G'))),
		);
		$service->method('membersWithEName')->willReturn(['alice' => '@alice', 'bob' => '@bob', 'carol' => '@carol']);
		$this->set = [];
		$service->method('setReadMarker')->willReturnCallback(function (string $token, string $uid, int $id): bool {
			$this->set[$uid] = $id;
			return true;
		});

		return $service->rebuild();
	}

	private function message(string $at, string $sender, array $readBy = [], array $readByAt = []): array {
		return ['data' => [
			'chatId' => 'chat-1',
			'createdAt' => '2026-10-19T' . $at . ':00Z',
			'senderEName' => $sender,
			'readBy' => $readBy,
			'readByAt' => $readByAt,
		]];
	}

	public function testMarkersFollowTheNewestReceiptAcrossEveryEVault(): void {
		$result = $this->rebuild([
			'@alice' => [$this->message('10:00', '@alice'), $this->message('11:00', '@bob', ['@alice'])],
			'@bob' => [$this->message('12:00', '@bob', [], ['@ALICE' => '2026-10-19T12:05:00Z'])],
		]);

		$this->assertSame(['rooms' => 1, 'markers' => 3, 'skipped' => 0], $result);
		// Alice read up to noon (the receipt's eName in another case), Bob
		// sent the noon message, and nothing names Carol: she has read only
		// the notices before the first message.
		$this->assertSame(['alice' => 12, 'bob' => 12, 'carol' => 9], $this->set);
	}

	public function testAReceiptLaterCopiesDropDoesNotUnreadAMessage(): void {
		$this->rebuild([
			'@alice' => [$this->message('11:00', '@bob', ['@alice'])],
			'@bob' => [$this->message('11:00', '@bob')],
		]);

		$this->assertSame(11, $this->set['alice']);
	}

	public function testOtherConversationsAndReferencesAreIgnored(): void {
		$elsewhere = $this->message('12:00', '@alice');
		$elsewhere['data']['chatId'] = 'chat-2';
		$reference = $this->message('12:00', '@alice');
		$reference['data']['isReference'] = true;

		$this->rebuild(['@alice' => [$this->message('10:00', '@bob', ['@alice']), $elsewhere, $reference]]);

		$this->assertSame(10, $this->set['alice']);
	}

	public function testAConversationWithAnUnreadableEVaultIsLeftAlone(): void {
		$result = $this->rebuild(['@alice' => [$this->message('10:00', '@alice')]], ['@bob' => false]);

		$this->assertSame(['rooms' => 0, 'markers' => 0, 'skipped' => 1], $result);
		$this->assertSame([], $this->set);
	}
}
