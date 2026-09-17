<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\BackgroundJob\AwarenessSyncJob;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\AwarenessPacketProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Following the awareness stream without losing or repeating messages.
 *
 * The job's whole purpose is to be the reliable half of delivery: webhooks are
 * faster but can be missed, and this catches up. Anything it skips is a
 * message that silently never appears in a conversation, so the position it
 * resumes from is the part worth testing.
 */
class AwarenessSyncJobTest extends TestCase {
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

	private function job(AwarenessClient $client, ?AwarenessPacketProcessor $processor = null): AwarenessSyncJob {
		$processor ??= $this->createMock(AwarenessPacketProcessor::class);

		return new AwarenessSyncJob(
			$this->createMock(ITimeFactory::class),
			$client,
			$processor,
			$this->config(),
			$this->createMock(IURLGenerator::class),
			new NullLogger(),
		);
	}

	private function tick(AwarenessSyncJob $job): void {
		$method = new \ReflectionMethod(AwarenessSyncJob::class, 'run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	/**
	 * A client that records how it was asked for each page.
	 *
	 * @param list<array{cursor: ?string, from: ?string}> $calls
	 * @param list<array{packets: list<array<string, mixed>>, nextCursor: ?string, hasMore: bool}|null> $pages
	 */
	private function client(array &$calls, array $pages): AwarenessClient {
		$client = $this->createMock(AwarenessClient::class);
		$client->method('isConfigured')->willReturn(true);

		$client->method('fetchPackets')->willReturnCallback(
			static function (?string $cursor, array $ontologies = [], ?string $from = null) use (&$calls, &$pages): ?array {
				$calls[] = ['cursor' => $cursor, 'from' => $from];

				return array_shift($pages) ?? ['packets' => [], 'nextCursor' => null, 'hasMore' => false];
			},
		);

		return $client;
	}

	private function page(array $packets, ?string $nextCursor, bool $hasMore = false): array {
		return ['packets' => $packets, 'nextCursor' => $nextCursor, 'hasMore' => $hasMore];
	}

	/**
	 * A fresh install must not replay the history of every platform in the
	 * ecosystem into people's conversations, so the first poll is anchored to
	 * the present rather than the beginning of the stream.
	 */
	public function testTheFirstPollStartsFromNowRatherThanTheBeginning(): void {
		$calls = [];
		$this->tick($this->job($this->client($calls, [$this->page([], null)])));

		$this->assertNotNull($calls[0]['from'], 'the first poll must be bounded');
		$this->assertNull($calls[0]['cursor']);
	}

	/**
	 * The regression this exists for. The service only returns a cursor
	 * alongside results, so a quiet minute leaves us with none. If the start
	 * time were re-derived as "now" on the next run, the window would move
	 * forward and every packet that arrived in between would never be
	 * requested -- a message lost with nothing in the logs to show for it.
	 */
	public function testAQuietPollDoesNotMoveTheStartingPointForward(): void {
		$calls = [];
		$job = $this->job($this->client($calls, [
			$this->page([], null),
			$this->page([], null),
		]));

		$this->tick($job);
		$this->tick($job);

		$this->assertSame(
			$calls[0]['from'],
			$calls[1]['from'],
			'a run that found nothing must resume from the same point, not from now',
		);
	}

	/**
	 * Once the service has issued a cursor it is the authoritative position,
	 * and it has to survive the run that received it.
	 */
	public function testTheCursorIsRememberedAndUsedOnTheNextRun(): void {
		$calls = [];
		$job = $this->job($this->client($calls, [
			$this->page([['eventId' => 'e1']], 'cursor-after-first-page'),
			$this->page([], null),
		]));

		$this->tick($job);
		$this->tick($job);

		$this->assertSame('cursor-after-first-page', $calls[1]['cursor']);
		$this->assertNull($calls[1]['from'], 'a cursor supersedes the starting timestamp');
	}

	/**
	 * A failed read is not an empty one. Advancing past an error would skip
	 * whatever that page contained, permanently.
	 */
	public function testAFailedReadLeavesThePositionUntouched(): void {
		$calls = [];
		$job = $this->job($this->client($calls, [
			$this->page([['eventId' => 'e1']], 'cursor-one', true),
			null,
			$this->page([], null),
		]));

		$this->tick($job);
		$this->tick($job);

		$this->assertSame('cursor-one', $calls[2]['cursor'], 'the position must not advance past a failure');
	}

	/**
	 * Catching up after downtime means many pages, but a single cron tick
	 * should not turn into an unbounded walk of a shared history holding
	 * hundreds of thousands of packets. Stopping early is free because the
	 * position is saved.
	 */
	public function testOneRunConsumesABoundedNumberOfPages(): void {
		$calls = [];
		$pages = array_fill(0, 50, $this->page([['eventId' => 'e']], 'more', true));

		$this->tick($this->job($this->client($calls, $pages)));

		$this->assertLessThanOrEqual(10, count($calls));
		$this->assertGreaterThan(1, count($calls), 'catching up should read more than one page');
	}

	/**
	 * Without credentials the job does nothing rather than issuing
	 * unauthenticated requests once a minute forever.
	 */
	public function testNothingIsReadWhenTheServiceIsNotConfigured(): void {
		$client = $this->createMock(AwarenessClient::class);
		$client->method('isConfigured')->willReturn(false);
		$client->expects($this->never())->method('fetchPackets');

		$this->tick($this->job($client));
	}

	/**
	 * Every packet in a page has to reach the processor; one skipped here is
	 * one message missing from a conversation.
	 */
	public function testEveryPacketInAPageIsProcessed(): void {
		$calls = [];
		$processor = $this->createMock(AwarenessPacketProcessor::class);
		$processor->expects($this->exactly(3))->method('process')->willReturn(true);

		$this->tick($this->job(
			$this->client($calls, [
				$this->page([['eventId' => 'e1'], ['eventId' => 'e2'], ['eventId' => 'e3']], null),
			]),
			$processor,
		));
	}

	/**
	 * Credentials are set with `occ`, which runs no registration step, so the
	 * job is what actually registers the webhook subscription. Without it we
	 * fall back to the service's catch-all, which carries no shared secret and
	 * therefore delivers packets we cannot authenticate.
	 */
	public function testTheWebhookSubscriptionIsRegisteredWhenASecretIsSet(): void {
		$this->appConfig['awareness_webhook_secret'] = 'shared-secret';

		$calls = [];
		$client = $this->client($calls, [$this->page([], null)]);
		$client->expects($this->once())
			->method('ensureSubscription')
			->with($this->anything(), $this->anything(), 'shared-secret')
			->willReturn(true);

		$this->tick($this->job($client));
	}

	/**
	 * Re-asserting it on every tick would mean two extra HTTP calls a minute
	 * for something that changes almost never.
	 */
	public function testTheSubscriptionIsNotReRegisteredOnEveryRun(): void {
		$this->appConfig['awareness_webhook_secret'] = 'shared-secret';

		$calls = [];
		$client = $this->client($calls, [$this->page([], null), $this->page([], null)]);
		$client->expects($this->once())->method('ensureSubscription')->willReturn(true);

		$job = $this->job($client);
		$this->tick($job);
		$this->tick($job);
	}

	/**
	 * A failed registration must be retried rather than suppressed for the
	 * whole check interval, or a service that was briefly unreachable leaves
	 * the instance unsubscribed for an hour.
	 */
	public function testAFailedRegistrationIsRetriedOnTheNextRun(): void {
		$this->appConfig['awareness_webhook_secret'] = 'shared-secret';

		$calls = [];
		$client = $this->client($calls, [$this->page([], null), $this->page([], null)]);
		$client->expects($this->exactly(2))
			->method('ensureSubscription')
			->willReturnOnConsecutiveCalls(false, true);

		$job = $this->job($client);
		$this->tick($job);
		$this->tick($job);
	}

	/**
	 * A subscription with no secret accepts deliveries that cannot be
	 * authenticated. Polling already covers an instance nothing is pushed to,
	 * so the safe default is to stay unsubscribed.
	 */
	public function testNoSubscriptionIsRegisteredWithoutASecret(): void {
		$calls = [];
		$client = $this->client($calls, [$this->page([], null)]);
		$client->expects($this->never())->method('ensureSubscription');

		$this->tick($this->job($client));
	}
}
