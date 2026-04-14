<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

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
			], $roomToken);
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Sync] MessageSentListener error', [
				'exception' => $e,
				'eventClass' => $eventClass,
			]);
		}
	}
}
