<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\BackgroundJob;

use OCA\W3dsLogin\Service\ChatSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Queued job for async outbound sync to eVault.
 * Dispatched by event listeners to avoid blocking Talk requests.
 */
class OutboundSyncJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private ChatSyncService $chatSyncService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run(mixed $argument): void {
		if (!is_array($argument)) {
			return;
		}

		$type = $argument['type'] ?? '';
		$ncUid = $argument['ncUid'] ?? '';

		if (empty($type) || empty($ncUid)) {
			return;
		}

		$this->logger->info('[W3DS Sync] OutboundSyncJob running', [
			'type' => $type,
			'ncUid' => $ncUid,
			'localId' => $argument['localId'] ?? '',
		]);

		try {
			if ($type === 'chat') {
				$this->chatSyncService->pushChat($ncUid, [
					'token' => $argument['localId'] ?? '',
					'type' => $argument['roomType'] ?? 2,
					'name' => $argument['roomName'] ?? '',
					'participants' => $argument['participants'] ?? [],
					'createdAt' => $argument['timestamp'] ?? time(),
				]);
			} elseif ($type === 'message') {
				$this->chatSyncService->pushMessage($ncUid, [
					'id' => $argument['localId'] ?? '',
					'message' => $argument['message'] ?? '',
					'verb' => $argument['verb'] ?? 'comment',
					'timestamp' => $argument['timestamp'] ?? time(),
				], $argument['roomToken'] ?? '');
			}
		} catch (\Throwable $e) {
			$this->logger->error('OutboundSyncJob failed', [
				'type' => $type,
				'ncUid' => $ncUid,
				'exception' => $e,
			]);
		}
	}
}
