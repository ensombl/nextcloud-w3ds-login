<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Where an attachment's blob reference actually lives on the wire.
 *
 * The Message schema declares `mediaUrl` as the single attachment field, typed
 * only as `format: uri` and with additional properties forbidden. `fileId` is
 * an extension some platforms (including this one) also write. So a reference
 * may arrive in either field and in any of three forms: a `w3ds://file` URI, a
 * plain object-storage URL, or a base64 `data:` URI.
 *
 * A w3ds:// reference is preferred wherever it appears, because it is the only
 * form that also carries the file's real name and MIME type. But the other two
 * are fetchable and must not be discarded: doing so is what left attachments
 * composed on other platforms arriving as bare text.
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

	/**
	 * With no w3ds:// reference anywhere, the inline copy is the only copy of
	 * the bytes. Discarding it loses the attachment outright.
	 */
	public function testFallsBackToADataUriWhenItIsTheOnlyReference(): void {
		$uri = 'data:image/jpeg;base64,/9j/4AAQSkZJRgABAQAAAQABAAD';
		$this->assertSame($uri, $this->pick(['mediaUrl' => $uri]));
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

	/**
	 * `mediaUrl` is what the schema actually defines, so a plain URL there is
	 * a conforming attachment, not a malformed one.
	 */
	public function testAcceptsAPlainUrlAsAReference(): void {
		$this->assertSame('https://example.org/a.png', $this->pick(['fileId' => 'https://example.org/a.png']));
		$this->assertSame('https://cdn.example.org/x.pdf', $this->pick(['mediaUrl' => 'https://cdn.example.org/x.pdf']));
	}

	/**
	 * A reference we have no way to fetch is worse than none: it would post
	 * the raw URI as a line of text.
	 */
	public function testIgnoresReferencesItCannotFetch(): void {
		$this->assertNull($this->pick(['mediaUrl' => 'w3ds://profile?id=@alice/x']));
		$this->assertNull($this->pick(['mediaUrl' => 'ftp://example.org/a.png']));
		$this->assertNull($this->pick(['fileId' => 'javascript:alert(1)']));
	}

	/**
	 * The w3ds:// reference wins wherever it sits, because only it resolves to
	 * the file's real name and MIME type.
	 */
	public function testPrefersAW3dsReferenceInMediaUrlOverAPlainUrlInFileId(): void {
		$this->assertSame(self::W3DS_URI, $this->pick([
			'fileId' => 'https://example.org/not-a-w3ds-uri.png',
			'mediaUrl' => self::W3DS_URI,
		]));
	}
}
