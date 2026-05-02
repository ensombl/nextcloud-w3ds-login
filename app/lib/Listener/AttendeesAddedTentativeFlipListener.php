<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

use OCA\W3dsLogin\Db\TentativeUserMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * On Talk's AttendeesAddedEvent, clear the tentative flag for every
 * added user actor. After this point the account is "real" and survives
 * the cleanup job.
 *
 * @implements IEventListener<Event>
 */
class AttendeesAddedTentativeFlipListener implements IEventListener {
	public function __construct(
		private TentativeUserMapper $tentativeUserMapper,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getAttendees')) {
			return;
		}

		try {
			$attendees = $event->getAttendees();
		} catch (\Throwable) {
			return;
		}
		if (!is_iterable($attendees)) {
			return;
		}

		foreach ($attendees as $attendee) {
			try {
				$actorType = method_exists($attendee, 'getActorType') ? $attendee->getActorType() : null;
				$actorId = method_exists($attendee, 'getActorId') ? $attendee->getActorId() : null;
				if ($actorType !== 'users' || !is_string($actorId) || $actorId === '') {
					continue;
				}
				$this->tentativeUserMapper->clear($actorId);
			} catch (\Throwable $e) {
				$this->logger->warning('[W3DS Search] failed to clear tentative flag', [
					'exception' => $e->getMessage(),
				]);
			}
		}
	}
}
