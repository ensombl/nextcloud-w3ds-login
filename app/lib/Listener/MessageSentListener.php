<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

use OCA\W3dsLogin\Service\AttachmentSyncService;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Listens for Talk message events and pushes to eVault inline so sync is
 * near-real-time (Nextcloud cron granularity is too coarse for chat).
 *
 * @implements IEventListener<Event>
 */
class MessageSentListener implements IEventListener {
	/**
	 * Comment verbs worth replicating: `comment` is user text, and
	 * `object_shared` is a file share. Everything else Talk emits is room
	 * bookkeeping.
	 */
	private const SYNCABLE_VERBS = ['comment', AttachmentSyncService::TALK_SHARE_VERB];

	public function __construct(
		private ChatSyncService $chatSyncService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		$eventClass = get_class($event);
		$this->logger->info('[W3DS Sync] MessageSentListener triggered', [
			'eventClass' => $eventClass,
		]);

		try {
			$comment = null;
			$room = null;

			// Talk's ChatMessageSentEvent has getComment() + getRoom()
			if (method_exists($event, 'getComment')) {
				$comment = $event->getComment();
			}
			if (method_exists($event, 'getRoom')) {
				$room = $event->getRoom();
			}

			if ($comment === null || $room === null) {
				$this->logger->debug('[W3DS Sync] Event missing comment or room, skipping', [
					'hasComment' => $comment !== null,
					'hasRoom' => $room !== null,
					'eventClass' => $eventClass,
				]);
				return;
			}

			$messageId = (string)$comment->getId();
			$senderUid = $comment->getActorId();
			$roomToken = $room->getToken();
			$message = $comment->getMessage();
			$verb = $comment->getVerb();

			// We now hear SystemMessageSentEvent too, because that is the only
			// event a file share emits. Talk's other system messages (joins,
			// leaves, calls, renames, read markers) are room bookkeeping, not
			// conversation, and pushing them would litter every peer's eVault
			// with envelopes no platform can render. Sync exactly the two
			// verbs that carry something a person wrote or shared.
			if (!in_array($verb, self::SYNCABLE_VERBS, true)) {
				return;
			}

			$this->logger->info('[W3DS Sync] Chat message detected', [
				'messageId' => $messageId,
				'senderUid' => $senderUid,
				'roomToken' => $roomToken,
				'verb' => $verb,
				'messageLength' => strlen($message),
			]);

			if (empty($messageId) || empty($senderUid) || empty($roomToken)) {
				$this->logger->warning('[W3DS Sync] Missing required fields, skipping');
				return;
			}

			// Push inline so sync is near-real-time. This adds ~1-2s to the
			// Talk HTTP response but avoids the 5-minute cron wait a queued
			// job would incur.
			\ignore_user_abort(true);
			$this->chatSyncService->pushMessage($senderUid, [
				'id' => $messageId,
				'message' => $message,
				'verb' => $verb,
				'timestamp' => $comment->getCreationDateTime()->getTimestamp(),
				// A file share carries its payload here, not in the message
				// text, which is only the literal `{file}` placeholder.
				'messageParameters' => $this->decodeMessageParameters($comment),
			], $roomToken);
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Sync] MessageSentListener error', [
				'exception' => $e,
				'eventClass' => $eventClass,
			]);
		}
	}

	/**
	 * Talk stores a file share's payload in the comment's message
	 * parameters, JSON-encoded, while the message text is only `{file}`.
	 *
	 * @return array<string, mixed>
	 */
	private function decodeMessageParameters(object $comment): array {
		if (!method_exists($comment, 'getMessage')) {
			return [];
		}

		// Newer Talk exposes parsed parameters directly; older versions keep
		// them JSON-encoded on the comment.
		if (method_exists($comment, 'getMessageParameters')) {
			$params = $comment->getMessageParameters();
			if (is_array($params)) {
				return $params;
			}
			if (is_string($params) && $params !== '') {
				$decoded = json_decode($params, true);
				return is_array($decoded) ? $decoded : [];
			}
		}

		return [];
	}
}
