<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Db\IdMappingMapper;
use OCA\W3dsLogin\Db\SyncCursorMapper;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

class ChatSyncService {
	public const CHAT_SCHEMA_ID = '550e8400-e29b-41d4-a716-446655440003';
	public const MESSAGE_SCHEMA_ID = '550e8400-e29b-41d4-a716-446655440004';

	private const SYNC_LOCK_PREFIX = 'w3ds_sync_lock_';
	private const SYNC_LOCK_TTL = 10;
	private const INBOUND_POST_LOCK_PREFIX = 'w3ds_inbound_post_';
	private const INBOUND_POST_LOCK_TTL = 10;
	private const MESSAGE_SIG_CACHE_PREFIX = 'w3ds_msg_sig_';
	private const MESSAGE_SIG_CACHE_TTL = 86400; // 24h — enough to span a typical poll window

	private const PULL_PAGE_SIZE = 50;
	private const PULL_MAX_PAGES = 5;

	private ICache $cache;

	public function __construct(
		private EvaultClient $evaultClient,
		private IdMappingMapper $idMappingMapper,
		private SyncCursorMapper $syncCursorMapper,
		private UserProvisioningService $userProvisioning,
		private W3dsMappingMapper $w3dsMappingMapper,
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
	}

	// ---------------------------------------------------------------
	// Outbound: Push local Talk data to eVault
	// ---------------------------------------------------------------

	/**
	 * Push a Talk room to the owner's eVault as a Chat MetaEnvelope.
	 *
	 * Always resolves the CURRENT participants and admins from the live
	 * Talk room state so the eVault reflects reality -- RoomCreatedEvent
	 * fires before participants are added, so the caller's list is often
	 * incomplete.
	 *
	 * @param string $ncUid The Nextcloud user who owns/created the room
	 * @param array $roomData Talk room data: token, type, name, createdAt (timestamp)
	 */
	public function pushChat(string $ncUid, array $roomData): void {
		$w3id = $this->userProvisioning->getLinkedW3id($ncUid);
		if ($w3id === null) {
			return; // User not linked to W3DS
		}

		$localId = (string)($roomData['token'] ?? '');
		if (empty($localId)) {
			return;
		}

		// Anti-ping-pong: skip if this entity was just created from an inbound webhook
		if ($this->isSyncLocked('chat', $localId)) {
			return;
		}

		// Read CURRENT participants + admins from the live Talk room.
		[$participantUids, $adminUids, $roomType, $roomName] = $this->readRoomState($localId, $roomData);

		// Resolve each participant/admin NC UID → W3ID → profile envelope ID.
		[$participantEnvelopeIds, $participantW3ids] = $this->resolveParticipantProfileIds($participantUids);
		[$adminEnvelopeIds, ] = $this->resolveParticipantProfileIds($adminUids);

		// Always include the owner themselves in both participant lists and
		// (if they're not already there) in admins -- the creator of a
		// Talk room is its OWNER level.
		$ownerProfileId = $this->getOrFallbackProfileId($w3id);
		if (!in_array($ownerProfileId, $participantEnvelopeIds, true)) {
			$participantEnvelopeIds[] = $ownerProfileId;
		}
		if (!in_array($w3id, $participantW3ids, true)) {
			$participantW3ids[] = $w3id;
		}
		if (!in_array($ownerProfileId, $adminEnvelopeIds, true)) {
			$adminEnvelopeIds[] = $ownerProfileId;
		}

		$payload = [
			'id' => $this->generateUuid(),
			'type' => $this->mapRoomTypeToGlobal($roomType),
			'participantIds' => $participantEnvelopeIds,
			'admins' => $adminEnvelopeIds,
			'createdAt' => $this->toIso8601($roomData['createdAt'] ?? time()),
		];

		if (!empty($roomName)) {
			$payload['name'] = $roomName;
		}

		$lastMessageGlobalId = $this->idMappingMapper->getGlobalId('message', (string)($roomData['lastMessageId'] ?? ''));
		if ($lastMessageGlobalId !== null) {
			$payload['lastMessageId'] = $lastMessageGlobalId;
		}

		// ACL by W3ID (eVault access is keyed on W3IDs, not envelope IDs)
		$acl = !empty($participantW3ids) ? $participantW3ids : ['*'];

		$existingGlobalId = $this->idMappingMapper->getGlobalId('chat', $localId);

		if ($existingGlobalId !== null) {
			$payload['updatedAt'] = $this->toIso8601(time());
			$this->evaultClient->updateMetaEnvelope($w3id, $existingGlobalId, self::CHAT_SCHEMA_ID, $payload, $acl);
			$this->logger->info('[W3DS Sync] Updated chat in eVault', [
				'localId' => $localId,
				'globalId' => $existingGlobalId,
				'participantCount' => count($participantEnvelopeIds),
				'adminCount' => count($adminEnvelopeIds),
			]);
		} else {
			$globalId = $this->evaultClient->createMetaEnvelope($w3id, self::CHAT_SCHEMA_ID, $payload, $acl);
			if ($globalId !== null) {
				$this->idMappingMapper->storeMapping('chat', $localId, $globalId, $w3id);
				$this->logger->info('[W3DS Sync] Pushed new chat to eVault', [
					'localId' => $localId,
					'globalId' => $globalId,
					'participantCount' => count($participantEnvelopeIds),
					'adminCount' => count($adminEnvelopeIds),
				]);
			} else {
				$this->logger->error('[W3DS Sync] Failed to create chat MetaEnvelope', ['localId' => $localId]);
			}
		}
	}

