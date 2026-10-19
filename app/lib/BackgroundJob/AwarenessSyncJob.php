<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\BackgroundJob;

use OCA\W3dsLogin\Service\AwarenessPoller;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Polls the awareness service on every cron run.
 *
 * The floor for inbound latency on an instance that runs nothing else: as
 * often as cron runs, at most once a minute. The `w3ds_login:awareness-poll`
 * worker and open Talk pages poll far more often; this keeps messages
 * arriving when neither is there.
 */
class AwarenessSyncJob extends TimedJob {
	/** Kept here as well: the admin settings clear it by this name. */
	public const SUBSCRIPTION_CHECKED_KEY = AwarenessPoller::SUBSCRIPTION_CHECKED_KEY;

	public function __construct(
		ITimeFactory $time,
		private AwarenessPoller $poller,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		$this->setInterval(60);
		// Chat delivery: never deferred to a maintenance window.
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	protected function run(mixed $argument): void {
		try {
			$this->poller->poll();
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Awareness] Sync job failed', ['exception' => $e]);
		}
	}
}
