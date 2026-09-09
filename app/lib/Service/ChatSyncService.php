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
	private const POLL_PAGE_SIZE = 50; // per-page size when walking a chat's messages
	private const POLL_MAX_PAGES = 20; // per-participant page budget for one poll (~1000 messages)
	private const MAX_CLOCK_SKEW = 300; // 5 min tolerance for a future-dated inbound createdAt

	private ICache $cache;

	/**
	 * Per-request coalesced chat-push queue. Each room maps to its latest
	 * {ncUid, roomData} pair; flushed once at request shutdown.
	 *
	 * @var array<string, array{ncUid: string, roomData: array}>
	 */
	private array $pendingChatPushes = [];
	private bool $shutdownRegistered = false;

	/**
	 * Set of viewer W3IDs for whom we've already re-primed the profile cache
	 * during the current request. Bounds the cost of cold-cache participant
	 * resolution: at most one extra by-ontology list call per viewer per
	 * request, regardless of how many envelopes reference unknown peers.
	 *
	 * @var array<string, true>
	 */
	private array $primedViewers = [];

	public function __construct(
		private EvaultClient $evaultClient,
		private IdMappingMapper $idMappingMapper,
		private UserProvisioningService $userProvisioning,
		private W3dsMappingMapper $w3dsMappingMapper,
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private MentionTranslator $mentionTranslator,
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

		// Long-lived inbound-mirror gate. The 10s sync lock above only covers
		// the request that ingested the envelope; subsequent listener fires
		// (e.g. when the first replicated message lands and Talk re-touches
		// the room) would otherwise push the inbound chat back to its peer.
		// The `origin` column on the mapping survives request boundaries.
		if ($this->idMappingMapper->getOrigin('chat', $localId) === 'inbound') {
			$this->logger->info('[W3DS Sync] pushChat skip: inbound mirror', ['localId' => $localId]);
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

		// Resolve each participant/admin NC UID → W3ID. eNames are the
		// identity we track internally: stable, self-describing, and
		// resolvable without a profile envelope.
		$participantW3ids = $this->resolveParticipantW3ids($participantUids);
		$adminW3ids = $this->resolveParticipantW3ids($adminUids);

		// Always include the owner themselves in both participant lists and
		// (if they're not already there) in admins -- the creator of a
		// Talk room is its OWNER level.
		if (!in_array($w3id, $participantW3ids, true)) {
			$participantW3ids[] = $w3id;
		}
		if (!in_array($w3id, $adminW3ids, true)) {
			$adminW3ids[] = $w3id;
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

		// Entity references: the User profile envelope ID where we can get
		// one, the eName otherwise. Both shapes are readable by eVault's
		// membership resolution and by consumers going through the
		// web3-adapter, so nobody is dropped for want of a profile envelope.
		$participantRefs = $this->resolveParticipantReferences($participantW3ids);
		$adminRefs = $this->resolveParticipantReferences($adminW3ids);

		// Minimum-common payload across platforms (matches the field set used
		// by blabsy etc.). Don't include `ename`, `owner`, or `type`: blabsy's
		// chat adapter (`participants: "ename||user(participants[])"`) routes
		// participant resolution through `ename` when set and crashes when
		// the eName-derived participants come back as null entries.
		$payload = [
			'participantIds' => $participantRefs,
			'admins' => $adminRefs,
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

		$newCount = count($participantW3ids);

		$this->logger->info('[W3DS Sync] pushChat payload prepared', [
			'localId' => $localId,
			'localUidCount' => count($participantUids),
			'localUids' => $participantUids,
			'resolvedW3ids' => $participantW3ids,
			'participantIdCount' => count($participantRefs),
			'participantIds' => $participantRefs,
			'referencedByENameCount' => count(array_filter(
				$participantRefs,
				static fn (string $ref): bool => str_starts_with($ref, '@'),
			)),
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
				'adminCount' => count($adminW3ids),
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
					'adminCount' => count($adminW3ids),
				]);

				$this->fanOutReference($globalId, $w3id, $participantW3ids);
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

		// Prefer the sender's profile envelope ID, falling back to the eName:
		// senderId is a scalar, so omitting it would leave the message
		// unattributed entirely, and senderEName carries the same value.
		$senderId = $this->evaultClient->getProfileEnvelopeId($w3id) ?? $w3id;

		// Match the field set used by other platforms (>99% of message
		// envelopes carry these). The inner `id` is a stable per-message
		// identity derived from (chat, sender, local comment id): the outer
		// envelope id differs in every participant's replica, so consumers
		// deduplicating across replicas need something that does not. It is
		// derived rather than random so a re-push of the same comment keeps
		// the same value.
		$now = $this->toIso8601(time());
		$payload = [
			'id' => $this->deriveMessageInnerId($chatGlobalId, $w3id, $localId),
			'chatId' => $chatGlobalId,
			// senderId is the User profile envelope ID, the reference peer
			// platforms dereference; senderEName is the same person as an
			// eName, for consumers that prefer the stable identifier.
			'senderId' => $senderId,
			'senderEName' => $w3id,
			'content' => $this->mentionTranslator->toWire((string)($messageData['message'] ?? '')),
			'type' => $this->mapMessageVerbToGlobal($messageData['verb'] ?? 'comment'),
			'createdAt' => $this->toIso8601($messageData['timestamp'] ?? time()),
			'updatedAt' => $now,
			'isArchived' => false,
			'isSystemMessage' => false,
		];

		$acl = [$w3id]; // At minimum, sender can access

		// Everyone in the room needs to be able to read the message, and
		// needs a pointer to it in their own vault. Resolved from the live
		// room rather than the payload: the payload carries envelope IDs,
		// and references are addressed by eName.
		[$participantUids] = $this->readRoomState($roomToken, []);
		$participantW3ids = $this->resolveParticipantW3ids($participantUids);
		if (!in_array($w3id, $participantW3ids, true)) {
			$participantW3ids[] = $w3id;
		}
		if (count($participantW3ids) > 1) {
			$acl = $participantW3ids;
		}

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

				$this->fanOutReference($globalId, $w3id, $participantW3ids);
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

		// Participants are named either by User profile envelope ID or by
		// eName; resolveParticipantIdToW3id() handles both shapes.
		//
		// Peers we cannot resolve are simply not represented locally; that must
		// not sink the whole room. A group chat whose members mostly live on
		// other platforms is still a room this user belongs to, so the viewer
		// (the eVault owner we are reading from) is always a participant.
		$participantIds = $data['participantIds'] ?? [];
		$references = is_array($participantIds) ? $participantIds : [];
		$participantUids = [];
		$seenW3ids = [];
		$unresolved = 0;
		foreach ($references as $pid) {
			$w3id = $this->resolveParticipantIdToW3id((string)$pid, $ownerW3id);
			if ($w3id === null) {
				$unresolved++;
				continue;
			}
			// The same person can be named by both shapes; count them once.
			if (isset($seenW3ids[$w3id])) {
				continue;
			}
			$seenW3ids[$w3id] = true;
			$uid = $this->resolveW3idToNcUid($w3id);
			if ($uid !== null) {
				$participantUids[] = $uid;
			} else {
				$unresolved++;
			}
		}

		// Guarantee the viewer's own membership. Without this, a room whose
		// participantIds carry only unresolvable off-platform peers is dropped
		// entirely and the user never sees the conversation.
		$viewerUid = $this->resolveW3idToNcUid($ownerW3id);
		if ($viewerUid !== null && !in_array($viewerUid, $participantUids, true)) {
			$participantUids[] = $viewerUid;
		}

		$participantUids = array_values(array_unique($participantUids));

		if (empty($participantUids)) {
			$this->logger->warning('[W3DS Sync] No resolvable participants for inbound chat', [
				'globalId' => $globalId,
				'ownerW3id' => $ownerW3id,
				'participantIdCount' => count($references),
			]);
			return;
		}

		if ($unresolved > 0) {
			$this->logger->info('[W3DS Sync] Inbound chat has participants not resolvable locally', [
				'globalId' => $globalId,
				'resolvedCount' => count($participantUids),
				'unresolvedCount' => $unresolved,
			]);
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

			// Set the sync lock to prevent outbound re-push (covers listeners
			// that fire synchronously inside createTalkRoom). The `inbound`
			// origin on the mapping below is the durable, cross-request
			// version of the same guard.
			$this->setSyncLock('chat', $roomToken);

			$this->idMappingMapper->storeMapping('chat', $roomToken, $globalId, $ownerW3id, 'inbound');
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

		// Prefer senderEName, fall back to senderId, which we now write as an
		// eName but which may be a profile envelope ID in older envelopes.
		$senderEName = is_string($data['senderEName'] ?? null) ? $data['senderEName'] : '';
		$senderW3id = $senderEName !== ''
			? $senderEName
			: $this->resolveParticipantIdToW3id((string)($data['senderId'] ?? ''), $ownerW3id);
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

		// Translate eName mentions back into Talk's `@"uid"` tokens. Talk
		// derives both the rendered highlight and the mention *notification*
		// from these tokens, so an untranslated eName means the mentioned
		// user is never notified.
		//
		// Dedup below uses the raw wire content, not this rewritten form:
		// each participant's replica must produce the same signature, and the
		// local translation result depends on who is known to this instance.
		$rawContent = (string)($data['content'] ?? '');
		$content = $this->mentionTranslator->toTalk($rawContent);
		$messageType = $data['type'] ?? 'text';

		// Cross-replica dedup: the same logical message lives in every
		// participant's eVault under a *different* global_id, so the outer
		// envelope ID cannot identify it. We need something stable across
		// replicas.
		//
		// Prefer the sender-assigned inner `id` when the envelope carries one;
		// that is a genuine per-message identity and replicates unchanged.
		// Otherwise fall back to a content signature — but include createdAt,
		// which the previous content-only signature omitted. Without it, any
		// repeat of an identical string by the same sender in the same room
		// within the cache TTL was dropped and never posted: "ok", "yes",
		// "+1", a re-sent link. Replicas of one logical message can carry
		// different createdAt values (each platform stamps on replication), so
		// this trades a rarer duplicate for no longer losing real messages.
		$signature = $this->messageIdentitySignature($senderUid, $chatGlobalId, $data, $rawContent);
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
			$localMessageId = $this->postTalkMessage(
				$roomToken,
				$senderUid,
				$content,
				$messageType,
				is_string($data['createdAt'] ?? null) ? $data['createdAt'] : null,
			);
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

	/**
	 * Derive a deterministic UUIDv5-shaped inner `id` for a message from
	 * (chat, sender, local comment id). Deterministic so re-pushing the same
	 * Talk comment produces the same identity rather than a fresh one.
	 */
	private function deriveMessageInnerId(string $chatGlobalId, string $senderW3id, string $localId): string {
		$hash = md5($chatGlobalId . '|' . $senderW3id . '|' . $localId);

		return sprintf(
			'%s-%s-5%s-%04x-%s',
			substr($hash, 0, 8),
			substr($hash, 8, 4),
			substr($hash, 13, 3),
			(hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
			substr($hash, 20, 12),
		);
	}

	/**
	 * Build the cross-replica identity key for an inbound message.
	 *
	 * The outer envelope ID differs per replica, so it cannot be used. Prefer
	 * the sender-assigned inner `id` when present — that is a real per-message
	 * identity that survives replication. Fall back to
	 * (sender, chat, content, createdAt), which distinguishes a legitimately
	 * repeated message from a replica of the same one.
	 */
	private function messageIdentitySignature(
		string $senderUid,
		string $chatGlobalId,
		array $data,
		string $content,
	): string {
		$innerId = $data['id'] ?? null;
		if (is_string($innerId) && $innerId !== '') {
			return md5('id|' . $chatGlobalId . '|' . $innerId);
		}

		$createdAt = $data['createdAt'] ?? '';
		$createdAt = is_string($createdAt) ? $createdAt : '';

		return md5($senderUid . '|' . $chatGlobalId . '|' . $content . '|' . $createdAt);
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
				// Fetch Message envelopes for this chat from the participant's
				// eVault, following the cursor to the end.
				//
				// This used to request a single fixed page of 50 with no
				// cursor and no ordering. The eVault does not promise
				// newest-first, so in any room with more than a page of
				// messages per participant the newest ones could sit beyond
				// the first page and never be seen — messages sent today
				// missing while older ones synced fine. Walking the pages
				// removes the dependency on result ordering entirely.
				$after = null;
				$pages = 0;

				do {
					$result = $this->evaultClient->fetchMetaEnvelopes(
						$w3id,
						self::MESSAGE_SCHEMA_ID,
						self::POLL_PAGE_SIZE,
						$after,
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

					$pageInfo = $result['pageInfo'] ?? [];
					$after = ($pageInfo['hasNextPage'] ?? false) === true
						? ($pageInfo['endCursor'] ?? null)
						: null;
					$pages++;

					// Bound the work per poll so one very deep room cannot
					// monopolise a cron run. The next poll resumes from the
					// start and skips already-mapped messages cheaply.
					if ($pages >= self::POLL_MAX_PAGES && $after !== null) {
						$this->logger->info('[W3DS Sync] pollRoom: page budget reached, deferring rest', [
							'roomToken' => $roomToken,
							'w3id' => $w3id,
							'pages' => $pages,
						]);
						break;
					}
				} while ($after !== null);
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
	 *   - rooms where the user's profile envelope ID or eName appears in
	 *     `participantIds`, `admins`, or `owner`
	 *   - messages whose `chatId` resolves to one of those accepted rooms
	 */
	public function pullSyncForUser(string $w3id): void {
		// A missing profile envelope is not fatal. We also write eNames, so
		// chats involving this user are identifiable by eName alone; bailing
		// out here would deny pull sync to every user without a profile
		// envelope, which is precisely the bootstrap population.
		$myProfileId = $this->evaultClient->getProfileEnvelopeId($w3id);
		if ($myProfileId === null) {
			$this->logger->info('[W3DS Sync] pullSyncForUser: no profile envelope yet, matching on eName only', ['w3id' => $w3id]);
			$myProfileId = $w3id;
		}

		// 1. Rooms — list, filter by membership (the user's profile envelope
		// ID or eName must appear in the chat's participant / admin / owner
		// fields), ingest.
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
					if (!$this->userIsInRoom($parsed, $myProfileId, $w3id)) {
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

		// 2. Messages — fan out per accepted chat. Listing the message
		// ontology on $w3id's own eVault only surfaces messages *they*
		// authored; everyone else's messages live in their own eVaults
		// and are only reachable via pollRoom, which iterates each Talk
		// attendee's W3ID and pulls from there.
		foreach (array_keys($acceptedChatGlobalIds) as $chatGlobalId) {
			$roomToken = $this->idMappingMapper->getLocalId('chat', $chatGlobalId);
			if ($roomToken === null) {
				continue; // chat ingest failed above
			}
			try {
				$this->pollRoom($roomToken);
			} catch (\Throwable $e) {
				$this->logger->warning('[W3DS Sync] pullSyncForUser: pollRoom failed', [
					'w3id' => $w3id,
					'chatGlobalId' => $chatGlobalId,
					'roomToken' => $roomToken,
					'exception' => $e->getMessage(),
				]);
			}
		}
	}

	/**
	 * True when the viewer appears in the chat's `owner`, `participantIds`,
	 * or `admins`.
	 *
	 * Those fields name a person either by User profile envelope ID or by
	 * eName, and we write both shapes ourselves depending on what resolves.
	 * Matching on a single shape drops rooms silently — the envelope
	 * replicates fine and is then filtered out here — so accept either.
	 */
	private function userIsInRoom(array $parsed, string $myProfileId, string $myW3id = ''): bool {
		$identities = [$myProfileId];
		if ($myW3id !== '' && $myW3id !== $myProfileId) {
			$identities[] = $myW3id;
		}

		$owner = $parsed['owner'] ?? null;
		if (is_string($owner) && in_array($owner, $identities, true)) {
			return true;
		}

		foreach (['participantIds', 'admins'] as $key) {
			$arr = $parsed[$key] ?? null;
			if (!is_array($arr)) {
				continue;
			}
			foreach ($arr as $entry) {
				if (!is_string($entry)) {
					continue;
				}
				if (in_array($entry, $identities, true)) {
					return true;
				}
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
	private function postTalkMessage(
		string $roomToken,
		string $senderUid,
		string $content,
		string $messageType,
		?string $createdAt = null,
	): ?string {
		try {
			$manager = \OCP\Server::get(\OCA\Talk\Manager::class);
			$room = $manager->getRoomByToken($roomToken);

			$chatManager = \OCP\Server::get(\OCA\Talk\Chat\ChatManager::class);

			// Stamp the message with the time the sender actually sent it.
			// Using "now" instead makes every backfilled message look like it
			// arrived at ingest time, so a poll that catches up on yesterday's
			// conversation renders the whole of it as today.
			$creationDateTime = $this->parseCreatedAt($createdAt);
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
	 * Give every other participant a pointer to an envelope we just wrote to
	 * the owner's eVault.
	 *
	 * An envelope lives in exactly one vault, but platforms discover entities
	 * by listing an ontology on *their own user's* vault. Without a pointer
	 * there, a chat or message created here is invisible to every other
	 * participant's platform no matter how the ACL is set: they never ask the
	 * owner's vault, because they have no reason to know it holds anything.
	 * This mirrors what the reference web3-adapter does after a create.
	 *
	 * Best effort, and never fatal: the envelope itself is already stored, so
	 * a participant whose vault is unreachable costs them visibility of this
	 * one entity rather than failing the push for everyone.
	 *
	 * @param string[] $participantW3ids Every participant, owner included.
	 */
	private function fanOutReference(string $globalId, string $ownerW3id, array $participantW3ids): void {
		$others = array_values(array_filter(
			$participantW3ids,
			static fn (string $w3id): bool => $w3id !== $ownerW3id,
		));
		if (empty($others)) {
			return;
		}

		$reference = $ownerW3id . '/' . $globalId;
		$delivered = 0;
		foreach ($others as $target) {
			if ($this->evaultClient->storeReference($reference, $target)) {
				$delivered++;
			}
		}

		$this->logger->info('[W3DS Sync] Fanned out envelope reference to participants', [
			'globalId' => $globalId,
			'ownerW3id' => $ownerW3id,
			'delivered' => $delivered,
			'targets' => count($others),
		]);
	}

	/**
	 * Resolve an array of NC UIDs to the eNames of their linked W3IDs.
	 * Unlinked users are skipped.
	 *
	 * @return string[]
	 */
	private function resolveParticipantW3ids(array $ncUids): array {
		$w3ids = [];
		foreach ($ncUids as $uid) {
			$w3id = $this->userProvisioning->getLinkedW3id($uid);
			if ($w3id === null || in_array($w3id, $w3ids, true)) {
				continue;
			}
			$w3ids[] = $w3id;
		}
		return $w3ids;
	}

	/**
	 * Map eNames to the references that go into `participantIds` / `admins`.
	 *
	 * Prefer the User profile envelope ID: that is what consumers resolve
	 * through the web3-adapter, which looks each entry up in its own id
	 * mapping and hands the platform a `users(<localId>)` reference.
	 *
	 * Where no envelope resolves, fall back to the eName rather than dropping
	 * the participant. eVault's own membership resolution
	 * (GroupMembershipService) reads either shape out of these fields, so an
	 * eName here still grants group-derived access, whereas an omitted
	 * participant loses it. Consumers that cannot resolve the reference
	 * degrade the same way they already do for any unmapped id.
	 *
	 * @param string[] $w3ids
	 * @return string[] Envelope IDs where resolvable, eNames otherwise.
	 */
	private function resolveParticipantReferences(array $w3ids): array {
		$refs = [];
		foreach ($w3ids as $w3id) {
			$envelopeId = $this->evaultClient->getProfileEnvelopeId($w3id);

			if ($envelopeId === null) {
				$this->logger->info('[W3DS Sync] No profile envelope for participant; referencing by eName', [
					'w3id' => $w3id,
				]);
			}

			$ref = $envelopeId ?? $w3id;
			if (!in_array($ref, $refs, true)) {
				$refs[] = $ref;
			}
		}
		return $refs;
	}

	/**
	 * Resolve a participant identifier (may be raw W3ID or a User profile
	 * envelope UUID) back to a W3ID. Raw W3IDs start with '@'.
	 * Envelope IDs require a cached reverse lookup — we accept only ones
	 * we've seen before via {@see EvaultClient::getProfileEnvelopeId()}.
	 *
	 * On a cache miss for an envelope-shaped id, refresh the User-ontology
	 * listing on the *viewer's* eVault (the one we're reading the chat from)
	 * once per request — that primes the bidirectional cache for every peer
	 * visible to that viewer, which is enough to recover newly-replicated
	 * profiles that hadn't been listed at the time of the first call.
	 */
	private function resolveParticipantIdToW3id(string $id, string $viewerW3id = ''): ?string {
		if ($id === '') {
			return null;
		}
		if (str_starts_with($id, '@')) {
			return $id;
		}
		$resolved = $this->evaultClient->resolveW3idFromProfileEnvelopeId($id);
		if ($resolved !== null) {
			return $resolved;
		}
		if ($viewerW3id === '' || isset($this->primedViewers[$viewerW3id])) {
			return null;
		}
		$this->primedViewers[$viewerW3id] = true;
		$this->evaultClient->primeProfileCache($viewerW3id);
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

	/**
	 * Interpret an envelope's `createdAt` as the message's send time.
	 *
	 * Returns the current time when the value is missing, unparseable, or not
	 * plausibly a send time: an empty stamp must not push a message to the
	 * epoch, where it would sort to the very top of the room forever. Future
	 * stamps are clamped to now for the same reason, in the other direction,
	 * but only past a tolerance: a peer whose clock runs a little fast is
	 * ordinary, not garbage, so stamps within MAX_CLOCK_SKEW of now are kept
	 * as sent. Accepts ISO 8601 as written by pushMessage() and bare Unix
	 * seconds, since other platforms write both.
	 */
	private function parseCreatedAt(?string $createdAt): \DateTime {
		$now = new \DateTime();
		if ($createdAt === null || trim($createdAt) === '') {
			return $now;
		}

		$createdAt = trim($createdAt);

		try {
			$parsed = ctype_digit($createdAt)
				? (new \DateTime())->setTimestamp((int)$createdAt)
				: new \DateTime($createdAt);
		} catch (\Throwable) {
			return $now;
		}

		$timestamp = $parsed->getTimestamp();
		if ($timestamp <= 0 || $timestamp > $now->getTimestamp() + self::MAX_CLOCK_SKEW) {
			return $now;
		}

		return $parsed;
	}

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
