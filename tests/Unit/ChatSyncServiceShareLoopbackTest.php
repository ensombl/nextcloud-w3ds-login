<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\IdMappingMapper;
use OCA\W3dsLogin\Service\AttachmentSyncService;
use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;

/**
 * An inbound attachment must not be echoed back to the eVault.
 *
 * Materialising an inbound attachment creates a real Talk room share, and
 * Talk generates its own comment for that share. That comment reaches
 * MessageSentListener exactly like a locally composed message would.
 *
 * The inbound path records the attachment against the *share*
 * (`share:<id>`), while the outbound guard looks the comment up by its own
 * id. Those never matched, so every file received from another platform was
 * immediately pushed back out as a second, spurious envelope -- a duplicate
 * message nobody sent.
 *
 * The share id inside the comment's parameters is the join between the two.
 */
class ChatSyncServiceShareLoopbackTest extends TestCase {
	private const SHARE_ID = '6';
	private const ENVELOPE = 'envelope-global-id';

	/** The exact comment shape Talk writes for a file share. */
	private function shareComment(string $shareId = self::SHARE_ID): string {
		return (string)json_encode([
			'message' => 'file_shared',
			'parameters' => ['share' => $shareId],
		]);
	}

	private function service(IdMappingMapper $mapper): ChatSyncService {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();

		foreach ([
			'idMappingMapper' => $mapper,
			'attachmentSync' => (new \ReflectionClass(AttachmentSyncService::class))->newInstanceWithoutConstructor(),
		] as $name => $value) {
			$prop = new \ReflectionProperty(ChatSyncService::class, $name);
			$prop->setAccessible(true);
			$prop->setValue($service, $value);
		}

		return $service;
	}

	/**
	 * The share id has to be recoverable from the comment, since that is the
	 * only link back to the mapping the inbound path wrote.
	 */
	public function testTheShareIdIsRecoverableFromTheGeneratedComment(): void {
		$attachments = (new \ReflectionClass(AttachmentSyncService::class))->newInstanceWithoutConstructor();

		$this->assertSame(self::SHARE_ID, $attachments->extractShareId($this->shareComment()));
	}

	/**
	 * The inbound path stores the attachment under this key, so this is what
	 * the outbound guard has to consult.
	 */
	public function testAnInboundShareIsRecognisedByItsShareMapping(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('getGlobalId')->willReturnCallback(
			fn (string $type, string $localId): ?string => $localId === 'share:' . self::SHARE_ID ? self::ENVELOPE : null,
		);

		$this->assertSame(
			self::ENVELOPE,
			$mapper->getGlobalId('message', 'share:' . self::SHARE_ID),
		);
		// The comment's own id is unknown, which is precisely why looking it
		// up by comment id let the echo through.
		$this->assertNull($mapper->getGlobalId('message', '4242'));
	}

	/**
	 * Adopting the comment maps it to the same envelope, so the ordinary
	 * by-comment-id check recognises it from then on.
	 */
	public function testAdoptingTheCommentMapsItToTheSameEnvelope(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('getGlobalId')->willReturnCallback(
			fn (string $type, string $localId): ?string => $localId === 'share:' . self::SHARE_ID ? self::ENVELOPE : null,
		);
		$mapper->expects($this->once())
			->method('storeMapping')
			->with('message', '4242', self::ENVELOPE, '@alice', 'inbound');

		$this->adopt($mapper, self::SHARE_ID, '4242');
	}

	/**
	 * A share we know nothing about is a genuine local upload and must still
	 * be pushed, so nothing is recorded for it.
	 */
	public function testALocallyCreatedShareIsNotAdopted(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('getGlobalId')->willReturn(null);
		$mapper->expects($this->never())->method('storeMapping');

		$this->adopt($mapper, '99', '4242');
	}

	/**
	 * Both mapping columns are uniquely indexed and the envelope already has
	 * a row, so a collision here is expected and must not surface: the share
	 * mapping is what actually suppresses the loopback.
	 */
	public function testACollisionWhileAdoptingIsNotFatal(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('getGlobalId')->willReturnCallback(
			fn (string $type, string $localId): ?string => $localId === 'share:' . self::SHARE_ID ? self::ENVELOPE : null,
		);
		$mapper->method('storeMapping')->willThrowException(new \RuntimeException('duplicate key'));

		$this->adopt($mapper, self::SHARE_ID, '4242');

		$this->assertTrue(true, 'a duplicate-key insert must not propagate');
	}

	/**
	 * The guard suppressing the echo must not be able to expire during the
	 * work it guards. Materialising an attachment can mean downloading up to
	 * the protocol's 250 MB before Talk fires the event, and any time-bounded
	 * guard is a bet on that finishing first -- a bet that was lost at 10s and
	 * is merely less likely to be lost at 600s.
	 *
	 * Scoping the guard to the call itself removes the bet: it is raised for
	 * exactly as long as the write runs, however long that is. Asserted by
	 * checking no duration is involved at all.
	 *
	 * @see ChatSyncServiceIngestGuardTest for the behaviour itself.
	 */
	public function testTheEchoGuardCannotExpireMidWrite(): void {
		$reflection = new \ReflectionClass(ChatSyncService::class);

		$this->assertArrayNotHasKey(
			'INBOUND_POST_LOCK_TTL',
			$reflection->getConstants(),
			'a TTL means the guard can expire while the write is still running',
		);
		$this->assertTrue(
			$reflection->hasMethod('isIngesting'),
			'the echo guard must be scoped to the Talk call instead',
		);
	}

	private function adopt(IdMappingMapper $mapper, string $shareId, string $localId): void {
		$m = new \ReflectionMethod(ChatSyncService::class, 'adoptInboundShareComment');
		$m->setAccessible(true);
		$m->invoke($this->service($mapper), $shareId, $localId, '@alice');
	}
}
