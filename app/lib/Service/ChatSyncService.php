<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Db\IdMappingMapper;
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
	private const CHAT_PARTICIPANT_HWM_PREFIX = 'w3ds_chat_pmax_';
	private const CHAT_PARTICIPANT_HWM_TTL = 604800; // 7d high-water mark guard against pushChat shrinkage
	private const PULL_LIST_CACHE_TTL = 120; // 2 min for chat/message ontology lists during pull sync

	private ICache $cache;

	/**
	 * Per-request coalesced chat-push queue. Each room maps to its latest
	 * {ncUid, roomData} pair; flushed once at request shutdown.
	 *
	 * @var array<string, array{ncUid: string, roomData: array}>
	 */
	private array $pendingChatPushes = [];
	private bool $shutdownRegistered = false;

	public function __construct(
		private EvaultClient $evaultClient,
		private IdMappingMapper $idMappingMapper,
		private UserProvisioningService $userProvisioning,
		private W3dsMappingMapper $w3dsMappingMapper,
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
	}

	/**
	 * Queue a chat push to run once at request shutdown, coalescing repeat
	 * calls for the same room. Talk fires RoomCreatedEvent + one
	 * AttendeesAddedEvent per participant within a single HTTP request, so
	 * pushing inline produces a CREATE + several UPDATEs and the eVault's
	 * webhook retry queue can deliver the original CREATE *after* the final
	 * UPDATE — the receiving side then reverts to the 1-participant state.
	 * Coalescing collapses the burst into a single push with the final
	 * roster so there's only one webhook to deliver.
	 */
	public function queueChatPush(string $ncUid, array $roomData): void {
		$localId = (string)($roomData['token'] ?? '');
		if ($localId === '') {
			return;
		}

		$this->pendingChatPushes[$localId] = ['ncUid' => $ncUid, 'roomData' => $roomData];

		if (!$this->shutdownRegistered) {
			$this->shutdownRegistered = true;
			register_shutdown_function(function (): void {
				$this->flushPendingChatPushes();
			});
		}
	}

	/**
	 * Drain the pending chat-push queue. Called from the shutdown handler
	 * registered by {@see queueChatPush()}; safe to call directly in tests.
	 */
	public function flushPendingChatPushes(): void {
		if (empty($this->pendingChatPushes)) {
			return;
		}

		// Release the HTTP response before doing eVault network I/O so the
		// Talk client doesn't wait on us. PHP-FPM only.
		if (function_exists('fastcgi_finish_request')) {
			@fastcgi_finish_request();
		}

		\ignore_user_abort(true);

		$pending = $this->pendingChatPushes;
		$this->pendingChatPushes = [];

		foreach ($pending as $localId => $task) {
			try {
				$this->pushChat($task['ncUid'], $task['roomData']);
			} catch (\Throwable $e) {
				$this->logger->error('[W3DS Sync] Deferred pushChat failed', [
					'localId' => $localId,
					'exception' => $e,
				]);
			}
		}
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
		// Caller trace: who triggered this push? We need this because chat
		// shrinkage bugs are otherwise impossible to source-trace from logs.
		$bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
		$callerChain = [];
		foreach (array_slice($bt, 1, 5) as $frame) {
			$callerChain[] = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
		}

		$this->logger->info('[W3DS Sync] pushChat ENTER', [
			'ncUid' => $ncUid,
			'localId' => (string)($roomData['token'] ?? ''),
			'roomDataParticipants' => $roomData['participants'] ?? null,
			'roomDataParticipantCount' => is_array($roomData['participants'] ?? null) ? count($roomData['participants']) : null,
			'callers' => $callerChain,
		]);

		$w3id = $this->userProvisioning->getLinkedW3id($ncUid);
		if ($w3id === null) {
			$this->logger->info('[W3DS Sync] pushChat skip: user not linked', ['ncUid' => $ncUid]);
			return; // User not linked to W3DS
		}

		$localId = (string)($roomData['token'] ?? '');
		if (empty($localId)) {
			$this->logger->info('[W3DS Sync] pushChat skip: empty localId');
			return;
		}

		// Anti-ping-pong: skip if this entity was just created from an inbound webhook
		if ($this->isSyncLocked('chat', $localId)) {
			$this->logger->info('[W3DS Sync] pushChat skip: sync lock active', ['localId' => $localId]);
			return;
		}

		// Read CURRENT participants + admins from the live Talk room.
		[$participantUids, $adminUids, $roomType, $roomName] = $this->readRoomState($localId, $roomData);

		$this->logger->info('[W3DS Sync] pushChat readRoomState result', [
			'localId' => $localId,
			'participantUids' => $participantUids,
			'participantCount' => count($participantUids),
			'adminUids' => $adminUids,
			'adminCount' => count($adminUids),
			'roomType' => $roomType,
			'roomName' => $roomName,
		]);

		$existingGlobalId = $this->idMappingMapper->getGlobalId('chat', $localId);

		// If the room read returned no users, the Talk read failed (a real
		// room always has at least the owner). Refuse to overwrite an existing
		// eVault chat with a degraded participant list — that would silently
		// drop everyone but the owner. Only allow CREATE on initial sync.
		if (empty($participantUids) && $existingGlobalId !== null) {
			$this->logger->warning('[W3DS Sync] Aborting chat update: empty local participant read would shrink eVault state', [
				'localId' => $localId,
				'globalId' => $existingGlobalId,
			]);
			return;
		}

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

		// On UPDATE, reuse the inner `id` from the existing envelope. Regenerating
		// it makes downstream consumers (eVault UI, awareness replication) treat
		// each push as a brand-new chat — that's why the chat appeared to lose
		// participants after every update.
		$existingInnerId = null;
		$existingCreatedAt = null;
		if ($existingGlobalId !== null) {
			$existing = $this->evaultClient->fetchMetaEnvelopeById($w3id, $existingGlobalId);
			$existingParsed = is_array($existing) ? ($existing['parsed'] ?? null) : null;
			if (is_array($existingParsed)) {
				$existingInnerId = is_string($existingParsed['id'] ?? null) ? $existingParsed['id'] : null;
				$existingCreatedAt = is_string($existingParsed['createdAt'] ?? null) ? $existingParsed['createdAt'] : null;
			}
		}

		// Minimum-common payload across platforms (matches the field set used
		// by blabsy etc.). Don't include `ename`, `owner`, or `type`: blabsy's
		// chat adapter (`participants: "ename||user(participants[])"`) routes
		// participant resolution through `ename` when set and crashes when
		// the eName-derived participants come back as null entries.
		$payload = [
			'participantIds' => $participantEnvelopeIds,
			'admins' => $adminEnvelopeIds,
			'createdAt' => $existingCreatedAt ?? $this->toIso8601($roomData['createdAt'] ?? time()),
			'updatedAt' => $this->toIso8601(time()),
		];

		// Preserve the inner id across updates when existing envelopes carry
		// one (some platforms store this). Don't generate a fresh one on
		// create; the eVault assigns the outer envelope id which is the
		// stable identity.
		if ($existingInnerId !== null) {
			$payload['id'] = $existingInnerId;
		}

		if (!empty($roomName)) {
			$payload['name'] = $roomName;
		}

		$lastMessageGlobalId = $this->idMappingMapper->getGlobalId('message', (string)($roomData['lastMessageId'] ?? ''));
		if ($lastMessageGlobalId !== null) {
			$payload['lastMessageId'] = $lastMessageGlobalId;
		}

		// ACL by W3ID (eVault access is keyed on W3IDs, not envelope IDs)
		$acl = !empty($participantW3ids) ? $participantW3ids : ['*'];

		$newCount = count($participantEnvelopeIds);

		$this->logger->info('[W3DS Sync] pushChat payload prepared', [
			'localId' => $localId,
			'localUidCount' => count($participantUids),
			'localUids' => $participantUids,
			'resolvedW3ids' => $participantW3ids,
			'envelopeIdCount' => $newCount,
			'envelopeIds' => $participantEnvelopeIds,
			'ownerW3id' => $w3id,
			'aclCount' => count($acl),
			'acl' => $acl,
			'isUpdate' => $existingGlobalId !== null,
		]);

		if ($existingGlobalId !== null) {
			// High-water mark guard: if we've ever pushed N participants for
			// this chat, refuse to push fewer. Catches transient Talk reads
			// that return only the current user instead of the full roster.
			$hwmKey = self::CHAT_PARTICIPANT_HWM_PREFIX . $localId;
			$hwm = $this->cache->get($hwmKey);
			if (is_int($hwm) && $newCount < $hwm) {
				$this->logger->warning('[W3DS Sync] Aborting chat update: participant count would shrink', [
					'localId' => $localId,
					'globalId' => $existingGlobalId,
					'newCount' => $newCount,
					'highWaterMark' => $hwm,
				]);
				return;
			}

			$this->evaultClient->updateMetaEnvelope($w3id, $existingGlobalId, self::CHAT_SCHEMA_ID, $payload, $acl);
			$this->cache->set($hwmKey, max((int)$hwm, $newCount), self::CHAT_PARTICIPANT_HWM_TTL);
			$this->logger->info('[W3DS Sync] Updated chat in eVault', [
				'localId' => $localId,
				'globalId' => $existingGlobalId,
				'participantCount' => $newCount,
				'adminCount' => count($adminEnvelopeIds),
			]);

			$readback = $this->evaultClient->fetchMetaEnvelopeById($w3id, $existingGlobalId);
			$rbParsed = is_array($readback) ? ($readback['parsed'] ?? null) : null;
			$rbParticipants = is_array($rbParsed) ? ($rbParsed['participantIds'] ?? null) : null;
			$this->logger->info('[W3DS Sync] pushChat readback after update', [
				'localId' => $localId,
				'globalId' => $existingGlobalId,
				'gotEnvelope' => $readback !== null,
				'rbParticipantIdsType' => gettype($rbParticipants),
				'rbParticipantIdsCount' => is_array($rbParticipants) ? count($rbParticipants) : null,
				'rbParticipantIds' => $rbParticipants,
				'rbParsed' => $rbParsed,
			]);
		} else {
			$globalId = $this->evaultClient->createMetaEnvelope($w3id, self::CHAT_SCHEMA_ID, $payload, $acl);
			if ($globalId !== null) {
				$this->idMappingMapper->storeMapping('chat', $localId, $globalId, $w3id);
				$this->cache->set(
					self::CHAT_PARTICIPANT_HWM_PREFIX . $localId,
					$newCount,
					self::CHAT_PARTICIPANT_HWM_TTL,
				);
				$this->logger->info('[W3DS Sync] Pushed new chat to eVault', [
					'localId' => $localId,
					'globalId' => $globalId,
					'participantCount' => $newCount,
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
			$this->logger->info('[W3DS Sync] readRoomState: Talk not available', ['roomToken' => $roomToken]);
			return [$participantUids, $adminUids, $roomType, $roomName];
		}

		$rawAttendees = [];
		try {
			$manager = \OCP\Server::get(\OCA\Talk\Manager::class);
			$room = $manager->getRoomByToken($roomToken);
			$roomType = $room->getType();
			$roomName = $room->getName();

			$participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
			$participants = $participantService->getParticipantsForRoom($room);
			$this->logger->info('[W3DS Sync] readRoomState: getParticipantsForRoom returned', [
				'roomToken' => $roomToken,
				'totalParticipants' => count($participants),
			]);
			foreach ($participants as $p) {
				$attendee = $p->getAttendee();
				$rawAttendees[] = [
					'actorType' => $attendee->getActorType(),
					'actorId' => $attendee->getActorId(),
					'participantType' => $attendee->getParticipantType(),
				];
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
			$this->logger->info('[W3DS Sync] readRoomState: enumerated attendees', [
				'roomToken' => $roomToken,
				'rawAttendees' => $rawAttendees,
				'userParticipantUids' => $participantUids,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Sync] readRoomState THREW (returning fallback)', [
				'roomToken' => $roomToken,
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString(),
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

		// Match the field set used by other platforms (>99% of message
		// envelopes carry these). Inner `id` is omitted; the eVault assigns
		// the outer envelope id which is the stable identity.
		$now = $this->toIso8601(time());
		$payload = [
			'chatId' => $chatGlobalId,
			'senderId' => $senderEnvelopeId,
			'senderEName' => $w3id,
			'content' => (string)($messageData['message'] ?? ''),
			'type' => $this->mapMessageVerbToGlobal($messageData['verb'] ?? 'comment'),
			'createdAt' => $this->toIso8601($messageData['timestamp'] ?? time()),
			'updatedAt' => $now,
			'isArchived' => false,
			'isSystemMessage' => false,
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

		// Prefer senderEName (other-platform canonical), fall back to senderId
		// which we ourselves write as a profile envelope ID, or accept a raw W3ID.
		$senderEName = is_string($data['senderEName'] ?? null) ? $data['senderEName'] : '';
		$senderW3id = $senderEName !== ''
			? $senderEName
			: $this->resolveParticipantIdToW3id((string)($data['senderId'] ?? ''));
		if ($senderW3id === null || $senderW3id === '') {
			$this->logger->warning('[W3DS Sync] Cannot resolve sender ID to W3ID', [
				'senderEName' => $senderEName,
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
	 * Pull sync all chats and messages for a given user from their own eVault.
	 *
	 * Uses the REST `/metaenvelopes/by-ontology/:ontology` endpoint to list
	 * everything of a given ontology and filters client-side by membership:
	 *   - rooms where the user's profile envelope ID appears in
	 *     `participantIds`, `admins`, or `owner`
	 *   - messages whose `chatId` resolves to one of those accepted rooms
	 */
	public function pullSyncForUser(string $w3id): void {
		$myProfileId = $this->evaultClient->getProfileEnvelopeId($w3id);
		if ($myProfileId === null) {
			$this->logger->info('[W3DS Sync] pullSyncForUser skip: no profile envelope yet', ['w3id' => $w3id]);
			return;
		}

		// 1. Rooms — list, filter by membership (the user's profile envelope
		// ID must appear in the chat's participantIds / admins / owner --
		// same identifier we put there when *we* push chats), ingest.
		$acceptedChatGlobalIds = [];
		try {
			$chatEnvelopes = $this->evaultClient->listMetaEnvelopesByOntology($w3id, self::CHAT_SCHEMA_ID, self::PULL_LIST_CACHE_TTL);
			foreach ($chatEnvelopes as $env) {
				try {
					$globalId = (string)($env['id'] ?? '');
					$parsed = $env['parsed'] ?? [];
					if ($globalId === '' || !is_array($parsed) || empty($parsed)) {
						continue;
					}
					if (!$this->userIsInRoom($parsed, $myProfileId)) {
						continue;
					}
					$acceptedChatGlobalIds[$globalId] = true;
					if ($this->idMappingMapper->getLocalId('chat', $globalId) !== null) {
						continue;
					}
					$this->handleInboundChat($globalId, $w3id, $parsed);
				} catch (\Throwable $e) {
					$this->logger->warning('[W3DS Sync] pullSyncForUser: chat envelope failed', [
						'w3id' => $w3id,
						'globalId' => $env['id'] ?? null,
						'exception' => $e->getMessage(),
					]);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Sync] pullSyncForUser: chat list failed', [
				'w3id' => $w3id,
				'exception' => $e->getMessage(),
			]);
		}

		// 2. Messages — list, filter to messages whose chatId is in the accepted room set.
		try {
			$messageEnvelopes = $this->evaultClient->listMetaEnvelopesByOntology($w3id, self::MESSAGE_SCHEMA_ID, self::PULL_LIST_CACHE_TTL);
			foreach ($messageEnvelopes as $env) {
				try {
					$globalId = (string)($env['id'] ?? '');
					$parsed = $env['parsed'] ?? [];
					if ($globalId === '' || !is_array($parsed) || empty($parsed)) {
						continue;
					}
					$chatId = (string)($parsed['chatId'] ?? '');
					if ($chatId === '' || !isset($acceptedChatGlobalIds[$chatId])) {
						continue;
					}
					if ($this->idMappingMapper->getLocalId('message', $globalId) !== null) {
						continue;
					}
					$this->handleInboundMessage($globalId, $w3id, $parsed);
				} catch (\Throwable $e) {
					$this->logger->warning('[W3DS Sync] pullSyncForUser: message envelope failed', [
						'w3id' => $w3id,
						'globalId' => $env['id'] ?? null,
						'exception' => $e->getMessage(),
					]);
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Sync] pullSyncForUser: message list failed', [
				'w3id' => $w3id,
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * True when the user's profile envelope ID appears in the chat's
	 * `owner`, `participantIds`, or `admins` -- same identifier shape we
	 * write when pushing chats outbound.
	 */
	private function userIsInRoom(array $parsed, string $myProfileId): bool {
		if (($parsed['owner'] ?? null) === $myProfileId) {
			return true;
		}
		foreach (['participantIds', 'admins'] as $key) {
			$arr = $parsed[$key] ?? null;
			if (is_array($arr) && in_array($myProfileId, $arr, true)) {
				return true;
			}
		}
		return false;
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
