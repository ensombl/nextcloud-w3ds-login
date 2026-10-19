<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\BackgroundJob\AwarenessBackfillJob;
use OCA\W3dsLogin\Db\W3dsMapping;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\ChatHistoryService;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCA\W3dsLogin\Service\ChatSyncStatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Fetching the conversations, and the messages in them, somebody already had.
 *
 * Messages are not stored with whoever reads them: a group keeps them in its
 * own eVault, and otherwise each sender keeps theirs. So the history is read
 * from every eVault involved, not only the person's own.
 */
class AwarenessBackfillJobTest extends TestCase {
	private const W3ID = '@e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10';
	private const UID = 'alice';

	/** @var array<string, string> */
	private array $appConfig = [];

	/** @var list<array{kind: string, ontologies: array, from: ?string, evault: ?string}> */
	private array $walks = [];

	/** @var list<string> */
	private array $messageVaults = [];

	private function config(): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->appConfig[$key] ?? $default,
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->appConfig[$key] = $value;
			},
		);

		return $config;
	}

	private function client(bool $configured = true): AwarenessClient {
		$client = $this->createMock(AwarenessClient::class);
		$client->method('isConfigured')->willReturn($configured);

		return $client;
	}

	/**
	 * @param array{applied: int, complete: bool} $result What every read returns.
	 */
	private function job(array $result = ['applied' => 1, 'complete' => true], bool $configured = true, array $roomVaults = ['@group', '@bob']): AwarenessBackfillJob {
		$history = $this->createMock(ChatHistoryService::class);
		$history->method('walk')->willReturnCallback(function (array $ontologies, ?string $from, ?string $evault) use ($result): array {
			$this->walks[] = ['kind' => 'walk', 'ontologies' => $ontologies, 'from' => $from, 'evault' => $evault];
			return $result;
		});
		$history->method('vaultsForUser')->with(self::UID)->willReturn($roomVaults);
		$history->method('fetchFromVaults')->willReturnCallback(function (array $vaults, array $ontologies, ?string $from) use ($result): array {
			$this->messageVaults = $vaults;
			$this->walks[] = ['kind' => 'vaults', 'ontologies' => $ontologies, 'from' => $from, 'evault' => null];
			return $result;
		});

		$mapping = new W3dsMapping();
		$mapping->setW3id(self::W3ID);
		$mapping->setNcUid(self::UID);
		$mappings = $this->createMock(W3dsMappingMapper::class);
		$mappings->method('findByW3id')->willReturn($mapping);

		$config = $this->config();
		$client = $this->client($configured);

		return new AwarenessBackfillJob(
			$this->createMock(ITimeFactory::class),
			$client,
			$history,
			$mappings,
			$config,
			new NullLogger(),
			$this->statusService($config, $client),
		);
	}

	private function statusService(IConfig $config, AwarenessClient $client): ChatSyncStatusService {
		return new ChatSyncStatusService($config, $this->createMock(IJobList::class), $client);
	}

	private function backfill(AwarenessBackfillJob $job, mixed $argument = ['w3id' => self::W3ID]): void {
		$method = new \ReflectionMethod(AwarenessBackfillJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($job, $argument);
	}

	private function syncStatus(): array {
		return $this->statusService($this->config(), $this->client())->get(self::W3ID);
	}

	/**
	 * A message whose conversation has no local room is discarded rather than
	 * queued, so conversations are read before any message.
	 */
	public function testConversationsAreReadBeforeMessages(): void {
		$this->backfill($this->job());

		$this->assertSame([ChatSyncService::CHAT_SCHEMA_ID], $this->walks[0]['ontologies']);
		$this->assertSame([ChatSyncService::CHAT_SCHEMA_ID], $this->walks[1]['ontologies']);
		$this->assertSame('vaults', $this->walks[2]['kind']);
		$this->assertContains(ChatSyncService::MESSAGE_SCHEMA_ID, $this->walks[2]['ontologies']);
		$this->assertContains(AwarenessClient::FILE_ONTOLOGY, $this->walks[2]['ontologies']);
	}

	/**
	 * The person's own conversations however old; a conversation started
	 * before the window would otherwise lose every message sent in it since.
	 */
	public function testOwnConversationsAreReadWithoutAWindow(): void {
		$this->backfill($this->job());

		$this->assertSame(self::W3ID, $this->walks[0]['evault']);
		$this->assertNull($this->walks[0]['from']);
	}

	/**
	 * A group keeps its conversation in the group's eVault, not its members',
	 * so recent conversations are read from everywhere and the processor
	 * keeps the ones that include somebody here.
	 */
	public function testGroupConversationsAreFoundBeyondTheOwnEVault(): void {
		$this->backfill($this->job());

		$this->assertNull($this->walks[1]['evault']);
		$this->assertNotNull($this->walks[1]['from']);
	}

	/**
	 * The point of the change: messages from every conversation's own eVault
	 * and every participant's, plus the person's own.
	 */
	public function testMessagesAreReadFromEveryEVaultInvolved(): void {
		$this->backfill($this->job(roomVaults: ['@group', '@bob']));

		$this->assertSame([self::W3ID, '@group', '@bob'], $this->messageVaults);
	}

	public function testMessagesComeFromABoundedWindow(): void {
		$this->backfill($this->job());

		$from = strtotime((string)$this->walks[2]['from']);
		$this->assertGreaterThan(time() - (120 * 86400), $from);
		$this->assertLessThan(time(), $from);
	}

	/**
	 * A relink, or a callback the wallet retried, must not replay history.
	 */
	public function testHistoryIsOnlyFetchedOncePerIdentity(): void {
		$job = $this->job();
		$this->backfill($job);
		$first = count($this->walks);
		$this->backfill($job);

		$this->assertSame($first, count($this->walks));
	}

	/**
	 * Unless asked for: from settings, or by Talk on a first visit.
	 */
	public function testAFetchThatWasAskedForRunsAgain(): void {
		$job = $this->job();
		$this->backfill($job);
		$first = count($this->walks);
		$this->backfill($job, ['w3id' => self::W3ID, 'force' => true]);

		$this->assertGreaterThan($first, count($this->walks));
	}

	public function testReportsTheOutcome(): void {
		$this->backfill($this->job(), ['w3id' => self::W3ID, 'force' => true]);

		$this->assertSame(ChatSyncStatusService::STATE_DONE, $this->syncStatus()['state']);
		$this->assertTrue($this->syncStatus()['complete']);
	}

	public function testReportsAnIncompleteFetch(): void {
		$this->backfill($this->job(['applied' => 3, 'complete' => false]), ['w3id' => self::W3ID, 'force' => true]);

		$this->assertSame(ChatSyncStatusService::STATE_DONE, $this->syncStatus()['state']);
		$this->assertFalse($this->syncStatus()['complete']);
	}

	/**
	 * Nothing readable at all is the service, which only an administrator
	 * can fix, not a history with nothing in it.
	 */
	public function testReportsAnUnreachableService(): void {
		$this->backfill($this->job(['applied' => 0, 'complete' => false]), ['w3id' => self::W3ID, 'force' => true]);

		$this->assertSame(ChatSyncStatusService::STATE_FAILED, $this->syncStatus()['state']);
	}

	/**
	 * Without credentials there is nothing to read, and the marker must not be
	 * set: configuring the service later should still fetch.
	 */
	public function testNothingIsAttemptedOrRecordedWhenUnconfigured(): void {
		$this->backfill($this->job(configured: false));

		$this->assertSame([], $this->walks);
		$this->assertSame([], $this->appConfig);
	}

	public function testReportsAnUnconfiguredServiceToWhoeverAsked(): void {
		$this->backfill($this->job(configured: false), ['w3id' => self::W3ID, 'force' => true]);

		$this->assertSame(ChatSyncStatusService::STATE_FAILED, $this->syncStatus()['state']);
	}

	public function testAMissingIdentityIsIgnored(): void {
		$this->backfill($this->job(), ['w3id' => '']);
		$this->backfill($this->job(), null);

		$this->assertSame([], $this->walks);
	}
}
