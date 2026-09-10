<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\IdMappingMapper;
use OCA\W3dsLogin\Service\AttachmentSyncService;
use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A message we only mirror for display must never be written back to the
 * eVault as our own.
 *
 * Content that arrives from a peer is rendered here as an ordinary Talk
 * comment, because Talk has no way to express that it came from somewhere
 * else. A forward becomes text with a "Forwarded from ..." line we prepend,
 * and an inbound attachment becomes a real local file share. Both are
 * therefore indistinguishable, to the outbound path, from something this user
 * just typed -- so every later listener fire or concurrent poller replicated
 * them back out as fresh envelopes under our own identity. The same forwarded
 * image was then stored once by the sender and again by every recipient.
 *
 * These are read from the remote vault for display only. The existing guards
 * cannot express that: the sync lock is TTL-bounded, and the inbound-post flag
 * lives in a cache that degrades to a per-request store when no distributed
 * cache is configured, which is the default for a single-server install. The
 * `origin` column is durable, and pushChat() already relies on exactly this
 * mechanism for rooms.
 */
class ChatSyncServiceInboundMirrorTest extends TestCase {
	private function service(IdMappingMapper $mapper): ChatSyncService {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();

		foreach ([
			'idMappingMapper' => $mapper,
			'attachmentSync' => (new \ReflectionClass(AttachmentSyncService::class))->newInstanceWithoutConstructor(),
			'logger' => new NullLogger(),
		] as $name => $value) {
			$prop = new \ReflectionProperty(ChatSyncService::class, $name);
			$prop->setAccessible(true);
			$prop->setValue($service, $value);
		}

		return $service;
	}

	/**
	 * Run the outbound push for a comment whose mapping reports $origin, and
	 * report whether the push got past the mirror gate.
	 *
	 * Anything past the gate immediately needs collaborators this
	 * constructor-less instance does not have, so a push that proceeds throws
	 * rather than reaching the network. That distinction is exactly what is
	 * being asserted, and it keeps the test off the eVault entirely.
	 */
	private function pushWasBlocked(?string $origin, string $verb = 'comment', string $message = 'hello'): bool {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('getGlobalId')->willReturn(null);
		$mapper->method('getOrigin')->willReturn($origin);

		$push = new \ReflectionMethod(ChatSyncService::class, 'pushMessageClaimed');
		$push->setAccessible(true);

		try {
			$push->invoke(
				$this->service($mapper),
				'uid',
				'@alice',
				['id' => '4242', 'message' => $message, 'verb' => $verb, 'timestamp' => 0],
				'room-token',
				'4242',
			);

			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * The regression: a forwarded message displayed here is a mirror, and
	 * pushing it duplicates content the sender's vault already holds.
	 */
	public function testAnInboundMessageIsNotPushedBackToTheEvault(): void {
		$this->assertTrue(
			$this->pushWasBlocked('inbound'),
			'a mirrored message must not be replicated back to the eVault',
		);
	}

	/**
	 * The gate must be narrow. A message this user actually wrote is the
	 * entire point of outbound sync, so it has to get through.
	 */
	public function testALocallyComposedMessageIsStillPushed(): void {
		$this->assertFalse(
			$this->pushWasBlocked('local'),
			'a locally composed message must still sync outbound',
		);
	}

	/**
	 * Rows written before the origin column carried a value must not be
	 * mistaken for mirrors, or the user's own history would stop syncing.
	 */
	public function testAMessageWithNoRecordedOriginIsStillPushed(): void {
		$this->assertFalse(
			$this->pushWasBlocked(null),
			'an unmarked message must be treated as local, not as a mirror',
		);
	}

	/**
	 * An inbound attachment reaches the outbound path as Talk's own share
	 * comment, which is the form the duplicate took in practice.
	 */
	public function testAnInboundAttachmentShareCommentIsNotPushedBack(): void {
		$shareComment = (string)json_encode([
			'message' => 'file_shared',
			'parameters' => ['share' => '6', 'metaData' => ['caption' => 'Forwarded from Sahil Garg']],
		]);

		$this->assertTrue(
			$this->pushWasBlocked('inbound', AttachmentSyncService::TALK_SHARE_VERB, $shareComment),
			'a mirrored attachment must not be replicated back to the eVault',
		);
	}

	/**
	 * The gate is only useful if the inbound path actually marks what it
	 * writes. Both inbound mappings -- the share row and the comment row --
	 * must be recorded as mirrors, or the durable check has nothing to read.
	 */
	public function testInboundIngestRecordsTheOriginItLaterReliesOn(): void {
		$source = file_get_contents(__DIR__ . '/../../app/lib/Service/ChatSyncService.php');
		$this->assertIsString($source);

		$this->assertMatchesRegularExpression(
			"/storeMapping\(\s*'message',\s*'share:'\s*\.\s*\\\$share->getId\(\),\s*\\\$globalId,\s*\\\$ownerW3id,\s*'inbound',/",
			$source,
			'the inbound share mapping must be recorded as a mirror',
		);
		$this->assertStringContainsString(
			"storeMapping('message', \$localMessageId, \$globalId, \$ownerW3id, 'inbound')",
			$source,
			'the inbound comment mapping must be recorded as a mirror',
		);
	}
}
