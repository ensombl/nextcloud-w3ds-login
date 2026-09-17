<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\Db\IdMappingMapper;
use Psr\Log\LoggerInterface;

/**
 * Applies one awareness packet, from either delivery route.
 *
 * Webhook push and cursor polling carry the same packets, so they must reach
 * Talk by the same path or the two routes will disagree with each other. This
 * is that path: route by ontology, deduplicate by event, hand to
 * {@see ChatSyncService}.
 */
class AwarenessPacketProcessor {
	/**
	 * Entity type for the record of an applied delivery.
	 *
	 * Distinct from the `message` mapping because the two answer different
	 * questions. The mapping says "this envelope is that Talk comment" and has
	 * to live as long as the comment does. This says "this delivery has been
	 * applied", which only matters for as long as the service might resend it.
	 */
	private const EVENT_ENTITY = 'awareness_event';

	public function __construct(
		private ChatSyncService $chatSyncService,
		private IdMappingMapper $idMappingMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Ontologies worth receiving.
	 *
	 * Delivery is a broadcast: without a filter we would be handed every
	 * packet written by every platform in the ecosystem. The file ontology is
	 * included because attachment uploads announce themselves there rather
	 * than as message envelopes.
	 *
	 * @return string[]
	 */
	public function ontologies(): array {
		return [
			ChatSyncService::CHAT_SCHEMA_ID,
			ChatSyncService::MESSAGE_SCHEMA_ID,
			AwarenessClient::FILE_ONTOLOGY,
		];
	}

	/**
	 * @param array<string, mixed> $packet
	 * @return bool Whether the packet resulted in local work. False covers
	 *              both "not for us" and "already applied", which the caller
	 *              only uses for logging.
	 */
	public function process(array $packet): bool {
		$ontology = AwarenessClient::ontologyOf($packet);
		$globalId = is_string($packet['id'] ?? null) ? $packet['id'] : '';
		$data = is_array($packet['data'] ?? null) ? $packet['data'] : [];
		$ownerW3id = is_string($packet['w3id'] ?? null) ? $packet['w3id'] : '';
		$operation = is_string($packet['operation'] ?? null) ? $packet['operation'] : 'create';

		if ($globalId === '' || $ontology === '') {
			return false;
		}

		// An ontology we hold no mapping for is not an error. A receiver is
		// required to accept those quietly; returning a failure would have the
		// service retry a packet that was never ours for 24 hours and then
		// dead-letter it.
		if (!in_array($ontology, $this->ontologies(), true)) {
			return false;
		}

		// A delete arrives as a tombstone with a null payload. Removing
		// someone's local message on a remote instruction is a decision with
		// real consequences and no way back, so it is deliberately not acted
		// on until the semantics are agreed.
		if ($operation === 'delete') {
			$this->logger->info('[W3DS Awareness] Ignoring delete tombstone', [
				'globalId' => $globalId,
				'ontology' => $ontology,
			]);

			return false;
		}

		if ($data === []) {
			return false;
		}

		// Delivery is at-least-once, so a packet can legitimately arrive
		// twice, and the webhook and the poll can both carry the same one.
		// Claiming the event id is what makes applying it exactly once; the
		// unique index decides the race rather than a read-then-write.
		$eventId = AwarenessClient::eventIdOf($packet);
		if ($eventId !== '' && !$this->claimEvent($eventId, $ownerW3id)) {
			return false;
		}

		try {
			match ($ontology) {
				ChatSyncService::CHAT_SCHEMA_ID => $this->chatSyncService->handleInboundChat($globalId, $ownerW3id, $data),
				ChatSyncService::MESSAGE_SCHEMA_ID => $this->chatSyncService->handleInboundMessage($globalId, $ownerW3id, $data),
				// Upload announcements carry no conversation of their own: the
				// message envelope that references the blob is what puts it in
				// a room. Acknowledged so the service stops resending it.
				default => null,
			};

			return true;
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Awareness] Failed to apply packet', [
				'globalId' => $globalId,
				'ontology' => $ontology,
				'exception' => $e,
			]);

			// Release the claim so a transient failure is retried on the next
			// delivery rather than being swallowed permanently.
			if ($eventId !== '') {
				$this->idMappingMapper->releaseClaim(self::EVENT_ENTITY, $eventId);
			}

			return false;
		}
	}

	/**
	 * @return bool True when this process is the one that should apply the
	 *              event; false when it has already been applied.
	 */
	private function claimEvent(string $eventId, string $ownerW3id): bool {
		return $this->idMappingMapper->tryClaim(self::EVENT_ENTITY, $eventId, $ownerW3id);
	}
}
