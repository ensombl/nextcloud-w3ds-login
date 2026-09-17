<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\IdMappingMapper;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\AwarenessPacketProcessor;
use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Applying awareness packets.
 *
 * Packets reach this class from two routes -- pushed to the webhook, and read
 * by the polling job -- so the same event legitimately arrives twice, and the
 * protocol makes at-least-once delivery explicit on top of that. Applying one
 * twice means posting the same message into a conversation twice, which is the
 * failure this whole architecture exists to stop.
 */
class AwarenessPacketProcessorTest extends TestCase {
	private const MESSAGE_ONTOLOGY = ChatSyncService::MESSAGE_SCHEMA_ID;

	/**
	 * An in-memory stand-in for the claim table, honouring the same unique
	 * index the real dedup relies on: a given key can be claimed once.
	 *
	 * @param list<string> $claimed Receives the keys that were claimed.
	 */
	private function mapper(array &$claimed): IdMappingMapper {
		$mapper = $this->createMock(IdMappingMapper::class);

		$mapper->method('tryClaim')->willReturnCallback(
			static function (string $type, string $key) use (&$claimed): bool {
				if (in_array($key, $claimed, true)) {
					return false;
				}
				$claimed[] = $key;

				return true;
			},
		);

		$mapper->method('releaseClaim')->willReturnCallback(
			static function (string $type, string $key) use (&$claimed): void {
				$claimed = array_values(array_filter($claimed, static fn (string $k): bool => $k !== $key));
			},
		);

		return $mapper;
	}

	/**
	 * @param list<string> $claimed
	 */
	private function processor(ChatSyncService $sync, array &$claimed): AwarenessPacketProcessor {
		return new AwarenessPacketProcessor($sync, $this->mapper($claimed), new NullLogger());
	}

	/**
	 * @return array<string, mixed>
	 */
	private function packet(array $overrides = []): array {
		return array_merge([
			'eventId' => 'event-1',
			'id' => 'envelope-1',
			'ontology' => self::MESSAGE_ONTOLOGY,
			'w3id' => '@alice',
			'operation' => 'create',
			'data' => ['content' => 'hello', 'chatId' => 'chat-1'],
		], $overrides);
	}

	public function testAMessagePacketIsHandedToTheSyncService(): void {
		$claimed = [];
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->once())
			->method('handleInboundMessage')
			->with('envelope-1', '@alice', ['content' => 'hello', 'chatId' => 'chat-1']);

		$this->assertTrue($this->processor($sync, $claimed)->process($this->packet()));
	}

	/**
	 * Delivery is at-least-once by design, so the service can resend an event
	 * after a timeout or a crash, and the webhook and the poll routinely carry
	 * the same one. Applying it twice posts the message twice.
	 */
	public function testTheSameEventIsAppliedOnlyOnce(): void {
		$claimed = [];
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->once())->method('handleInboundMessage');

		$processor = $this->processor($sync, $claimed);

		$this->assertTrue($processor->process($this->packet()));
		$this->assertFalse($processor->process($this->packet()));
	}

	/**
	 * A create and its later edits share one MetaEnvelope id, so treating that
	 * id as the identity of a delivery would discard every edit. The event id
	 * is what distinguishes them.
	 */
	public function testAnEditOfTheSameEnvelopeIsStillApplied(): void {
		$claimed = [];
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->exactly(2))->method('handleInboundMessage');

		$processor = $this->processor($sync, $claimed);

		$processor->process($this->packet());
		$this->assertTrue($processor->process($this->packet([
			'eventId' => 'event-2',
			'operation' => 'update',
			'data' => ['content' => 'hello, edited', 'chatId' => 'chat-1'],
		])));
	}

	/**
	 * The polling API names the schema `ontology`; webhook deliveries and the
	 * protocol documentation call the same field `schemaId`. A packet that
	 * only carries the other spelling must not be dropped as unrecognised.
	 */
	public function testEitherSpellingOfTheOntologyFieldIsUnderstood(): void {
		$claimed = [];
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->once())->method('handleInboundMessage');

		$packet = $this->packet();
		unset($packet['ontology']);
		$packet['schemaId'] = self::MESSAGE_ONTOLOGY;

		$this->assertTrue($this->processor($sync, $claimed)->process($packet));
	}

	/**
	 * Delivery is a broadcast: a subscriber is handed packets for ontologies
	 * it has no mapping for. Those must be accepted quietly -- reporting a
	 * failure has the service retry for 24 hours and then dead-letter a packet
	 * that was never ours.
	 */
	public function testAnUnknownOntologyIsIgnoredWithoutError(): void {
		$claimed = [];
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->never())->method('handleInboundMessage');
		$sync->expects($this->never())->method('handleInboundChat');

		$this->assertFalse($this->processor($sync, $claimed)->process(
			$this->packet(['ontology' => 'some-other-platform-type']),
		));
	}

	/**
	 * Deleting somebody's message locally because a remote packet said so is
	 * irreversible and easy to abuse, so a tombstone is recorded and not acted
	 * on until the semantics are agreed.
	 */
	public function testADeleteTombstoneIsNotActedOn(): void {
		$claimed = [];
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->never())->method('handleInboundMessage');

		$this->assertFalse($this->processor($sync, $claimed)->process(
			$this->packet(['operation' => 'delete', 'data' => null]),
		));
	}

	/**
	 * A failure has to leave the event unclaimed, or one transient error
	 * (an unreachable eVault, say) would permanently discard the message: the
	 * redelivery that exists precisely for this case would be dropped as
	 * already applied.
	 */
	public function testAFailedPacketCanBeRetriedOnRedelivery(): void {
		$claimed = [];
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->exactly(2))
			->method('handleInboundMessage')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new \RuntimeException('eVault unreachable')),
				null,
			);

		$processor = $this->processor($sync, $claimed);

		$this->assertFalse($processor->process($this->packet()));
		$this->assertTrue($processor->process($this->packet()));
	}

	/**
	 * Attachment uploads announce themselves on their own ontology. We
	 * subscribe to it so the service does not treat the packet as undelivered,
	 * but the message envelope referencing the blob is what puts it in a room.
	 */
	public function testTheFileOntologyIsSubscribedButNeedsNoLocalWork(): void {
		$claimed = [];
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->never())->method('handleInboundMessage');

		$processor = $this->processor($sync, $claimed);

		$this->assertContains(AwarenessClient::FILE_ONTOLOGY, $processor->ontologies());
		$this->assertTrue($processor->process($this->packet([
			'ontology' => AwarenessClient::FILE_ONTOLOGY,
			'data' => ['filename' => 'photo.png'],
		])));
	}

	/**
	 * Packets backfilled from before the service existed carry a synthetic
	 * `legacy-packet:<uuid>` event id rather than a real one. It is still a
	 * usable key, and treating it as malformed would reprocess history.
	 */
	public function testASyntheticLegacyEventIdStillDeduplicates(): void {
		$claimed = [];
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->once())->method('handleInboundMessage');

		$processor = $this->processor($sync, $claimed);
		$legacy = $this->packet(['eventId' => 'legacy-packet:00000476-d494-5a41-9e62-b0147161c72d']);

		$this->assertTrue($processor->process($legacy));
		$this->assertFalse($processor->process($legacy));
	}
}
