<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\BackgroundJob;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\AwarenessPacketProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads awareness packets from AaaS and applies them to Talk.
 *
 * This replaces polling every participant's eVault. The old arrangement cost
 * one listing per participant per room per poll, and returned the same message
 * once per participant because each vault holds its own replica -- which is
 * why the app grew content signatures and occurrence counters to tell a
 * genuine repeat from a copy. One ordered packet stream removes both problems
 * at the source.
 *
 * Webhook delivery already covers the live case. This job is the backstop that
 * makes delivery reliable rather than best-effort: it catches up after
 * downtime, and it is the whole inbound path on an instance with no publicly
 * reachable URL.
 *
 * The cursor is a single value for the instance, kept in appconfig. Per-user,
 * per-ontology cursor rows existed because reads were per-vault; one stream
 * needs one bookmark.
 */
class AwarenessSyncJob extends TimedJob {
	/** Where the poll resumes from. Opaque to us; only AaaS reads it. */
	private const CURSOR_KEY = 'awareness_cursor';

	/**
	 * Pages consumed per run.
	 *
	 * A cron tick should not turn into an unbounded walk of a shared history
	 * that holds hundreds of thousands of packets. Stopping early is free:
	 * the cursor is saved, so the next tick continues where this one stopped.
	 */
	private const MAX_PAGES_PER_RUN = 10;

	public function __construct(
		ITimeFactory $time,
		private AwarenessClient $awarenessClient,
		private AwarenessPacketProcessor $processor,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);

		// Nextcloud's floor when system cron ticks every minute. Webhooks
		// carry the latency-sensitive path; this only has to be timely enough
		// that a missed delivery is not noticed.
		$this->setInterval(60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run(mixed $argument): void {
		if (!$this->awarenessClient->isConfigured()) {
			return;
		}

		try {
			$this->drain();
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Awareness] Sync job failed', ['exception' => $e]);
		}
	}

	private function drain(): void {
		$cursor = $this->config->getAppValue(Application::APP_ID, self::CURSOR_KEY, '');
		$applied = 0;

		for ($page = 0; $page < self::MAX_PAGES_PER_RUN; $page++) {
			$result = $this->awarenessClient->fetchPackets(
				$cursor !== '' ? $cursor : null,
				$this->processor->ontologies(),
				// Only on the very first run, and only from now: a fresh
				// install must not replay the ecosystem's entire history into
				// people's conversations.
				$cursor === '' ? $this->startingPoint() : null,
			);

			// A failed read is not an empty one. Leaving the cursor where it
			// is costs a retry; advancing past an error loses messages for
			// good.
			if ($result === null) {
				return;
			}

			foreach ($result['packets'] as $packet) {
				if ($this->processor->process($packet)) {
					$applied++;
				}
			}

			if ($result['nextCursor'] !== null) {
				$cursor = $result['nextCursor'];
				$this->config->setAppValue(Application::APP_ID, self::CURSOR_KEY, $cursor);
			}

			if (!$result['hasMore'] || $result['nextCursor'] === null) {
				break;
			}
		}

		if ($applied > 0) {
			$this->logger->info('[W3DS Awareness] Applied inbound packets', ['applied' => $applied]);
		}
	}

	/**
	 * Where a first-time poll starts.
	 *
	 * Deliberately "now", not "the beginning". Backfilling history is a
	 * separate, explicit decision: replaying it implicitly on install is what
	 * turned an echo bug into every attachment a user had ever received being
	 * re-sent to everyone they had ever talked to.
	 */
	private function startingPoint(): string {
		return gmdate('Y-m-d\TH:i:s\Z');
	}
}
