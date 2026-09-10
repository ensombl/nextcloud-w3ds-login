<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Where an attachment's blob reference actually lives on the wire.
 *
 * Platforms disagree. The reference implementation puts the `w3ds://file` URI
 * in `fileId` and treats `mediaUrl` as a slot for a base64 data URI it can
 * render inline, often leaving it out entirely. Reading only `mediaUrl` means
 * inbound attachments from those platforms resolve to nothing, and a data URI
 * found there is useless to us regardless: Talk needs real bytes written into
 * the recipient's Files, which only a dereferenceable w3ds://file URI provides.
 */
class ChatSyncServiceAttachmentUriTest extends TestCase {
	private const W3DS_URI = 'w3ds://file?id=@alice/8e34416e-7d22-51c1-949a-c263b53e3fbf';

	private function pick(array $data): ?string {
		$m = new \ReflectionMethod(ChatSyncService::class, 'pickAttachmentUri');
		$m->setAccessible(true);
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();

		return $m->invoke($service, $data);
	}

	public function testReadsTheUriPeersPutInFileId(): void {
		$this->assertSame(self::W3DS_URI, $this->pick(['fileId' => self::W3DS_URI]));
	}

	public function testReadsTheUriFromMediaUrlWhenThatIsWhereItIs(): void {
		$this->assertSame(self::W3DS_URI, $this->pick(['mediaUrl' => self::W3DS_URI]));
	}

	/**
	 * The exact shape the reference implementation emits: the real reference
	 * in fileId, an inline-renderable copy in mediaUrl. Taking mediaUrl here
	 * would hand a base64 blob to the URI parser and lose the attachment.
	 */
	public function testPrefersFileIdOverAnInlineDataUri(): void {
		$this->assertSame(self::W3DS_URI, $this->pick([
			'fileId' => self::W3DS_URI,
			'mediaUrl' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD',
		]));
	}

	public function testIgnoresADataUriWithNoUsableReferenceAnywhere(): void {
		$this->assertNull($this->pick([
			'mediaUrl' => 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD',
		]));
	}

	public function testReturnsNullWhenNoAttachmentFieldsArePresent(): void {
		$this->assertNull($this->pick([]));
		$this->assertNull($this->pick(['content' => 'just text', 'type' => 'text']));
	}

	public function testIgnoresEmptyAndNonStringValues(): void {
		$this->assertNull($this->pick(['fileId' => '', 'mediaUrl' => '']));
		$this->assertNull($this->pick(['fileId' => null]));
		$this->assertNull($this->pick(['fileId' => ['nested']]));
		$this->assertNull($this->pick(['fileId' => 42]));
	}

	public function testIgnoresUrisThatAreNotW3dsFileReferences(): void {
		$this->assertNull($this->pick(['fileId' => 'https://example.org/a.png']));
		$this->assertNull($this->pick(['mediaUrl' => 'w3ds://profile?id=@alice/x']));
	}

	public function testFallsBackToMediaUrlWhenFileIdIsUnusable(): void {
		$this->assertSame(self::W3DS_URI, $this->pick([
			'fileId' => 'https://example.org/not-a-w3ds-uri.png',
			'mediaUrl' => self::W3DS_URI,
		]));
	}
}
