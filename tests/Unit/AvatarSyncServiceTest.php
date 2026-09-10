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

	public function testAcceptsTypesGdCanConvertForUs(): void {
		// Nextcloud stores only PNG/JPEG, but normaliseForStorage() re-encodes
		// to PNG, so rejecting these outright would discard pictures we can in
		// fact display purely over container format.
		$this->assertSame('GIF89a', $this->invoke('decodeDataUri', [
			'data:image/gif;base64,' . base64_encode('GIF89a'),
		]));
		$this->assertSame('RIFF....WEBP', $this->invoke('decodeDataUri', [
			'data:image/webp;base64,' . base64_encode('RIFF....WEBP'),
		]));
	}

	public function testStillRejectsScriptBearingAndNonImageTypes(): void {
		// SVG is markup, so storing a remote one is a stored-XSS vector, and
		// it stays excluded no matter what GD could decode.
		$this->assertNull($this->invoke('decodeDataUri', [
			'data:image/svg+xml;base64,' . base64_encode('<svg onload="alert(1)"/>'),
		]));
		$this->assertNull($this->invoke('decodeDataUri', [
			'data:text/html;base64,' . base64_encode('<h1>hi</h1>'),
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

	// -------- normalising for storage --------

	/** Real encoded bytes, since normaliseForStorage actually decodes. */
	private function imageBytes(int $width, int $height, string $type = 'png'): string {
		$image = imagecreatetruecolor($width, $height);
		ob_start();
		('image' . $type)($image);
		$bytes = (string)ob_get_clean();
		imagedestroy($image);

		return $bytes;
	}

	/** @return array{0:int,1:int,2:string} width, height, mime */
	private function describe(string $bytes): array {
		$info = getimagesizefromstring($bytes);

		return [$info[0], $info[1], $info['mime']];
	}

	public function testPadsLandscapeImageToASquare(): void {
		// Nextcloud rejects a non-square avatar outright rather than adapting
		// it, so a landscape picture must be squared or it is silently
		// discarded after being fetched.
		$out = $this->invoke('normaliseForStorage', [$this->imageBytes(200, 100)]);

		$this->assertNotNull($out);
		$this->assertSame([200, 200, 'image/png'], $this->describe($out));
	}

	public function testPadsPortraitImageToASquare(): void {
		$out = $this->invoke('normaliseForStorage', [$this->imageBytes(80, 240)]);

		$this->assertNotNull($out);
		$this->assertSame([240, 240, 'image/png'], $this->describe($out));
	}

	public function testPadsRatherThanCropsSoNoPixelsAreLost(): void {
		// The padded canvas takes the LONGER side, which is what distinguishes
		// padding from cropping: a crop would come back at the shorter side
		// and would have thrown away part of the person's picture.
		$out = $this->invoke('normaliseForStorage', [$this->imageBytes(300, 100)]);

		[$width] = $this->describe($out);
		$this->assertSame(300, $width, 'padding must keep the full width, not crop to 100');
	}

	public function testConvertsTypesNextcloudCannotStore(): void {
		// Square already, but a GIF: still needs re-encoding to PNG.
		$out = $this->invoke('normaliseForStorage', [$this->imageBytes(100, 100, 'gif')]);

		$this->assertNotNull($out);
		$this->assertSame([100, 100, 'image/png'], $this->describe($out));
	}

	public function testLeavesAnAlreadyStorableImageUntouched(): void {
		// Square and already PNG: null means "keep the original bytes",
		// avoiding a pointless re-encode.
		$this->assertNull($this->invoke('normaliseForStorage', [$this->imageBytes(120, 120)]));
		$this->assertNull($this->invoke('normaliseForStorage', [$this->imageBytes(64, 64, 'jpeg')]));
	}

	public function testUndecodableBytesFallBackToTheOriginal(): void {
		$this->assertNull($this->invoke('normaliseForStorage', ['not-an-image']));
		$this->assertNull($this->invoke('normaliseForStorage', ['']));
	}

	// -------- keeping the stored avatar resizable --------

	public function testDownscalesAnOversizedSquarePicture(): void {
		// Nextcloud keeps the original and derives each requested size on
		// demand, inside a web request. A camera-resolution picture cannot be
		// decoded within a default 128M memory_limit, so that derivation
		// throws and /avatar/<uid>/512 returns 500 -- which renders as the
		// generated initials in Talk's conversation list while smaller,
		// already-cached sizes still look correct.
		$out = $this->invoke('normaliseForStorage', [$this->imageBytes(1600, 1600)]);

		$this->assertNotNull($out, 'an oversized picture must be re-encoded, not stored as-is');
		$this->assertSame([512, 512, 'image/png'], $this->describe($out));
	}

	public function testDownscalesAndSquaresInOnePass(): void {
		// Both problems at once, which is the common case for a phone photo.
		$out = $this->invoke('normaliseForStorage', [$this->imageBytes(3648, 2736, 'jpeg')]);

		$this->assertNotNull($out);
		$this->assertSame([512, 512, 'image/png'], $this->describe($out));
	}

	public function testDownscalingPreservesTheAspectRatio(): void {
		// A 2:1 source must still be 2:1 inside the padded square: scaling
		// each axis independently to fill it would stretch the picture.
		$width = 1024;
		$height = 512;
		$out = $this->invoke('normaliseForStorage', [$this->imageBytes($width, $height, 'jpeg')]);

		$image = imagecreatefromstring($out);
		$side = imagesx($image);

		// The drawn region is the part that is not transparent padding.
		$opaqueRows = 0;
		for ($y = 0; $y < $side; $y++) {
			for ($x = 0; $x < $side; $x++) {
				if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 127) {
					$opaqueRows++;
					break;
				}
			}
		}
		imagedestroy($image);

		$expected = (int)round($side * ($height / $width));
		$this->assertEqualsWithDelta($expected, $opaqueRows, 2, 'aspect ratio must survive downscaling');
	}

	public function testLeavesAPictureAtTheLimitUntouched(): void {
		// Exactly at the cap, square, and already PNG: nothing to fix, so the
		// original bytes are kept rather than re-encoded.
		$this->assertNull($this->invoke('normaliseForStorage', [$this->imageBytes(512, 512)]));
	}

	public function testRefusesAnImageWhoseDimensionsAreTooLargeToDecode(): void {
		// A decompression bomb is small on the wire and enormous in memory,
		// so the size check cannot catch it; the header must be read first.
		// 30000x30000 is ~3.6GB decoded and only a few KB compressed.
		$image = imagecreatetruecolor(1, 1);
		ob_start();
		imagepng($image);
		$tiny = (string)ob_get_clean();
		imagedestroy($image);

		// Rewrite the IHDR dimensions in place, so the header claims a size
		// the payload does not have -- exactly a bomb's shape.
		$forged = substr_replace($tiny, pack('NN', 30000, 30000), 16, 8);

		$this->assertNull(
			$this->invoke('normaliseForStorage', [$forged]),
			'an image above the pixel ceiling must be refused before GD decodes it',
		);
	}
}
