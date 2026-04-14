<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

use OCA\W3dsLogin\Service\ChatSyncService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Listens for Talk room creation events and pushes to eVault inline so sync
 * is near-real-time.
 *
 * @implements IEventListener<Event>
 */
class RoomCreatedListener implements IEventListener {
	public function __construct(
		private ChatSyncService $chatSyncService,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		$eventClass = get_class($event);
		$this->logger->info('[W3DS Sync] RoomCreatedListener triggered', [
			'eventClass' => $eventClass,
		]);

		try {
			$room = null;

			if (method_exists($event, 'getRoom')) {
				$room = $event->getRoom();
			}

			if ($room === null) {
				$this->logger->debug('[W3DS Sync] Room event has no room, skipping');
				return;
			}

			$roomToken = $room->getToken();
			$roomType = $room->getType();
			$roomName = $room->getName();

			$this->logger->info('[W3DS Sync] Room created', [
				'roomToken' => $roomToken,
				'roomType' => $roomType,
				'roomName' => $roomName,
			]);

			// RoomCreatedEvent fires before participants are added, so the
			// participants list is empty here. The session user IS the
			// creator -- the room was just created by this HTTP request.
			$creatorUid = '';
			$sessionUser = $this->userSession->getUser();
			if ($sessionUser !== null) {
				$creatorUid = $sessionUser->getUID();
			}

			// Best-effort: collect any participants that already exist
			$participants = [];
			try {
				$participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
				foreach ($participantService->getParticipantsForRoom($room) as $p) {
					if ($p->getAttendee()->getActorType() === 'users') {
						$uid = $p->getAttendee()->getActorId();
						$participants[] = $uid;
						if (empty($creatorUid)) {
							$creatorUid = $uid;
						}
					}
				}
			} catch (\Throwable $e) {
				$this->logger->debug('[W3DS Sync] Could not fetch participants during room creation', [
					'exception' => $e->getMessage(),
				]);
			}

			if (empty($creatorUid)) {
				$this->logger->warning('[W3DS Sync] No creator UID found for room, skipping', [
					'roomToken' => $roomToken,
				]);
				return;
			}

			// Coalesced with any AttendeesAddedEvent fired in the same HTTP
			// request, flushed once at shutdown with the final roster.
			$this->chatSyncService->queueChatPush($creatorUid, [
				'token' => $roomToken,
				'type' => $roomType,
				'name' => $roomName,
				'participants' => $participants,
				'createdAt' => time(),
			]);
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Sync] RoomCreatedListener error', [
				'exception' => $e,
			]);
		}
	}
}
