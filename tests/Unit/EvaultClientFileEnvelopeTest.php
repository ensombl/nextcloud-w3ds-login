<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\EvaultClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Reading a File meta-envelope's payload.
 *
 * Two field vocabularies are both legitimate here. A blob uploaded through the
 * eVault's own `uploadFile` mutation is stored under its internal
 * `w3ds-file-v1` payload: `filename`, `contentType`, `publicUrl`. A blob
 * written by a platform as an ordinary File entity uses the published File
 * schema (a1b2c3d4-e5f6-7890-abcd-ef1234567890), whose fields are `name`,
 * `mimeType`, `url`, `size`, and an optional base64 `data` for content never
 * pushed to object storage.
 *
 * Reading only the first vocabulary made every attachment of the second kind
 * dereference to nothing, which is why files sent from other platforms never
 * appeared in Talk.
 */
class EvaultClientFileEnvelopeTest extends TestCase {
	private function client(): EvaultClient {
		$client = (new \ReflectionClass(EvaultClient::class))->newInstanceWithoutConstructor();

		$logger = new \ReflectionProperty(EvaultClient::class, 'logger');
		$logger->setAccessible(true);
		$logger->setValue($client, new NullLogger());

		return $client;
	}

	private function invoke(string $method, array $args): mixed {
		$m = new \ReflectionMethod(EvaultClient::class, $method);
		$m->setAccessible(true);

		return $m->invoke($this->client(), ...$args);
	}

	// -------- field vocabularies --------

	public function testPrefersTheEvaultUploadPayloadFieldsWhenPresent(): void {
		$payload = [
			'filename' => 'report.pdf',
			'contentType' => 'application/pdf',
			'publicUrl' => 'https://cdn.example.org/a.pdf',
		];

		$this->assertSame('report.pdf', $this->invoke('firstStringField', [$payload, ['filename', 'name']]));
		$this->assertSame('application/pdf', $this->invoke('firstStringField', [$payload, ['contentType', 'mimeType']]));
	}

	public function testFallsBackToTheFileSchemaFieldNames(): void {
		// Exactly the shape the published File schema produces.
		$payload = [
			'name' => 'holiday.jpg',
			'mimeType' => 'image/jpeg',
			'url' => 'https://cdn.example.org/holiday.jpg',
			'size' => 12345,
		];

		$this->assertSame('holiday.jpg', $this->invoke('firstStringField', [$payload, ['filename', 'name', 'displayName']]));
		$this->assertSame('image/jpeg', $this->invoke('firstStringField', [$payload, ['contentType', 'mimeType']]));
		$this->assertSame('https://cdn.example.org/holiday.jpg', $this->invoke('firstStringField', [$payload, ['publicUrl', 'url']]));
	}

	/**
	 * The File schema types `url` as `["string","null"]`, so an envelope
	 * carrying its bytes inline sets it to null explicitly.
	 */
	public function testTreatsAnExplicitlyNullUrlAsAbsent(): void {
		$this->assertNull($this->invoke('firstStringField', [['url' => null], ['publicUrl', 'url']]));
		$this->assertNull($this->invoke('firstStringField', [['url' => ''], ['publicUrl', 'url']]));
		$this->assertNull($this->invoke('firstStringField', [['size' => 5], ['publicUrl', 'url']]));
	}

	public function testIgnoresNonStringValues(): void {
		$this->assertNull($this->invoke('firstStringField', [['name' => 42], ['name']]));
		$this->assertNull($this->invoke('firstStringField', [['name' => ['a']], ['name']]));
	}

	// -------- inline base64 content --------

	public function testDecodesBareBase64Content(): void {
		$this->assertSame(
			'hello world',
			$this->client()->decodeInlineFileData(base64_encode('hello world'), 1024),
		);
	}

	public function testDecodesADataUri(): void {
		$this->assertSame(
			'hello world',
			$this->client()->decodeInlineFileData('data:text/plain;base64,' . base64_encode('hello world'), 1024),
		);
	}

	/**
	 * Base64 in transit is often line-wrapped, which the strict decoder
	 * rejects outright.
	 */
	public function testToleratesWhitespaceInTransportedBase64(): void {
		$wrapped = chunk_split(base64_encode(str_repeat('a', 200)), 60, "\n");

		$this->assertSame(str_repeat('a', 200), $this->client()->decodeInlineFileData($wrapped, 4096));
	}

	/**
	 * PHP silently drops invalid characters unless decoding is strict, which
	 * would write a corrupt file rather than failing.
	 */
	public function testRejectsContentThatIsNotValidBase64(): void {
		$this->assertNull($this->client()->decodeInlineFileData('not!valid!base64!', 1024));
		$this->assertNull($this->client()->decodeInlineFileData('', 1024));
		$this->assertNull($this->client()->decodeInlineFileData('data:text/plain;base64,', 1024));
		// A data: URI with no comma has no payload at all.
		$this->assertNull($this->client()->decodeInlineFileData('data:text/plain', 1024));
	}

	/**
	 * The size cap must be enforced from the encoded length, before decoding
	 * allocates the full blob.
	 */
	public function testRefusesContentAboveTheSizeLimit(): void {
		$big = base64_encode(str_repeat('x', 4096));

		$this->assertNull($this->client()->decodeInlineFileData($big, 1024));
		$this->assertNotNull($this->client()->decodeInlineFileData($big, 8192));
	}
}
