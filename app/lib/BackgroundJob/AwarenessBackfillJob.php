<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\BackgroundJob;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\AwarenessPacketProcessor;
use OCA\W3dsLogin\Service\ChatSyncService;
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
	 * How far back a newly linked user's history is fetched.
	 *
	 * The service holds the whole ecosystem's history -- hundreds of thousands
	 * of packets -- and almost none of it belongs to any one person. A month
	 * covers the conversations somebody is likely to care about at a cost of
	 * roughly fifty requests, where "everything ever" is an unbounded walk
	 * whose length grows with the network rather than with the user.
	 */
	private const WINDOW_DAYS = 30;

	/**
	 * Pages read per ontology, at 200 packets each.
	 *
	 * A ceiling rather than an expectation: it bounds one person's backfill so
	 * it cannot occupy the queue indefinitely on a busy network.
	 */
	private const MAX_PAGES = 60;

	public function __construct(
		ITimeFactory $time,
		private AwarenessClient $awarenessClient,
		private AwarenessPacketProcessor $processor,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run(mixed $argument): void {
		$w3id = is_array($argument) ? (string)($argument['w3id'] ?? '') : '';
		if ($w3id === '') {
			return;
		}

		if (!$this->awarenessClient->isConfigured()) {
			$this->logger->info('[W3DS Awareness] Skipping backfill, no awareness service configured', [
				'w3id' => $w3id,
			]);

			return;
		}

		// Linking is not rare enough to replay a month of history on every
		// attempt: a user may link, unlink and link again, and the same job can
		// be queued twice by a retried callback.
		$marker = 'awareness_backfilled_' . md5($w3id);
		if ($this->config->getAppValue(Application::APP_ID, $marker, '') !== '') {
			return;
		}
		$this->config->setAppValue(Application::APP_ID, $marker, (string)time());

		$from = gmdate('Y-m-d\TH:i:s\Z', time() - (self::WINDOW_DAYS * 86400));

		// Conversations first. A message whose chat has no local room is
		// dropped, not deferred, so reading them in the other order would
		// discard most of what this job exists to fetch.
		$chats = $this->replay([ChatSyncService::CHAT_SCHEMA_ID], $from);
		$messages = $this->replay(
			[ChatSyncService::MESSAGE_SCHEMA_ID, AwarenessClient::FILE_ONTOLOGY],
			$from,
		);

		$this->logger->info('[W3DS Awareness] Backfilled history for a newly linked user', [
			'w3id' => $w3id,
			'chatsApplied' => $chats,
			'messagesApplied' => $messages,
			'since' => $from,
		]);
	}

	/**
	 * Walk one slice of the history, applying whatever belongs here.
	 *
	 * The service cannot filter by conversation or by participant, so this
	 * reads the window and lets ingest decide: chats nobody here is in are
	 * ignored, and messages for unknown conversations are dropped. Wasteful in
	 * bytes, but it is the only query the API offers, and it is the same
	 * judgement the live poll makes on every packet anyway.
	 *
	 * @param string[] $ontologies
	 * @return int Packets that resulted in local work.
	 */
	private function replay(array $ontologies, string $from): int {
		$cursor = null;
		$applied = 0;

		for ($page = 0; $page < self::MAX_PAGES; $page++) {
			$result = $this->awarenessClient->fetchPackets($cursor, $ontologies, $from);

			// A failed read ends the backfill rather than skipping past it.
			// Continuing would leave a hole in the middle of someone's history
			// with nothing to signal it; stopping leaves the live poll to
			// carry on from the present, which is the normal state anyway.
			if ($result === null) {
				$this->logger->warning('[W3DS Awareness] Backfill stopped early: could not read history');

				break;
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
				break;
			}
		}

		return $applied;
	}
}
