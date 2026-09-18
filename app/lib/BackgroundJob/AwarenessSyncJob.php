<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\BackgroundJob;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\AwarenessPacketProcessor;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IURLGenerator;
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
	 * The instant this instance started following the stream.
	 *
	 * Held separately from the cursor because the service only issues a cursor
	 * alongside results: a poll that finds nothing gets none. Re-deriving the
	 * start time as "now" on each such run would move the window forward every
	 * minute and skip anything that arrived in between -- which is a silently
	 * dropped message, the failure this job exists to prevent.
	 */
	private const STARTED_AT_KEY = 'awareness_started_at';

	/**
	 * Pages consumed per run.
	 *
	 * A cron tick should not turn into an unbounded walk of a shared history
	 * that holds hundreds of thousands of packets. Stopping early is free:
	 * the cursor is saved, so the next tick continues where this one stopped.
	 */
	private const MAX_PAGES_PER_RUN = 10;

	/**
	 * How often to re-assert our webhook subscription.
	 *
	 * Cheap to confirm and expensive to be wrong about: without it we fall back
	 * to whatever catch-all subscription the service reconciles for registered
	 * platforms, which carries no shared secret, so deliveries cannot be
	 * authenticated.
	 */
	private const SUBSCRIPTION_CHECK_INTERVAL = 3600;

	/** When the subscription was last confirmed, as a Unix timestamp. */
	private const SUBSCRIPTION_CHECKED_KEY = 'awareness_subscription_checked';

	public function __construct(
		ITimeFactory $time,
		private AwarenessClient $awarenessClient,
		private AwarenessPacketProcessor $processor,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
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
			$this->ensureSubscription();
			$this->drain();
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Awareness] Sync job failed', ['exception' => $e]);
		}
	}

	/**
	 * Keep our webhook subscription registered with the service.
	 *
	 * Done here rather than when credentials are saved, because a subscription
	 * can outlive this instance's memory of it: the service may drop it, or the
	 * credentials may have been set by hand with `occ`, which runs no such
	 * registration step.
	 *
	 * Only attempted when a webhook secret is configured. A subscription
	 * without one accepts deliveries that cannot be authenticated, and the
	 * polling below already covers the case where nothing is pushed to us.
	 */
	private function ensureSubscription(): void {
		$secret = $this->config->getAppValue(Application::APP_ID, 'awareness_webhook_secret', '');
		if ($secret === '') {
			return;
		}

		$checkedAt = (int)$this->config->getAppValue(Application::APP_ID, self::SUBSCRIPTION_CHECKED_KEY, '0');
		if ($checkedAt > time() - self::SUBSCRIPTION_CHECK_INTERVAL) {
			return;
		}

		$registered = $this->awarenessClient->ensureSubscription(
			$this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute(Application::APP_ID . '.webhook.receive'),
			),
			$this->processor->ontologies(),
			$secret,
		);

		// Only record success: a failed attempt should be retried on the next
		// tick rather than suppressed for an hour.
		if ($registered) {
			$this->config->setAppValue(Application::APP_ID, self::SUBSCRIPTION_CHECKED_KEY, (string)time());
		}
	}

	private function drain(): void {
		$cursor = $this->config->getAppValue(Application::APP_ID, self::CURSOR_KEY, '');
		$applied = 0;

		for ($page = 0; $page < self::MAX_PAGES_PER_RUN; $page++) {
			$result = $this->awarenessClient->fetchPackets(
				$cursor !== '' ? $cursor : null,
				$this->processor->ontologies(),
				// Until the service has issued a cursor, ask from the moment
				// this instance was first configured -- fixed, so that nothing
				// which arrives between two runs falls through the gap.
				$cursor === '' ? $this->startedAt() : null,
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
	 * When this instance began following the stream, recorded once.
	 *
	 * Deliberately "now", not "the beginning". Backfilling history is a
	 * separate, explicit decision: replaying it implicitly on install is what
	 * turned an echo bug into every attachment a user had ever received being
	 * re-sent to everyone they had ever talked to.
	 */
	private function startedAt(): string {
		$startedAt = $this->config->getAppValue(Application::APP_ID, self::STARTED_AT_KEY, '');
		if ($startedAt === '') {
			$startedAt = gmdate('Y-m-d\TH:i:s\Z');
			$this->config->setAppValue(Application::APP_ID, self::STARTED_AT_KEY, $startedAt);
		}

		return $startedAt;
	}
}