	/**
	 * Read the live Talk room state: participants, admins, type, and name.
	 * Falls back to caller-supplied values if the Talk services are unavailable.
	 *
	 * @return array{0: string[], 1: string[], 2: int, 3: string}
	 */
	private function readRoomState(string $roomToken, array $fallback): array {
		$participantUids = [];
		$adminUids = [];
		$roomType = (int)($fallback['type'] ?? 2);
		$roomName = (string)($fallback['name'] ?? '');

		if (!$this->isTalkAvailable()) {
			return [$participantUids, $adminUids, $roomType, $roomName];
		}

		try {
			$manager = \OCP\Server::get(\OCA\Talk\Manager::class);
			$room = $manager->getRoomByToken($roomToken);
			$roomType = $room->getType();
			$roomName = $room->getName();

			$participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
			foreach ($participantService->getParticipantsForRoom($room) as $p) {
				$attendee = $p->getAttendee();
				if ($attendee->getActorType() !== 'users') {
					continue;
				}
				$uid = $attendee->getActorId();
				$participantUids[] = $uid;

				// Talk: OWNER=1, MODERATOR=2 are the admin levels
				$level = (int)$attendee->getParticipantType();
				if ($level === \OCA\Talk\Participant::OWNER || $level === \OCA\Talk\Participant::MODERATOR) {
					$adminUids[] = $uid;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->debug('[W3DS Sync] readRoomState fallback', [
				'roomToken' => $roomToken,
				'exception' => $e->getMessage(),
			]);
		}

		return [$participantUids, $adminUids, $roomType, $roomName];
	}

	/**
	 * Ensure a Talk room has been synced to eVault. Creates a new Chat
	 * envelope on first call; subsequent calls are no-ops (use
	 * {@see pushChat()} directly to refresh participants / admins).
	 */
	private function ensureChatSynced(string $ncUid, string $roomToken): ?string {
		$existing = $this->idMappingMapper->getGlobalId('chat', $roomToken);
		if ($existing !== null) {
			return $existing;
		}

		$this->pushChat($ncUid, [
			'token' => $roomToken,
			'createdAt' => time(),
		]);

		return $this->idMappingMapper->getGlobalId('chat', $roomToken);
	}

	/**
	 * Push a Talk message to the sender's eVault as a Message MetaEnvelope.
	 *
	 * @param string $ncUid The sender's Nextcloud UID
	 * @param array $messageData Talk message data: id, message, verb, timestamp
	 * @param string $roomToken The Talk room token
	 */
	public function pushMessage(string $ncUid, array $messageData, string $roomToken): void {
		$w3id = $this->userProvisioning->getLinkedW3id($ncUid);
		if ($w3id === null) {
			return;
		}

		$localId = (string)($messageData['id'] ?? '');
		if (empty($localId)) {
			return;
		}

		if ($this->isSyncLocked('message', $localId)) {
			return;
		}

		// If this message is being posted by handleInboundMessage right now,
		// skip -- otherwise we'd ping-pong the message we just received.
		if ($this->isInboundPostActive($ncUid, $roomToken)) {
			return;
		}

		// Resolve (or auto-create) the chat's global ID
		$chatGlobalId = $this->ensureChatSynced($ncUid, $roomToken);
		if ($chatGlobalId === null) {
			$this->logger->warning('[W3DS Sync] Cannot push message: chat not syncable', ['roomToken' => $roomToken]);
			return;
		}

		// senderId in the Message schema is the sender's User profile envelope ID
		$senderEnvelopeId = $this->getOrFallbackProfileId($w3id);

		$payload = [
			'id' => $this->generateUuid(),
			'chatId' => $chatGlobalId,
			'senderId' => $senderEnvelopeId,
			'content' => (string)($messageData['message'] ?? ''),
			'type' => $this->mapMessageVerbToGlobal($messageData['verb'] ?? 'comment'),
			'createdAt' => $this->toIso8601($messageData['timestamp'] ?? time()),
		];

		$acl = [$w3id]; // At minimum, sender can access

		$existingGlobalId = $this->idMappingMapper->getGlobalId('message', $localId);

		if ($existingGlobalId !== null) {
			$payload['updatedAt'] = $this->toIso8601(time());
			$this->evaultClient->updateMetaEnvelope($w3id, $existingGlobalId, self::MESSAGE_SCHEMA_ID, $payload, $acl);
		} else {
			$globalId = $this->evaultClient->createMetaEnvelope($w3id, self::MESSAGE_SCHEMA_ID, $payload, $acl);
			if ($globalId !== null) {
				$this->idMappingMapper->storeMapping('message', $localId, $globalId, $w3id);
				$this->logger->info('[W3DS Sync] Pushed new message to eVault', [
					'localId' => $localId,
					'globalId' => $globalId,
				]);
			} else {
				$this->logger->error('[W3DS Sync] Failed to create message MetaEnvelope', ['localId' => $localId]);
			}
		}
	}

	// ---------------------------------------------------------------
	// Inbound: Handle webhooks from eVault
	// ---------------------------------------------------------------

	/**
	 * Handle an inbound Chat MetaEnvelope from a webhook.
	 */
	public function handleInboundChat(string $globalId, string $ownerW3id, array $data): void {
		if (!$this->isTalkAvailable()) {
			$this->logger->warning('Talk not available, skipping inbound chat');
			return;
		}

		// Check if we already have this chat locally
		$existingLocalId = $this->idMappingMapper->getLocalId('chat', $globalId);
		if ($existingLocalId !== null) {
			$this->updateLocalChat($existingLocalId, $data);
			return;
		}

		// participantIds may be User profile envelope IDs OR raw W3IDs (legacy).
		// For envelope IDs we can't reverse-lookup without a registry query, so
		// we best-effort resolve only the ones we recognize.
		$participantIds = $data['participantIds'] ?? [];
		$participantUids = [];
		foreach ($participantIds as $pid) {
			$w3id = $this->resolveParticipantIdToW3id($pid);
			if ($w3id === null) {
				continue;
			}
			$uid = $this->resolveW3idToNcUid($w3id);
			if ($uid !== null) {
				$participantUids[] = $uid;
			}
		}

		if (empty($participantUids)) {
			$this->logger->warning('No resolvable participants for inbound chat', ['globalId' => $globalId]);
			return;
		}

		// Create the Talk room
		$roomType = ($data['type'] ?? 'group') === 'direct'
			? 1  // ONE_TO_ONE
			: 2; // GROUP

		try {
			$roomToken = $this->createTalkRoom($roomType, $data['name'] ?? '', $participantUids);
			if ($roomToken === null) {
				return;
			}

			// Set the sync lock to prevent outbound re-push
			$this->setSyncLock('chat', $roomToken);

			$this->idMappingMapper->storeMapping('chat', $roomToken, $globalId, $ownerW3id);
			$this->logger->info('Created local chat from inbound webhook', [
				'globalId' => $globalId,
				'roomToken' => $roomToken,
			]);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to create local chat from webhook', [
				'globalId' => $globalId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Handle an inbound Message MetaEnvelope from a webhook.
	 */
	public function handleInboundMessage(string $globalId, string $ownerW3id, array $data): void {
		if (!$this->isTalkAvailable()) {
			$this->logger->warning('Talk not available, skipping inbound message');
			return;
		}

		// Check for duplicate
		$existingLocalId = $this->idMappingMapper->getLocalId('message', $globalId);
		if ($existingLocalId !== null) {
			return; // Already exists
		}

		// Resolve the chat
		$chatGlobalId = $data['chatId'] ?? '';
		$roomToken = $this->idMappingMapper->getLocalId('chat', $chatGlobalId);
		if ($roomToken === null) {
			$this->logger->warning('Inbound message for unknown chat, skipping', [
				'globalId' => $globalId,
				'chatGlobalId' => $chatGlobalId,
			]);
			return;
		}

		// senderId may be a profile envelope ID OR a raw W3ID
		$senderW3id = $this->resolveParticipantIdToW3id((string)($data['senderId'] ?? ''));
		if ($senderW3id === null) {
			$this->logger->warning('[W3DS Sync] Cannot resolve sender ID to W3ID', [
				'senderId' => $data['senderId'] ?? '',
			]);
			return;
		}
		$senderUid = $this->resolveW3idToNcUid($senderW3id);
		if ($senderUid === null) {
			$this->logger->warning('Cannot resolve sender for inbound message', [
				'senderW3id' => $senderW3id,
			]);
			return;
		}

		$content = $data['content'] ?? '';
		$messageType = $data['type'] ?? 'text';

		// Cross-replica dedup: the same logical message lives in every
		// participant's eVault under a *different* global_id AND a
		// *different* createdAt timestamp (each platform stamps on
		// replication). Only sender + chat + content are stable, so that's
		// the signature — sufficient because Talk treats identical
		// messages in the same chat as one.
		$signature = md5($senderUid . '|' . $chatGlobalId . '|' . $content);
		$sigKey = self::MESSAGE_SIG_CACHE_PREFIX . $signature;
		$existingLocal = $this->cache->get($sigKey);
		if (is_string($existingLocal) && $existingLocal !== '') {
			// Record this replica's mapping so future polls skip it cheaply.
			try {
				$this->idMappingMapper->storeMapping('message', $existingLocal, $globalId, $ownerW3id);
			} catch (\Throwable) {
				// Duplicate-key races are fine — one of them wins.
			}
			return;
		}

		// Prevent the MessageSentListener from re-pushing what we're about
		// to post — Talk fires the event synchronously during sendMessage(),
		// before we know the resulting local ID.
		$this->beginInboundPost($senderUid, $roomToken);
		try {
			$localMessageId = $this->postTalkMessage($roomToken, $senderUid, $content, $messageType);
			if ($localMessageId === null) {
				return;
			}

			$this->setSyncLock('message', $localMessageId);
			$this->idMappingMapper->storeMapping('message', $localMessageId, $globalId, $ownerW3id);
			$this->cache->set($sigKey, $localMessageId, self::MESSAGE_SIG_CACHE_TTL);
		} catch (\Throwable $e) {
			$this->logger->error('Failed to create local message from webhook', [
				'globalId' => $globalId,
				'exception' => $e,
			]);
		} finally {
			$this->endInboundPost($senderUid, $roomToken);
		}
	}

	// ---------------------------------------------------------------
	// Pull sync: Fetch from eVault on schedule
	// ---------------------------------------------------------------

	/**
	 * Poll all participants' eVaults for new messages in a specific Talk room.
	 * Driven by the frontend every ~15s while a room is open.
	 *
	 * @return int Number of new messages synced into Talk
	 */
	public function pollRoom(string $roomToken): int {
		if (!$this->isTalkAvailable()) {
			return 0;
		}

		$chatGlobalId = $this->idMappingMapper->getGlobalId('chat', $roomToken);
		if ($chatGlobalId === null) {
			// Chat has never been synced outbound; nothing to correlate against
			return 0;
		}

		// Collect the W3IDs of all linked participants of the room
		$participantW3ids = [];
		try {
			$manager = \OCP\Server::get(\OCA\Talk\Manager::class);
			$room = $manager->getRoomByToken($roomToken);
			$participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
			foreach ($participantService->getParticipantsForRoom($room) as $p) {
				if ($p->getAttendee()->getActorType() !== 'users') {
					continue;
				}
				$uid = $p->getAttendee()->getActorId();
				$w3id = $this->userProvisioning->getLinkedW3id($uid);
				if ($w3id !== null && !in_array($w3id, $participantW3ids, true)) {
					$participantW3ids[] = $w3id;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Sync] pollRoom: failed to collect participants', [
				'roomToken' => $roomToken,
				'exception' => $e->getMessage(),
			]);
			return 0;
		}

		// Prime the envelope-id → W3ID reverse cache for every linked
		// participant. Inbound senderIds on messages are profile envelope
		// UUIDs; without this priming, handleInboundMessage would be unable
		// to reverse-resolve them and would silently drop every message.
		foreach ($participantW3ids as $w3id) {
			$this->evaultClient->getProfileEnvelopeId($w3id);
		}

		$newCount = 0;
		foreach ($participantW3ids as $w3id) {
			try {
				// Fetch recent Message envelopes from this participant's eVault.
				// Server-side search filters by chatId so we don't paginate
				// the entire message history.
				$result = $this->evaultClient->fetchMetaEnvelopes(
					$w3id,
					self::MESSAGE_SCHEMA_ID,
					50,
					null,
					[
						'term' => $chatGlobalId,
						'fields' => ['chatId'],
						'mode' => 'EXACT',
					],
				);

				foreach (($result['edges'] ?? []) as $edge) {
					$node = $edge['node'] ?? [];
					$globalId = $node['id'] ?? '';
					$data = $node['parsed'] ?? [];
					if ($globalId === '' || empty($data)) {
						continue;
					}
					if ($this->idMappingMapper->getLocalId('message', $globalId) !== null) {
						continue; // already synced
					}
					$this->handleInboundMessage($globalId, $w3id, $data);
					// Only count as synced if the mapping now exists —
					// handleInboundMessage returns void and may skip silently.
					if ($this->idMappingMapper->getLocalId('message', $globalId) !== null) {
						$newCount++;
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning('[W3DS Sync] pollRoom: fetch failed for participant', [
					'roomToken' => $roomToken,
					'w3id' => $w3id,
					'exception' => $e->getMessage(),
				]);
			}
		}

		if ($newCount > 0) {
			$this->logger->info('[W3DS Sync] pollRoom synced new messages', [
				'roomToken' => $roomToken,
				'newCount' => $newCount,
				'participantCount' => count($participantW3ids),
			]);
		}

		return $newCount;
	}

	/**
	 * Pull sync all chats and messages for a given user from their eVault.
	 */
	public function pullSyncForUser(string $w3id): void {
		$this->pullEnvelopes($w3id, self::CHAT_SCHEMA_ID, 'chat');
		$this->pullEnvelopes($w3id, self::MESSAGE_SCHEMA_ID, 'message');
	}

	private function pullEnvelopes(string $w3id, string $schemaId, string $entityType): void {
		$cursor = $this->syncCursorMapper->getCursor($w3id, $schemaId);

		for ($page = 0; $page < self::PULL_MAX_PAGES; $page++) {
			$result = $this->evaultClient->fetchMetaEnvelopes($w3id, $schemaId, self::PULL_PAGE_SIZE, $cursor);

			$edges = $result['edges'] ?? [];
			if (empty($edges)) {
				break;
			}

			foreach ($edges as $edge) {
				$node = $edge['node'] ?? [];
				$globalId = $node['id'] ?? '';
				$data = $node['parsed'] ?? [];

				if (empty($globalId) || empty($data)) {
					continue;
				}

				// Skip if we already have this entity
				if ($this->idMappingMapper->getLocalId($entityType, $globalId) !== null) {
					continue;
				}

				if ($entityType === 'chat') {
					$this->handleInboundChat($globalId, $w3id, $data);
				} else {
					$this->handleInboundMessage($globalId, $w3id, $data);
				}
			}

			$pageInfo = $result['pageInfo'] ?? [];
			$cursor = $pageInfo['endCursor'] ?? null;

			if (!($pageInfo['hasNextPage'] ?? false)) {
				break;
			}
		}

		$this->syncCursorMapper->upsertCursor($w3id, $schemaId, $cursor);
	}

	// ---------------------------------------------------------------
	// Talk interaction helpers
	// ---------------------------------------------------------------

	private function isTalkAvailable(): bool {
		return class_exists(\OCA\Talk\Manager::class);
	}

	/**
	 * Create a Talk room and add participants. Returns the room token or null.
	 */
	private function createTalkRoom(int $type, string $name, array $participantUids): ?string {
		try {
			$manager = \OCP\Server::get(\OCA\Talk\Manager::class);

			$room = $manager->createRoom($type, $name);
			$participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);

			foreach ($participantUids as $uid) {
				try {
					$participantService->addUsers($room, [[
						'actorType' => 'users',
						'actorId' => $uid,
					]]);
				} catch (\Throwable $e) {
					$this->logger->warning('Failed to add participant to room', [
						'uid' => $uid,
						'exception' => $e,
					]);
				}
			}

			return $room->getToken();
		} catch (\Throwable $e) {
			$this->logger->error('Failed to create Talk room', ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Post a message to a Talk room on behalf of a user. Returns the comment ID or null.
	 */
	private function postTalkMessage(string $roomToken, string $senderUid, string $content, string $messageType): ?string {
		try {
			$manager = \OCP\Server::get(\OCA\Talk\Manager::class);
			$room = $manager->getRoomByToken($roomToken);

			$chatManager = \OCP\Server::get(\OCA\Talk\Chat\ChatManager::class);

			$creationDateTime = new \DateTime();
			$comment = $chatManager->sendMessage(
				$room,
				null,
				'users',
				$senderUid,
				$content,
				$creationDateTime,
				null,
				'',
				false,
			);

			return (string)$comment->getId();
		} catch (\Throwable $e) {
			$this->logger->error('Failed to post Talk message', [
				'roomToken' => $roomToken,
				'senderUid' => $senderUid,
				'exception' => $e,
			]);
			return null;
		}
	}

	private function updateLocalChat(string $roomToken, array $data): void {
		try {
			$manager = \OCP\Server::get(\OCA\Talk\Manager::class);
			$room = $manager->getRoomByToken($roomToken);

			if (!empty($data['name']) && $room->getName() !== $data['name']) {
				$room->setName($data['name']);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to update local chat', [
				'roomToken' => $roomToken,
				'exception' => $e,
			]);
		}
	}

	// ---------------------------------------------------------------
	// Schema mapping helpers
	// ---------------------------------------------------------------

	private function mapRoomTypeToGlobal(int $talkType): string {
		// Talk types: 1=ONE_TO_ONE, 2=GROUP, 3=PUBLIC, 4=CHANGELOG, 5=FORMER_ONE_TO_ONE, 6=NOTE_TO_SELF
		return ($talkType === 1 || $talkType === 5) ? 'direct' : 'group';
	}

	private function mapMessageVerbToGlobal(string $verb): string {
		return match ($verb) {
			'system' => 'system',
			'object' => 'file',
			default => 'text',
		};
	}

	/**
	 * Look up the profile envelope ID for a W3ID. If the user has not yet
	 * published a User profile envelope to their eVault, fall back to the
	 * raw W3ID -- this keeps sync functional during bootstrap, and other
	 * platforms can still attribute the data to the correct owner.
	 */
	private function getOrFallbackProfileId(string $w3id): string {
		$profileId = $this->evaultClient->getProfileEnvelopeId($w3id);
		if ($profileId !== null) {
			return $profileId;
		}
		$this->logger->warning('[W3DS Sync] No User profile envelope found, falling back to W3ID', [
			'w3id' => $w3id,
		]);
		return $w3id;
	}

	/**
	 * Resolve an array of NC UIDs to their User profile envelope IDs.
	 * Returns [$envelopeIds, $w3ids] -- parallel lists for the linked participants.
	 *
	 * @return array{0: string[], 1: string[]}
	 */
	private function resolveParticipantProfileIds(array $ncUids): array {
		$envelopeIds = [];
		$w3ids = [];
		foreach ($ncUids as $uid) {
			$w3id = $this->userProvisioning->getLinkedW3id($uid);
			if ($w3id === null) {
				continue;
			}
			$envelopeIds[] = $this->getOrFallbackProfileId($w3id);
			$w3ids[] = $w3id;
		}
		return [$envelopeIds, $w3ids];
	}

	/**
	 * Resolve a participant identifier (may be raw W3ID or a User profile
	 * envelope UUID) back to a W3ID. Raw W3IDs start with '@'.
	 * Envelope IDs require a cached reverse lookup — we accept only ones
	 * we've seen before via {@see EvaultClient::getProfileEnvelopeId()}.
	 */
	private function resolveParticipantIdToW3id(string $id): ?string {
		if ($id === '') {
			return null;
		}
		if (str_starts_with($id, '@')) {
			return $id;
		}
		return $this->evaultClient->resolveW3idFromProfileEnvelopeId($id);
	}

	/**
	 * Resolve a W3ID to a Nextcloud UID, auto-provisioning if needed.
	 */
	private function resolveW3idToNcUid(string $w3id): ?string {
		try {
			$mapping = $this->w3dsMappingMapper->findByW3id($w3id);
			return $mapping->getNcUid();
		} catch (DoesNotExistException) {
			// Auto-provision a new Nextcloud user
			$user = $this->userProvisioning->findOrCreateUser($w3id);
			return $user?->getUID();
		}
	}

	// ---------------------------------------------------------------
	// Anti-ping-pong lock
	// ---------------------------------------------------------------

	private function setSyncLock(string $entityType, string $localId): void {
		$this->cache->set(self::SYNC_LOCK_PREFIX . $entityType . '_' . $localId, true, self::SYNC_LOCK_TTL);
	}

	private function isSyncLocked(string $entityType, string $localId): bool {
		return $this->cache->get(self::SYNC_LOCK_PREFIX . $entityType . '_' . $localId) !== null;
	}

	private function inboundPostKey(string $senderUid, string $roomToken): string {
		return self::INBOUND_POST_LOCK_PREFIX . md5($senderUid . '|' . $roomToken);
	}

	private function beginInboundPost(string $senderUid, string $roomToken): void {
		$this->cache->set($this->inboundPostKey($senderUid, $roomToken), true, self::INBOUND_POST_LOCK_TTL);
	}

	private function endInboundPost(string $senderUid, string $roomToken): void {
		$this->cache->remove($this->inboundPostKey($senderUid, $roomToken));
	}

	private function isInboundPostActive(string $senderUid, string $roomToken): bool {
		return $this->cache->get($this->inboundPostKey($senderUid, $roomToken)) !== null;
	}

	// ---------------------------------------------------------------
	// Utility
	// ---------------------------------------------------------------

	private function toIso8601(int|string $timestamp): string {
		if (is_string($timestamp)) {
			// Already ISO 8601 or similar
			if (!is_numeric($timestamp)) {
				return $timestamp;
			}
			$timestamp = (int)$timestamp;
		}
		return date('c', $timestamp);
	}

	private function generateUuid(): string {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			random_int(0, 0xffff), random_int(0, 0xffff),
			random_int(0, 0xffff),
			random_int(0, 0x0fff) | 0x4000,
			random_int(0, 0x3fff) | 0x8000,
			random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
		);
	}
}
