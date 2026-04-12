<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\BackgroundJob;

use OCA\W3dsLogin\Service\ChatSyncService;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-time job queued on W3DS login or account linking.
 * Pushes the user's existing Talk rooms and messages to their eVault,
 * and pulls any existing chats from the eVault into Talk.
 */
class InitialSyncJob extends QueuedJob {
    public function __construct(
        ITimeFactory $time,
        private ChatSyncService $chatSyncService,
        private UserProvisioningService $userProvisioning,
        private LoggerInterface $logger,
    ) {
        parent::__construct($time);
    }

    protected function run(mixed $argument): void {
        if (!is_array($argument)) {
            return;
        }

        $ncUid = $argument['ncUid'] ?? '';
        if (empty($ncUid)) {
            return;
        }

        $w3id = $this->userProvisioning->getLinkedW3id($ncUid);
        if ($w3id === null) {
            return;
        }

        $this->logger->info('Starting initial sync for user', ['ncUid' => $ncUid, 'w3id' => $w3id]);

        try {
            // Push existing Talk rooms to eVault
            $this->pushExistingRooms($ncUid);
        } catch (\Throwable $e) {
            $this->logger->error('Initial outbound sync failed', [
                'ncUid' => $ncUid,
                'exception' => $e,
            ]);
        }

        try {
            // Pull chats/messages from eVault into Talk
            $this->chatSyncService->pullSyncForUser($w3id);
        } catch (\Throwable $e) {
            $this->logger->error('Initial inbound sync failed', [
                'ncUid' => $ncUid,
                'exception' => $e,
            ]);
        }

        $this->logger->info('Initial sync completed for user', ['ncUid' => $ncUid]);
    }

    private function pushExistingRooms(string $ncUid): void {
        if (!class_exists(\OCA\Talk\Manager::class)) {
            return;
        }

        try {
            $manager = \OCP\Server::get(\OCA\Talk\Manager::class);
            $rooms = $manager->getRoomsForActor('users', $ncUid);

            foreach ($rooms as $room) {
                $participants = [];
                try {
                    $participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
                    $participantObjects = $participantService->getParticipantsForRoom($room);
                    foreach ($participantObjects as $p) {
                        if ($p->getAttendee()->getActorType() === 'users') {
                            $participants[] = $p->getAttendee()->getActorId();
                        }
                    }
                } catch (\Throwable) {
                    // Continue without full participant list
                }

                $this->chatSyncService->pushChat($ncUid, [
                    'token' => $room->getToken(),
                    'type' => $room->getType(),
                    'name' => $room->getName(),
                    'participants' => $participants,
                    'createdAt' => time(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Failed to push existing rooms', [
                'ncUid' => $ncUid,
                'exception' => $e,
            ]);
        }
    }
}
