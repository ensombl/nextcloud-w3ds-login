<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\BackgroundJob\AwarenessBackfillJob;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\AwarenessPacketProcessor;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Fetching the conversations somebody already had, when they link.
 *
 * The live poll resumes from a cursor the whole instance shares, so anyone who
 * links afterwards inherits a position already past their own history. Without
 * this job they arrive to empty conversations while the messages sit unread in
 * the awareness service.
 */
class AwarenessBackfillJobTest extends TestCase {
	private const W3ID = '@newly-linked';

	/** @var array<string, string> */
	private array $appConfig = [];

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

	/**
	 * @param list<array{ontologies: list<string>, from: ?string, cursor: ?string}> $calls
	 */
	private function client(array &$calls, bool $configured = true, bool $failing = false): AwarenessClient {
		$client = $this->createMock(AwarenessClient::class);
		$client->method('isConfigured')->willReturn($configured);

		$client->method('fetchPackets')->willReturnCallback(
			static function (?string $cursor, array $ontologies = [], ?string $from = null) use (&$calls, $failing): ?array {
				$calls[] = ['ontologies' => $ontologies, 'from' => $from, 'cursor' => $cursor];

				if ($failing) {
					return null;
				}

				return ['packets' => [['eventId' => 'e' . count($calls)]], 'nextCursor' => null, 'hasMore' => false];
			},
		);

		return $client;
	}

	private function job(AwarenessClient $client, ?AwarenessPacketProcessor $processor = null): AwarenessBackfillJob {
		return new AwarenessBackfillJob(
			$this->createMock(ITimeFactory::class),
			$client,
			$processor ?? $this->createMock(AwarenessPacketProcessor::class),
			$this->config(),
			new NullLogger(),
		);
	}

	private function backfill(AwarenessBackfillJob $job, mixed $argument = ['w3id' => self::W3ID]): void {
		$method = new \ReflectionMethod(AwarenessBackfillJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($job, $argument);
	}

	/**
	 * A message whose conversation has no local room is discarded rather than
	 * queued, so reading messages before chats would throw away most of what
	 * this job exists to fetch.
	 */
	public function testConversationsAreReadBeforeMessages(): void {
		$calls = [];
		$this->backfill($this->job($this->client($calls)));

		$this->assertContains(ChatSyncService::CHAT_SCHEMA_ID, $calls[0]['ontologies']);
		$this->assertContains(ChatSyncService::MESSAGE_SCHEMA_ID, $calls[1]['ontologies']);
	}

	/**
	 * Attachments announce themselves on their own ontology, and are
	 * materialised through the ordinary inbound path, which already suppresses
	 * the echo that would push them back out.
	 */
	public function testAttachmentsAreIncluded(): void {
		$calls = [];
		$this->backfill($this->job($this->client($calls)));

		$this->assertContains(AwarenessClient::FILE_ONTOLOGY, $calls[1]['ontologies']);
	}

	/**
	 * Bounded on purpose. The service holds the whole ecosystem's history, and
	 * replaying all of it on every login is what once turned one echo bug into
	 * every attachment a user had received being re-sent to everyone.
	 */
	public function testHistoryIsFetchedFromABoundedWindow(): void {
		$calls = [];
		$this->backfill($this->job($this->client($calls)));

		$this->assertNotNull($calls[0]['from'], 'the window must have a start');
		$this->assertGreaterThan(
			time() - (90 * 86400),
			strtotime($calls[0]['from']),
			'the window must not reach back indefinitely',
		);
		$this->assertLessThan(time(), strtotime($calls[0]['from']));
	}

	/**
	 * Every packet goes through the processor the webhook and the poll use, so
	 * the echo guard, the sender attribution and the once-only claim all apply
	 * without being re-implemented here.
	 */
	public function testPacketsAreAppliedThroughTheOrdinaryInboundPath(): void {
		$calls = [];
		$processor = $this->createMock(AwarenessPacketProcessor::class);
		$processor->expects($this->atLeastOnce())->method('process')->willReturn(true);

		$this->backfill($this->job($this->client($calls), $processor));
	}

	/**
	 * A relink, or a callback the wallet retried, must not replay a month of
	 * history again.
	 */
	public function testHistoryIsOnlyFetchedOncePerIdentity(): void {
		$calls = [];
		$client = $this->client($calls);
		$job = $this->job($client);

		$this->backfill($job);
		$firstRun = count($calls);
		$this->backfill($job);

		$this->assertSame($firstRun, count($calls), 'a second attempt must do nothing');
	}

	/**
	 * Two people linking are two separate histories.
	 */
	public function testEachIdentityIsBackfilledSeparately(): void {
		$calls = [];
		$job = $this->job($this->client($calls));

		$this->backfill($job, ['w3id' => '@first']);
		$afterFirst = count($calls);
		$this->backfill($job, ['w3id' => '@second']);

		$this->assertGreaterThan($afterFirst, count($calls));
	}

	/**
	 * Without credentials there is nothing to read, and the marker must not be
	 * set: configuring the service later should still backfill.
	 */
	public function testNothingIsAttemptedOrRecordedWhenUnconfigured(): void {
		$calls = [];
		$this->backfill($this->job($this->client($calls, configured: false)));

		$this->assertSame([], $calls);
		$this->assertSame([], $this->appConfig, 'an unconfigured instance must stay backfillable');
	}

	/**
	 * A read failure ends the backfill rather than skipping past it: carrying
	 * on would leave a hole in the middle of someone's history with nothing to
	 * show for it.
	 */
	public function testAReadFailureStopsTheWalk(): void {
		$calls = [];
		$processor = $this->createMock(AwarenessPacketProcessor::class);
		$processor->expects($this->never())->method('process');

		$this->backfill($this->job($this->client($calls, failing: true), $processor));

		// One attempt per ontology group, then stop.
		$this->assertLessThanOrEqual(2, count($calls));
	}

	/**
	 * Queued jobs carry whatever the caller passed, and a malformed argument
	 * must not take the queue down with it.
	 */
	public function testAMissingIdentityIsIgnored(): void {
		$calls = [];

		$this->backfill($this->job($this->client($calls)), ['w3id' => '']);
		$this->backfill($this->job($this->client($calls)), null);

		$this->assertSame([], $calls);
	}
}
