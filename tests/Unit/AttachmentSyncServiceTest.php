<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\AttachmentSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Share-reference parsing and filename handling.
 *
 * Filenames arrive from a remote platform, so they are untrusted input that
 * gets written to disk -- path traversal here would be a real vulnerability.
 */
class AttachmentSyncServiceTest extends TestCase {
	private function service(): AttachmentSyncService {
		return (new \ReflectionClass(AttachmentSyncService::class))->newInstanceWithoutConstructor();
	}

	private function invoke(string $method, array $args): mixed {
		$m = new \ReflectionMethod(AttachmentSyncService::class, $method);
		$m->setAccessible(true);

		return $m->invoke($this->service(), ...$args);
	}

	// -------- Talk share references --------

	public function testExtractsShareIdFromTalkComment(): void {
		// The exact shape Talk writes for a file share.
		$message = json_encode([
			'message' => 'file_shared',
			'parameters' => ['share' => '6', 'metaData' => ['mimeType' => 'text/plain']],
		]);

		$this->assertSame('6', $this->service()->extractShareId($message));
	}

	public function testExtractsNumericShareId(): void {
		$this->assertSame('42', $this->service()->extractShareId(
			json_encode(['message' => 'file_shared', 'parameters' => ['share' => 42]]),
		));
	}

	public function testReturnsNullForNonShareMessages(): void {
		$this->assertNull($this->service()->extractShareId('just a normal message'));
		$this->assertNull($this->service()->extractShareId(''));
		$this->assertNull($this->service()->extractShareId(json_encode(['message' => 'file_shared'])));
		$this->assertNull($this->service()->extractShareId(json_encode(['parameters' => []])));
		$this->assertNull($this->service()->extractShareId(json_encode(['parameters' => ['share' => '']])));
	}

	public function testReturnsNullWhenShareIsNotScalar(): void {
		$this->assertNull($this->service()->extractShareId(
			json_encode(['parameters' => ['share' => ['nested']]]),
		));
	}

	// -------- filenames from a remote platform --------

	public function testStripsPathTraversalFromFilename(): void {
		$this->assertSame('passwd', $this->invoke('sanitiseFilename', ['../../etc/passwd']));
		$this->assertSame('evil.txt', $this->invoke('sanitiseFilename', ['/absolute/evil.txt']));
		$this->assertSame('win.txt', $this->invoke('sanitiseFilename', ['..\\..\\win.txt']));
	}

	public function testStripsControlCharacters(): void {
		$this->assertSame('report.pdf', $this->invoke('sanitiseFilename', ["report\x00.pdf"]));
		$this->assertSame('a.txt', $this->invoke('sanitiseFilename', ["a\n.txt"]));
	}

	public function testFallsBackForDegenerateNames(): void {
		$this->assertSame('attachment', $this->invoke('sanitiseFilename', ['']));
		$this->assertSame('attachment', $this->invoke('sanitiseFilename', ['.']));
		$this->assertSame('attachment', $this->invoke('sanitiseFilename', ['..']));
		$this->assertSame('attachment', $this->invoke('sanitiseFilename', ['   ']));
		$this->assertSame('attachment', $this->invoke('sanitiseFilename', ['/']));
	}

	public function testKeepsOrdinaryFilenamesIntact(): void {
		$this->assertSame('Report 2026.pdf', $this->invoke('sanitiseFilename', ['Report 2026.pdf']));
		$this->assertSame('photo.jpeg', $this->invoke('sanitiseFilename', ['photo.jpeg']));
		$this->assertSame('émoji-ok.txt', $this->invoke('sanitiseFilename', ['émoji-ok.txt']));
	}
}
