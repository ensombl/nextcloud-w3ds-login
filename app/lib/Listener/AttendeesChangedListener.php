<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

use OCA\W3dsLogin\Service\ChatSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Re-push the chat to eVault when its attendee list changes (add or remove).
 * RoomCreatedEvent fires before participants are added, so without this the
 * eVault copy only ever knows about the creator.
 *
 * @implements IEventListener<Event>
 */
class AttendeesChangedListener implements IEventListener {
	public function __construct(
		private ChatSyncService $chatSyncService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		$eventClass = get_class($event);
		$this->logger->info('[W3DS Sync] AttendeesChangedListener FIRED', [
			'eventClass' => $eventClass,
		]);
		try {
			$room = null;
			if (method_exists($event, 'getRoom')) {
				$room = $event->getRoom();
			}
			if ($room === null) {
				$this->logger->info('[W3DS Sync] AttendeesChangedListener: no room on event', [
					'eventClass' => $eventClass,
				]);
				return;
			}

			$roomToken = $room->getToken();
			$this->logger->info('[W3DS Sync] AttendeesChangedListener: handling room', [
				'eventClass' => $eventClass,
				'roomToken' => $roomToken,
			]);

			// Prefer the session user -- this runs during the HTTP request
			// that's mutating the room, so the current user is the one
			// performing the action.
			$ncUid = '';
			$sessionUser = $this->userSession->getUser();
			if ($sessionUser !== null) {
				$ncUid = $sessionUser->getUID();
			}

			if (empty($ncUid)) {
				// Fall back to any owner/moderator already in the room.
				try {
					$participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
					foreach ($participantService->getParticipantsForRoom($room) as $p) {
						if ($p->getAttendee()->getActorType() !== 'users') {
							continue;
						}
						$level = (int)$p->getAttendee()->getParticipantType();
						if ($level === \OCA\Talk\Participant::OWNER
							|| $level === \OCA\Talk\Participant::MODERATOR) {
							$ncUid = $p->getAttendee()->getActorId();
							break;
						}
					}
				} catch (\Throwable) {
				}
			}

			if (empty($ncUid)) {
				return;
			}

			$this->chatSyncService->queueChatPush($ncUid, [
				'token' => $roomToken,
				'createdAt' => time(),
			]);
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Sync] AttendeesChangedListener error', [
				'exception' => $e,
			]);
		}
	}
}
