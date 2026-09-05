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

	// -------- w3ds://file URI parsing --------
	//
	// Must agree with the reference parser in
	// infrastructure/web3-adapter/src/w3ds/uri.ts: split on the FIRST slash,
	// both segments non-empty, and an eName longer than a bare '@'.

	private function parseUri(string $uri): ?array {
		$client = (new \ReflectionClass(\OCA\W3dsLogin\Service\EvaultClient::class))
			->newInstanceWithoutConstructor();
		$m = new \ReflectionMethod(\OCA\W3dsLogin\Service\EvaultClient::class, 'parseFileUri');
		$m->setAccessible(true);

		return $m->invoke($client, $uri);
	}

	public function testParsesAValidFileUri(): void {
		$this->assertSame(
			['ename' => '@alice', 'metaEnvelopeId' => 'env-123'],
			$this->parseUri('w3ds://file?id=@alice/env-123'),
		);
	}

	public function testKeepsLaterSlashesInTheEnvelopeId(): void {
		// Split on the first slash only, as the reference parser does.
		$this->assertSame(
			['ename' => '@alice', 'metaEnvelopeId' => 'env/with/slashes'],
			$this->parseUri('w3ds://file?id=@alice/env/with/slashes'),
		);
	}

	public function testRejectsUrisTheReferenceParserRejects(): void {
		$this->assertNull($this->parseUri('w3ds://file?id=@alice'), 'no slash');
		$this->assertNull($this->parseUri('w3ds://file?id=@alice/'), 'empty envelope id');
		$this->assertNull($this->parseUri('w3ds://file?id=@/env'), 'bare @ is not an eName');
		$this->assertNull($this->parseUri('w3ds://file?id=alice/env'), 'missing @ prefix');
		$this->assertNull($this->parseUri('https://example.org/x'), 'wrong scheme');
		$this->assertNull($this->parseUri('w3ds://file'), 'no query');
		$this->assertNull($this->parseUri(''), 'empty');
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
