<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\AttachmentSyncService;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Creating the inbound attachments folder while another request is doing the
 * same thing.
 *
 * Inbound sync runs from every open browser tab's poll, from cron and from the
 * webhook, so several requests routinely handle a user's first attachment at
 * the same moment. Nextcloud takes an exclusive lock on the parent while a
 * folder is created, so all but one of them saw:
 *
 *     "/<uid>/files/W3DS Attachments" is locked
 *
 * That exception was raised while resolving the destination, before any bytes
 * were written, so the attachment was dropped rather than retried -- which is
 * why a file or image sent from another platform never appeared in Talk.
 *
 * The loser of that race does not actually need to create anything: the
 * winner's folder is exactly what it wanted.
 */
class AttachmentSyncServiceFolderRaceTest extends TestCase {
	private function service(): AttachmentSyncService {
		$service = (new \ReflectionClass(AttachmentSyncService::class))->newInstanceWithoutConstructor();

		$logger = new \ReflectionProperty(AttachmentSyncService::class, 'logger');
		$logger->setAccessible(true);
		$logger->setValue($service, new NullLogger());

		return $service;
	}

	private function ensureInboxFolder(Folder $userFolder): ?Folder {
		$m = new \ReflectionMethod(AttachmentSyncService::class, 'ensureInboxFolder');
		$m->setAccessible(true);

		return $m->invoke($this->service(), $userFolder);
	}

	public function testCreatesTheFolderWhenItDoesNotExistYet(): void {
		$created = $this->createMock(Folder::class);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(false);
		$userFolder->expects($this->once())->method('newFolder')->willReturn($created);

		$this->assertSame($created, $this->ensureInboxFolder($userFolder));
	}

	public function testReusesTheFolderWhenItAlreadyExists(): void {
		$existing = $this->createMock(Folder::class);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($existing);
		$userFolder->expects($this->never())->method('newFolder');

		$this->assertSame($existing, $this->ensureInboxFolder($userFolder));
	}

	/**
	 * The regression: a lock held by the request that is creating the very
	 * same folder must not cost us the attachment.
	 */
	public function testAdoptsTheWinnersFolderAfterLosingTheCreationRace(): void {
		$winners = $this->createMock(Folder::class);

		$userFolder = $this->createMock(Folder::class);
		// First look: absent, so we try to create. By the time we retry, the
		// winner has finished and the folder is there.
		$userFolder->method('nodeExists')->willReturnOnConsecutiveCalls(false, true, true);
		$userFolder->method('newFolder')->willThrowException(
			new LockedException('"/uid/files/W3DS Attachments" is locked'),
		);
		$userFolder->method('get')->willReturn($winners);

		$this->assertSame(
			$winners,
			$this->ensureInboxFolder($userFolder),
			'losing the race must yield the winner folder, not drop the attachment',
		);
	}

	/**
	 * Same race seen from the other side: some storages report the collision
	 * as a plain exception from newFolder() rather than as a lock.
	 */
	public function testAdoptsTheWinnersFolderWhenCreationReportsANameCollision(): void {
		$winners = $this->createMock(Folder::class);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturnOnConsecutiveCalls(false, true, true);
		$userFolder->method('newFolder')->willThrowException(new \RuntimeException('already exists'));
		$userFolder->method('get')->willReturn($winners);

		$this->assertSame($winners, $this->ensureInboxFolder($userFolder));
	}

	/**
	 * A lock that never clears is a real failure, and must be reported as one
	 * rather than looping forever.
	 */
	public function testGivesUpWhenTheFolderNeverBecomesAvailable(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(false);
		$userFolder->method('newFolder')->willThrowException(new LockedException('locked'));

		$this->assertNull($this->ensureInboxFolder($userFolder));
	}

	/**
	 * A node of the wrong kind is not something to retry around: the user has
	 * a *file* by that name, and no number of retries changes that.
	 */
	public function testReturnsNullWhenTheNameIsTakenBySomethingOtherThanAFolder(): void {
		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('nodeExists')->willReturn(true);
		$userFolder->method('get')->willReturn($this->createMock(Node::class));

		$this->assertNull($this->ensureInboxFolder($userFolder));
	}
}
