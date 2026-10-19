<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\BackgroundJob;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Db\TalkAccountMigrator;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Service\AccountMergeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Empties an account that was folded into another at link time, then
 * deletes it.
 *
 * Its Talk rows were moved while the user waited; what is left is its files.
 * Received attachments live there and are shared into rooms from there, and
 * an account somebody used through wallet login may hold anything else they
 * uploaded. Deleting it without moving them would break every attachment
 * and could destroy real data, so the account is only deleted once Nextcloud's
 * own ownership transfer has moved the whole home.
 */
class AbsorbShadowAccountJob extends QueuedJob {
	private const OWNERSHIP_TRANSFER_SERVICE = 'OCA\\Files\\Service\\OwnershipTransferService';

	public function __construct(
		ITimeFactory $time,
		private IUserManager $userManager,
		private IConfig $config,
		private W3dsMappingMapper $mapper,
		private TalkAccountMigrator $talkMigrator,
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param array{shadow?: string, target?: string, w3id?: string} $argument
	 */
	protected function run($argument): void {
		$shadowUid = (string)($argument['shadow'] ?? '');
		$targetUid = (string)($argument['target'] ?? '');
		if ($shadowUid === '' || $targetUid === '') {
			return;
		}

		$shadow = $this->userManager->get($shadowUid);
		$target = $this->userManager->get($targetUid);
		if ($shadow === null || $target === null || !$this->stillFolded($shadowUid, $targetUid)) {
			return;
		}

		try {
			// A chat that arrived while the mapping was switching may have
			// been written for the old account; pick up whatever it left.
			$this->talkMigrator->migrate($shadowUid, $targetUid, $target->getDisplayName());
		} catch (\Throwable $e) {
			$this->keep($shadowUid, $targetUid, 'Talk migration failed: ' . $e->getMessage());
			return;
		}

		if (!$this->transferFiles($shadow, $target)) {
			return;
		}

		try {
			$shadow->delete();
			$this->logger->info('Deleted the W3DS account folded into another at link time', [
				'shadowUid' => $shadowUid,
				'targetUid' => $targetUid,
			]);
		} catch (\Throwable $e) {
			$this->keep($shadowUid, $targetUid, 'delete failed: ' . $e->getMessage());
		}
	}

	/**
	 * The fold this job was queued for is still the current state: the old
	 * account was folded into this target and has not been linked again.
	 */
	private function stillFolded(string $shadowUid, string $targetUid): bool {
		if ($this->config->getUserValue($shadowUid, Application::APP_ID, AccountMergeService::MERGED_INTO_KEY, '') !== $targetUid) {
			return false;
		}
		try {
			$this->mapper->findByNcUid($shadowUid);
			return false;
		} catch (DoesNotExistException) {
			return true;
		}
	}

	private function transferFiles(IUser $shadow, IUser $target): bool {
		if ($this->hasNoFiles($shadow->getUID())) {
			return true;
		}

		if (!class_exists(self::OWNERSHIP_TRANSFER_SERVICE)) {
			$this->keep($shadow->getUID(), $target->getUID(), 'the Files app ownership transfer is unavailable');
			return false;
		}

		try {
			/** @psalm-suppress UndefinedClass */
			$service = \OCP\Server::get(self::OWNERSHIP_TRANSFER_SERVICE);
			// The whole home, into a "Transferred from ..." folder: moving it
			// to the root would need the target's home to be empty. Shares,
			// room shares included, are re-owned and keep their ids.
			$service->transfer($shadow, $target, '', new NullOutput(), false);

			return true;
		} catch (\Throwable $e) {
			$this->keep($shadow->getUID(), $target->getUID(), 'file transfer failed: ' . $e->getMessage());
			return false;
		}
	}

	protected function hasNoFiles(string $uid): bool {
		try {
			return $this->rootFolder->getUserFolder($uid)->getDirectoryListing() === [];
		} catch (\Throwable) {
			// An account whose home was never set up has nothing to move.
			return !$this->rootFolder->nodeExists('/' . $uid . '/files');
		}
	}

	private function keep(string $shadowUid, string $targetUid, string $reason): void {
		$this->logger->error('Kept the W3DS account folded into another at link time; finish by hand', [
			'shadowUid' => $shadowUid,
			'targetUid' => $targetUid,
			'reason' => $reason,
			'commands' => sprintf('occ files:transfer-ownership %s %s && occ user:delete %s', $shadowUid, $targetUid, $shadowUid),
		]);
	}
}
