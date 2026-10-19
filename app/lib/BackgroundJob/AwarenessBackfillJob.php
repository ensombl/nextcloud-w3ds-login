<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\BackgroundJob;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\AwarenessPacketProcessor;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCA\W3dsLogin\Service\ChatSyncStatusService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Fetches the conversations somebody already had, when they link their eVault.
 *
 * Ordinary sync resumes from a cursor the whole instance shares, set when the
 * awareness service was first configured. Somebody who links their eVault
 * afterwards inherits that cursor, so everything sent to them before it is
 * never requested: they arrive to empty conversations, or to no conversations
 * at all, while the messages sit in the service's history unread.
 *
 * This walks that history once, for one person, and hands each packet to the
 * same processor the webhook and the poll use. That matters more than it
 * looks: inbound ingest already suppresses the echo that would push a received
 * message straight back out, attributes attachments to whoever sent them, and
 * applies each event exactly once. A backfill with its own ingest path would
 * have to re-earn all three, and the one previous attempt at replaying history
 * on login is what turned a single echo bug into every attachment a user had
 * ever received being re-sent to everyone they had ever spoken to.
 *
 * Chats are read before messages because a message whose conversation is not
 * yet known is discarded rather than queued.
 */
class AwarenessBackfillJob extends QueuedJob {
	/**
	 * How far back a person's messages are fetched.
	 *
	 * The read is restricted to their own eVault, so its cost grows with
	 * their history rather than the network's. Their conversations
	 * themselves are read without a window: a message is only placed in a
	 * conversation that exists here, and a conversation started before the
	 * window would otherwise drop every message sent in it since.
	 */
	private const WINDOW_DAYS = 90;

	/**
	 * Pages read per ontology, at 500 packets each.
	 *
	 * A ceiling rather than an expectation -- one person's eVault rarely
	 * fills more than a handful -- so a runaway history cannot hold the queue
	 * indefinitely. Reaching it is reported as an incomplete sync.
	 */
	private const MAX_PAGES = 200;

	/** False when a page could not be read and the walk stopped short. */
	private bool $complete = true;

	public function __construct(
		ITimeFactory $time,
		private AwarenessClient $awarenessClient,
		private AwarenessPacketProcessor $processor,
		private IConfig $config,
		private LoggerInterface $logger,
		private ChatSyncStatusService $status,
	) {
		parent::__construct($time);
	}

	protected function run(mixed $argument): void {
		$w3id = is_array($argument) ? (string)($argument['w3id'] ?? '') : '';
		if ($w3id === '') {
			return;
		}

		// Asked for by the user, from the sync button: run even if this
		// identity was backfilled before, which is the whole point of asking.
		$forced = is_array($argument) && ($argument['force'] ?? false) === true;

		if (!$this->awarenessClient->isConfigured()) {
			$this->logger->info('[W3DS Awareness] Skipping backfill, no awareness service configured', [
				'w3id' => $w3id,
			]);
			if ($forced) {
				$this->status->markFailed($w3id, 'The awareness service is not configured on this server.');
			}

			return;
		}

		// Linking is not rare enough to replay a month of history on every
		// attempt: a user may link, unlink and link again, and the same job can
		// be queued twice by a retried callback.
		$marker = 'awareness_backfilled_' . md5($w3id);
		if (!$forced && $this->config->getAppValue(Application::APP_ID, $marker, '') !== '') {
			return;
		}
		$this->config->setAppValue(Application::APP_ID, $marker, (string)time());
		$this->status->markRunning($w3id);

		$from = gmdate('Y-m-d\TH:i:s\Z', time() - (self::WINDOW_DAYS * 86400));

		// Conversations first. A message whose chat has no local room is
		// dropped, not deferred, so reading them in the other order would
		// discard most of what this job exists to fetch.
		$this->complete = true;
		try {
			$chats = $this->replay([ChatSyncService::CHAT_SCHEMA_ID], null, $w3id);
			$messages = $this->replay(
				[ChatSyncService::MESSAGE_SCHEMA_ID, AwarenessClient::FILE_ONTOLOGY],
				$from,
				$w3id,
			);
		} catch (\Throwable $e) {
			$this->status->markFailed($w3id, 'Fetching conversations failed. Try again later.');
			throw $e;
		}
		if (!$this->complete && $chats === 0 && $messages === 0) {
			// Nothing at all could be read: the service is unreachable or
			// refused the key, which only an administrator can fix.
			$this->status->markFailed($w3id, 'Could not reach the awareness service. Ask an administrator to check it under Administration settings → W3DS.');
		} else {
			$this->status->markDone($w3id, $chats, $messages, $this->complete);
		}

		$this->logger->info('[W3DS Awareness] Backfilled history for a newly linked user', [
			'w3id' => $w3id,
			'chatsApplied' => $chats,
			'messagesApplied' => $messages,
			'since' => $from,
		]);
	}

	/**
	 * Walk one slice of one person's history, applying whatever belongs here.
	 *
	 * Restricted to their eVault, which holds a copy of every conversation
	 * and message they are part of. Reading the whole network instead -- the
	 * service's only other option -- meant walking everyone's traffic oldest
	 * first and stopping at a page limit, which on a busy network ran out
	 * before reaching the recent messages people actually miss.
	 *
	 * @param string[] $ontologies
	 * @return int Packets that resulted in local work.
	 */
	private function replay(array $ontologies, ?string $from, string $w3id): int {
		$cursor = null;
		$applied = 0;

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$result = $this->awarenessClient->fetchPackets($cursor, $ontologies, $from, $w3id);

			// A failed read ends the backfill rather than skipping past it.
			// Continuing would leave a hole in the middle of someone's history
			// with nothing to signal it; stopping leaves the live poll to
			// carry on from the present, which is the normal state anyway.
			if ($result === null) {
				$this->logger->warning('[W3DS Awareness] Backfill stopped early: could not read history');
				$this->complete = false;

				return $applied;
			}

			foreach ($result['packets'] as $packet) {
				// Re-apply rather than skip. Most of this history has already
				// been seen by the live poll, and a conversation ingested
				// before this person had an account here was created without
				// them; only handing the chat back to the handler adds them.
				if ($this->processor->process($packet, reapply: true)) {
					$applied++;
				}
			}

			$cursor = $result['nextCursor'];
			if (!$result['hasMore'] || $cursor === null) {
				return $applied;
			}
		}

		// Out of pages with more still to read.
		$this->complete = false;
		$this->logger->warning('[W3DS Awareness] Backfill stopped at its page limit', ['w3id' => $w3id]);

		return $applied;
	}
}
