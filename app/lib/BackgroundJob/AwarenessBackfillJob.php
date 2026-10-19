<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\BackgroundJob;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\ChatHistoryService;
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
	 * How far back conversations started elsewhere, and all messages, are
	 * fetched. The person's own conversations are read without a window: a
	 * message is only placed in a conversation that exists here, and one
	 * started before the window would otherwise lose every message since.
	 */
	private const WINDOW_DAYS = 90;

	/**
	 * Pages read per walk, at 500 packets each: a ceiling, not an
	 * expectation, so a runaway history cannot hold the queue indefinitely.
	 * Reaching it is reported as an incomplete sync.
	 */
	private const MAX_PAGES = 200;

	/** eVaults read for messages, at most, in one history fetch. */
	private const MAX_VAULTS = 500;

	public function __construct(
		ITimeFactory $time,
		private AwarenessClient $awarenessClient,
		private ChatHistoryService $history,
		private W3dsMappingMapper $mappings,
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

		// Asked for by the user, or by Talk on their first visit: run even if
		// this identity was fetched before, which is the point of asking.
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

		// Linking is not rare enough to replay history on every attempt: a
		// user may link, unlink and link again, and the same job can be
		// queued twice by a retried callback.
		$marker = 'awareness_backfilled_' . md5($w3id);
		if (!$forced && $this->config->getAppValue(Application::APP_ID, $marker, '') !== '') {
			return;
		}
		$this->config->setAppValue(Application::APP_ID, $marker, (string)time());
		$this->status->markRunning($w3id);

		$from = gmdate('Y-m-d\TH:i:s\Z', time() - (self::WINDOW_DAYS * 86400));

		try {
			// Conversations first. A message whose chat has no local room is
			// dropped, not deferred, so reading them in the other order would
			// discard most of what this job exists to fetch.
			//
			// The ones in this person's own eVault, however old; then every
			// conversation from the window, because a group keeps its
			// conversation in the group's eVault rather than its members',
			// and only the processor can tell which of those include them.
			$own = $this->history->walk([ChatSyncService::CHAT_SCHEMA_ID], null, $w3id, self::MAX_PAGES);
			$groups = $this->history->walk([ChatSyncService::CHAT_SCHEMA_ID], $from, null, self::MAX_PAGES);

			// Then the messages, from every eVault that holds any: each
			// conversation's own and each participant's. Messages are not
			// copied to whoever reads them.
			$vaults = $this->vaultsFor($w3id);
			$messages = $this->history->fetchFromVaults(
				array_slice($vaults, 0, self::MAX_VAULTS),
				[ChatSyncService::MESSAGE_SCHEMA_ID, AwarenessClient::FILE_ONTOLOGY],
				$from,
				self::MAX_PAGES,
			);
		} catch (\Throwable $e) {
			$this->status->markFailed($w3id, 'Fetching conversations failed. Try again later.');
			throw $e;
		}

		$chats = $own['applied'] + $groups['applied'];
		$complete = $own['complete'] && $groups['complete'] && $messages['complete'] && count($vaults) <= self::MAX_VAULTS;
		if (!$complete && $chats === 0 && $messages['applied'] === 0) {
			// Nothing at all could be read: the service is unreachable or
			// refused the key, which only an administrator can fix.
			$this->status->markFailed($w3id, 'Could not reach the awareness service. Ask an administrator to check it under Administration settings → W3DS.');
		} else {
			$this->status->markDone($w3id, $chats, $messages['applied'], $complete);
		}

		$this->logger->info('[W3DS Awareness] Fetched history', [
			'w3id' => $w3id,
			'chatsApplied' => $chats,
			'messagesApplied' => $messages['applied'],
			'vaultsRead' => min(count($vaults), self::MAX_VAULTS),
			'complete' => $complete,
			'since' => $from,
		]);
	}

	/**
	 * @return list<string>
	 */
	private function vaultsFor(string $w3id): array {
		try {
			$uid = $this->mappings->findByW3id($w3id)->getNcUid();
		} catch (\Throwable) {
			return [$w3id];
		}

		// Their own eVault too: it holds what they sent.
		$vaults = $this->history->vaultsForUser($uid);
		array_unshift($vaults, $w3id);

		return array_values(array_unique($vaults));
	}
}
