<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\AttachmentSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Non-image attachments from other platforms.
 *
 * Images arrived and files did not, for two reasons the ontology makes plain.
 *
 * The File schema requires `size`, and the inbound guard rejected anything
 * over 25 MB while the protocol's own upload limit is 250 MB. Images sit well
 * under both; documents, archives and video routinely sit between them, so
 * exactly the non-image attachments were dropped.
 *
 * The File schema also requires `mimeType`, but the extension table consulted
 * only image types. Nextcloud infers a file's type from its stored *name*, so
 * a PDF or spreadsheet landed as an extensionless blob with no icon, no
 * preview and no working "open with" -- present in Files but not usable as
 * the file it is.
 */
class AttachmentSyncServiceFileTypesTest extends TestCase {
	private function service(): AttachmentSyncService {
		$service = (new \ReflectionClass(AttachmentSyncService::class))->newInstanceWithoutConstructor();

		$logger = new \ReflectionProperty(AttachmentSyncService::class, 'logger');
		$logger->setAccessible(true);
		$logger->setValue($service, new NullLogger());

		return $service;
	}

	private function invoke(string $method, array $args): mixed {
		$m = new \ReflectionMethod(AttachmentSyncService::class, $method);
		$m->setAccessible(true);

		return $m->invoke($this->service(), ...$args);
	}

	private function constant(string $name): int {
		return (int)(new \ReflectionClass(AttachmentSyncService::class))->getConstant($name);
	}

	// -------- the size cap --------

	/**
	 * The cap must not sit below what a sending platform is allowed to store,
	 * or attachments that legitimately exist simply never arrive.
	 */
	public function testTheSizeCapMatchesTheProtocolUploadLimit(): void {
		$this->assertSame(250 * 1024 * 1024, $this->constant('MAX_ATTACHMENT_BYTES'));
	}

	/**
	 * The concrete regression: a 40 MB document is ordinary, and was rejected
	 * by the old 25 MB cap while every photo sailed through.
	 */
	public function testATypicalDocumentIsWithinTheCap(): void {
		$this->assertGreaterThan(40 * 1024 * 1024, $this->constant('MAX_ATTACHMENT_BYTES'));
	}

	// -------- naming non-image types --------

	/**
	 * @dataProvider documentTypes
	 */
	public function testDocumentTypesGetTheirExtension(string $mimeType, string $expected): void {
		$this->assertSame($expected, $this->invoke('extensionForMimeType', [$mimeType]));
	}

	public static function documentTypes(): array {
		return [
			'pdf' => ['application/pdf', '.pdf'],
			'word' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', '.docx'],
			'excel' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', '.xlsx'],
			'powerpoint' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', '.pptx'],
			'legacy word' => ['application/msword', '.doc'],
			'opendocument' => ['application/vnd.oasis.opendocument.text', '.odt'],
			'zip' => ['application/zip', '.zip'],
			'csv' => ['text/csv', '.csv'],
			'mp4' => ['video/mp4', '.mp4'],
			'mp3' => ['audio/mpeg', '.mp3'],
			// Images must keep working.
			'png' => ['image/png', '.png'],
			'jpeg' => ['image/jpeg', '.jpg'],
		];
	}

	public function testMediaTypeParametersAreIgnored(): void {
		$this->assertSame('.png', $this->invoke('extensionForMimeType', ['image/png; charset=binary']));
		$this->assertSame('.pdf', $this->invoke('extensionForMimeType', ['APPLICATION/PDF']));
	}

	/**
	 * An unidentifiable type must still produce a named file rather than an
	 * extensionless blob.
	 */
	public function testUnknownTypesStillGetAFileExtension(): void {
		$this->assertSame('attachment.bin', $this->invoke('ensureExtension', ['attachment', 'application/x-unheard-of']));
	}

	public function testAnExistingExtensionIsLeftAlone(): void {
		// The sender's own naming is more informative than our table.
		$this->assertSame('report.jpeg', $this->invoke('ensureExtension', ['report.jpeg', 'image/jpeg']));
		$this->assertSame('notes.yml', $this->invoke('ensureExtension', ['notes.yml', 'application/octet-stream']));
		$this->assertSame('slides.pptx', $this->invoke('ensureExtension', ['slides.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation']));
	}

	public function testANamelessDocumentGainsTheRightExtension(): void {
		$this->assertSame(
			'9f8a7b6c5d.pdf',
			$this->invoke('ensureExtension', ['9f8a7b6c5d', 'application/pdf']),
		);
	}

	// -------- identifying opaque content --------

	/**
	 * A plain object-storage URL declares no type and often has an opaque
	 * path, so the bytes are the only evidence of what the file is.
	 */
	public function testIdentifiesCommonDocumentsFromTheirBytes(): void {
		$this->assertSame('application/pdf', $this->invoke('sniffContentType', ["%PDF-1.7\n%\xE2\xE3\xCF\xD3\n"]));
		$this->assertSame('image/png', $this->invoke('sniffContentType', ["\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR"]));
		$this->assertSame('image/gif', $this->invoke('sniffContentType', ['GIF89a' . str_repeat("\x00", 32)]));
	}

	/**
	 * OOXML and ODF are ZIP containers, so the archive magic alone is not the
	 * answer; the parts inside identify the real format.
	 */
	public function testDistinguishesOfficeFormatsFromPlainArchives(): void {
		$docx = "PK\x03\x04" . str_repeat("\x00", 26) . 'word/document.xml';
		$xlsx = "PK\x03\x04" . str_repeat("\x00", 26) . 'xl/workbook.xml';
		$zip = "PK\x03\x04" . str_repeat("\x00", 26) . 'holiday/photo.jpg';

		$this->assertSame(
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			$this->invoke('sniffContentType', [$docx]),
		);
		$this->assertSame(
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			$this->invoke('sniffContentType', [$xlsx]),
		);
		$this->assertSame('application/zip', $this->invoke('sniffContentType', [$zip]));
	}

	/**
	 * Unidentifiable content must return null so the caller keeps its
	 * existing type rather than renaming the user's file on a guess.
	 */
	public function testReturnsNullWhenContentCannotBeIdentified(): void {
		$this->assertNull($this->invoke('sniffContentType', ['']));
	}
}
