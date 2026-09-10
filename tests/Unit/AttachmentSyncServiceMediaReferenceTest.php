<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\AttachmentSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Resolving whatever a peer platform put in a Message envelope's `mediaUrl`.
 *
 * The Message schema (550e8400-e29b-41d4-a716-446655440004) declares exactly
 * one attachment field, `mediaUrl`, typed only as `format: uri`, and forbids
 * additional properties. So a conforming sender may legitimately put a
 * `w3ds://file` reference, a plain object-storage URL, or a base64 `data:` URI
 * there, and we have to handle all three. Handling only the first is why
 * attachments composed on other platforms never materialised here.
 */
class AttachmentSyncServiceMediaReferenceTest extends TestCase {
	private function service(): AttachmentSyncService {
		$service = (new \ReflectionClass(AttachmentSyncService::class))->newInstanceWithoutConstructor();

		// Only the logger is reachable from resolveAttachment(); the rejection
		// path logs what it turned down, so it has to be initialised.
		$logger = new \ReflectionProperty(AttachmentSyncService::class, 'logger');
		$logger->setAccessible(true);
		$logger->setValue($service, new \Psr\Log\NullLogger());

		return $service;
	}

	private function resolve(string $mediaUrl): ?array {
		$m = new \ReflectionMethod(AttachmentSyncService::class, 'resolveAttachment');
		$m->setAccessible(true);

		return $m->invoke($this->service(), $mediaUrl);
	}

	// -------- plain URLs --------

	public function testResolvesAPlainHttpsUrlToItself(): void {
		$meta = $this->resolve('https://cdn.example.org/files/holiday.jpg');

		$this->assertNotNull($meta);
		$this->assertSame('https://cdn.example.org/files/holiday.jpg', $meta['publicUrl']);
		$this->assertNull($meta['inlineData']);
		$this->assertSame('holiday.jpg', $meta['filename']);
	}

	public function testTakesTheFilenameFromTheUrlPathAndDecodesIt(): void {
		$meta = $this->resolve('https://cdn.example.org/f/my%20report.pdf?sig=abc');

		$this->assertSame('my report.pdf', $meta['filename']);
	}

	/**
	 * A signed storage URL often ends in an opaque key with no filename.
	 * Anything unusable must still produce a safe name rather than an empty
	 * one, which would fail the write into the recipient's Files.
	 */
	public function testFallsBackToAGenericNameWhenTheUrlCarriesNone(): void {
		$this->assertSame('attachment', $this->resolve('https://cdn.example.org/')['filename']);
	}

	/**
	 * The filename comes from a remote platform and gets written to disk, so
	 * a traversal attempt in the URL path must not escape the inbox folder.
	 */
	public function testStripsPathTraversalFromAUrlDerivedFilename(): void {
		$meta = $this->resolve('https://cdn.example.org/a/%2E%2E%2F%2E%2E%2Fetc%2Fpasswd');

		$this->assertSame('passwd', $meta['filename']);
		$this->assertStringNotContainsString('/', $meta['filename']);
		$this->assertStringNotContainsString('..', $meta['filename']);
	}

	// -------- data URIs --------

	public function testResolvesADataUriToInlineBytes(): void {
		$uri = 'data:image/png;base64,iVBORw0KGgo=';
		$meta = $this->resolve($uri);

		$this->assertNotNull($meta);
		$this->assertNull($meta['publicUrl']);
		$this->assertSame($uri, $meta['inlineData']);
		$this->assertSame('image/png', $meta['contentType']);
	}

	/**
	 * A data URI names no file. Talk decides whether to render a preview from
	 * the stored file's name, so an image landing without an extension shows
	 * as a download link instead of the picture that was sent.
	 */
	public function testNamesADataUriAttachmentFromItsMediaType(): void {
		$this->assertSame('attachment.png', $this->resolve('data:image/png;base64,AAAA')['filename']);
		$this->assertSame('attachment.jpg', $this->resolve('data:image/jpeg;base64,AAAA')['filename']);
		$this->assertSame('attachment.pdf', $this->resolve('data:application/pdf;base64,AAAA')['filename']);
	}

	public function testFallsBackToAnUntypedNameForAnUnknownMediaType(): void {
		$meta = $this->resolve('data:application/x-thing;base64,AAAA');

		$this->assertSame('attachment', $meta['filename']);
		$this->assertSame('application/x-thing', $meta['contentType']);
	}

	public function testTreatsAnOmittedMediaTypeAsOpaqueBytes(): void {
		$meta = $this->resolve('data:;base64,AAAA');

		$this->assertSame('application/octet-stream', $meta['contentType']);
	}

	// -------- rejections --------

	public function testRejectsReferencesItCannotFetch(): void {
		$this->assertNull($this->resolve('ftp://example.org/x.png'));
		$this->assertNull($this->resolve('javascript:alert(1)'));
		$this->assertNull($this->resolve('/etc/passwd'));
		$this->assertNull($this->resolve(''));
	}
}
