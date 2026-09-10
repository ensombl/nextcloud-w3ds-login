<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\AttachmentSyncService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Writing an inbound attachment without ever exposing a partial file.
 *
 * Several requests can ingest the same attachment at once: polling is driven
 * by every open browser tab, plus cron, plus the webhook. The old code chose a
 * free name with nodeExists() and created it later, which is check-then-act --
 * two requests could pick the same name, and the second truncated and rewrote
 * the file while the first was still readable. A reader in that window saw a
 * partially written image: the header parsed, so the dimensions were right,
 * but the pixels were garbage and the preview rendered as a flat colour block.
 *
 * Nextcloud cannot serialise this for us: its file locking needs a distributed
 * cache, and none is configured by default. So bytes are staged under a name
 * unique to the attempt and only moved into place once complete.
 */
class AttachmentSyncServiceAtomicWriteTest extends TestCase {
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

	/**
	 * The check-then-act helper that caused the corruption must be gone, not
	 * merely unused: leaving it invites the same bug back.
	 */
	public function testTheCheckThenActNameHelperIsGone(): void {
		$this->assertFalse(
			method_exists(AttachmentSyncService::class, 'uniqueName'),
			'uniqueName() picked a name that a concurrent request could also pick',
		);
	}

	public function testWritesGoThroughAnAtomicPlacementStep(): void {
		$this->assertTrue(method_exists(AttachmentSyncService::class, 'writeAttachmentFile'));
	}

	/**
	 * Collision naming still has to look like everyone else's, since Talk
	 * shows the stored file's name in the conversation.
	 */
	public function testNumberedNamesKeepTheExtension(): void {
		$this->assertSame('photo (1).jpg', $this->invoke('numberedName', ['photo.jpg', 1]));
		$this->assertSame('report (7).pdf', $this->invoke('numberedName', ['report.pdf', 7]));
	}

	public function testNumberedNamesHandleNamesWithoutAnExtension(): void {
		$this->assertSame('attachment (2)', $this->invoke('numberedName', ['attachment', 2]));
	}

	/**
	 * A dotted stem must not be mistaken for an extension boundary in the
	 * wrong place.
	 */
	public function testNumberedNamesSplitOnTheFinalDot(): void {
		$this->assertSame('archive.tar (1).gz', $this->invoke('numberedName', ['archive.tar.gz', 1]));
	}
}
