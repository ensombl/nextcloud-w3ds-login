<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\BackgroundJob\AwarenessBackfillJob;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\ChatSyncStatusService;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * The "Sync W3DS chats" button queues the history walk at most once at a
 * time, and refuses plainly when this server cannot sync at all.
 */
class ChatSyncStatusServiceTest extends TestCase {
	private const W3ID = '@e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10';

	/** @var array<string, string> */
	private array $appConfig = [];
	private IJobList $jobs;

	private function service(bool $configured = true): ChatSyncStatusService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->appConfig[$key] ?? $default,
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->appConfig[$key] = $value;
			},
		);
		$client = $this->createMock(AwarenessClient::class);
		$client->method('isConfigured')->willReturn($configured);
		$this->jobs ??= $this->createMock(IJobList::class);

		return new ChatSyncStatusService($config, $this->jobs, $client);
	}

	public function testQueuesAForcedBackfill(): void {
		$this->jobs = $this->createMock(IJobList::class);
		$this->jobs->expects($this->once())->method('add')->with(
			AwarenessBackfillJob::class,
			$this->callback(fn (array $arg) => $arg['w3id'] === self::W3ID && $arg['force'] === true),
		);

		$status = $this->service()->request(self::W3ID);

		$this->assertSame(ChatSyncStatusService::STATE_QUEUED, $status['state']);
	}

	public function testDoesNotQueueASecondSyncWhileOneIsPending(): void {
		$this->jobs = $this->createMock(IJobList::class);
		$this->jobs->expects($this->once())->method('add');

		$service = $this->service();
		$service->request(self::W3ID);
		$service->markRunning(self::W3ID);
		$status = $service->request(self::W3ID);

		$this->assertSame(ChatSyncStatusService::STATE_RUNNING, $status['state']);
	}

	public function testCanRunAgainOnceFinished(): void {
		$this->jobs = $this->createMock(IJobList::class);
		$this->jobs->expects($this->exactly(2))->method('add');

		$service = $this->service();
		$service->request(self::W3ID);
		$service->markDone(self::W3ID, 2, 10, true);
		$service->request(self::W3ID);
	}

	public function testAStuckSyncDoesNotBlockForever(): void {
		$this->jobs = $this->createMock(IJobList::class);
		$this->jobs->expects($this->once())->method('add');
		$this->appConfig['chat_sync_status_' . md5(self::W3ID)] = json_encode(['state' => 'queued', 'requestedAt' => time() - 7200]);

		$this->service()->request(self::W3ID);
	}

	public function testRefusesWhenTheServerIsNotConnected(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/administrator/');

		$this->service(configured: false)->request(self::W3ID);
	}
}
