<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\BackgroundJob;

use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Periodic background job that polls each linked user's eVault
 * for Chat and Message MetaEnvelopes to catch anything webhooks missed.
 */
class PullSyncJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private ChatSyncService $chatSyncService,
		private W3dsMappingMapper $w3dsMappingMapper,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		// Run every 15 minutes
		$this->setInterval(15 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		try {
			$this->syncAllUsers();
		} catch (\Throwable $e) {
			$this->logger->error('PullSyncJob failed', ['exception' => $e]);
		}
	}

	private function syncAllUsers(): void {
		// Get all users with linked W3IDs
		$mappings = $this->w3dsMappingMapper->findAll();
		$synced = 0;

		foreach ($mappings as $mapping) {
			$w3id = $mapping->getW3id();

			try {
				$this->chatSyncService->pullSyncForUser($w3id);
				$synced++;
			} catch (\Throwable $e) {
				$this->logger->warning('Pull sync failed for user', [
					'w3id' => $w3id,
					'exception' => $e,
				]);
				// Continue to next user
			}
		}

		if ($synced > 0) {
			$this->logger->info('Pull sync completed', ['usersProcessed' => $synced]);
		}
	}
}
