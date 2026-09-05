<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\AvatarSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Avatar source selection and inline decoding.
 *
 * These are the parts that decide whether we touch the network at all and
 * what we hand to Nextcloud, so they need to reject hostile or malformed
 * input without ever throwing into a login path.
 */
class AvatarSyncServiceTest extends TestCase {
	private function service(): AvatarSyncService {
		$service = (new \ReflectionClass(AvatarSyncService::class))->newInstanceWithoutConstructor();

		// Only the rejection paths touch the logger.
		$logger = new \ReflectionProperty(AvatarSyncService::class, 'logger');
		$logger->setAccessible(true);
		$logger->setValue($service, new \Psr\Log\NullLogger());

		return $service;
	}

	private function invoke(string $method, array $args): mixed {
		$m = new \ReflectionMethod(AvatarSyncService::class, $method);
		$m->setAccessible(true);

		return $m->invoke($this->service(), ...$args);
	}

	// -------- which profile field carries the picture --------

	public function testPrefersAvatarUrlField(): void {
		$this->assertSame(
			'https://example.org/a.png',
			$this->invoke('pickAvatarSource', [[
				'avatarUrl' => 'https://example.org/a.png',
				'picture' => 'https://example.org/b.png',
			]]),
		);
	}

	public function testFallsBackThroughAlternativeFieldNames(): void {
		foreach (['avatar', 'picture', 'image', 'photo'] as $field) {
			$this->assertSame(
				'https://example.org/x.png',
				$this->invoke('pickAvatarSource', [[$field => 'https://example.org/x.png']]),
				"field: $field",
			);
		}
	}

	public function testReturnsNullWhenProfileHasNoPicture(): void {
		$this->assertNull($this->invoke('pickAvatarSource', [[]]));
		$this->assertNull($this->invoke('pickAvatarSource', [['displayName' => 'Alice']]));
	}

	public function testIgnoresEmptyAndNonStringValues(): void {
		$this->assertNull($this->invoke('pickAvatarSource', [['avatarUrl' => '']]));
		$this->assertNull($this->invoke('pickAvatarSource', [['avatarUrl' => '   ']]));
		$this->assertNull($this->invoke('pickAvatarSource', [['avatarUrl' => null]]));
		$this->assertNull($this->invoke('pickAvatarSource', [['avatarUrl' => ['nested']]]));
	}

	public function testTrimsSurroundingWhitespace(): void {
		$this->assertSame(
			'https://example.org/a.png',
			$this->invoke('pickAvatarSource', [['avatarUrl' => "  https://example.org/a.png\n"]]),
		);
	}

	// -------- inline data URIs --------

	public function testDecodesBase64DataUri(): void {
		$png = base64_decode('iVBORw0KGgoAAAANSUhEUg==', true);
		$uri = 'data:image/png;base64,' . base64_encode($png);

		$this->assertSame($png, $this->invoke('decodeDataUri', [$uri]));
	}

	public function testRejectsNonImageDataUri(): void {
		// An SVG or HTML payload is a stored-XSS vector, not an avatar.
		$this->assertNull($this->invoke('decodeDataUri', [
			'data:image/svg+xml;base64,' . base64_encode('<svg onload="alert(1)"/>'),
		]));
		$this->assertNull($this->invoke('decodeDataUri', [
			'data:text/html;base64,' . base64_encode('<script>alert(1)</script>'),
		]));
	}

	public function testRejectsImageTypesNextcloudCannotStore(): void {
		// IAvatar::set() throws "Unknown filetype" for these, so accepting
		// them would mean fetching bytes we can never store.
		$this->assertNull($this->invoke('decodeDataUri', [
			'data:image/gif;base64,' . base64_encode('GIF89a'),
		]));
		$this->assertNull($this->invoke('decodeDataUri', [
			'data:image/webp;base64,' . base64_encode('RIFF....WEBP'),
		]));
	}

	public function testAcceptsTheTypesNextcloudSupports(): void {
		foreach (['image/png', 'image/jpeg'] as $mime) {
			$this->assertSame(
				'bytes',
				$this->invoke('decodeDataUri', ["data:$mime;base64," . base64_encode('bytes')]),
				"mime: $mime",
			);
		}
	}

	public function testRejectsMalformedDataUri(): void {
		$this->assertNull($this->invoke('decodeDataUri', ['data:']));
		$this->assertNull($this->invoke('decodeDataUri', ['not a data uri']));
		$this->assertNull($this->invoke('decodeDataUri', ['data:image/png;base64,!!!not-base64!!!']));
	}

	public function testRejectsEmptyPayload(): void {
		$this->assertNull($this->invoke('decodeDataUri', ['data:image/png;base64,']));
	}

	public function testRejectsOversizedInlineAvatar(): void {
		// Guards against a hostile profile embedding something huge.
		$huge = str_repeat('A', 6 * 1024 * 1024);

		$this->assertNull($this->invoke('decodeDataUri', [
			'data:image/png;base64,' . base64_encode($huge),
		]));
	}

	// -------- reference kinds --------

	public function testIgnoresUnsupportedReferenceSchemes(): void {
		// file:// would be a local-file-read primitive; ipfs:// we can't fetch.
		$this->assertNull($this->invoke('resolveBytes', ['file:///etc/passwd']));
		$this->assertNull($this->invoke('resolveBytes', ['ipfs://Qm...']));
		$this->assertNull($this->invoke('resolveBytes', ['/relative/path.png']));
	}

	public function testInlineDataUriNeedsNoNetworkCall(): void {
		// resolveBytes must handle data: without an HTTP client, which is
		// what makes this safe to call on the provisioning path.
		$png = 'binary-png-bytes';

		$this->assertSame(
			$png,
			$this->invoke('resolveBytes', ['data:image/jpeg;base64,' . base64_encode($png)]),
		);
	}
}
