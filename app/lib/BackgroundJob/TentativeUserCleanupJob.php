<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\BackgroundJob;

use OCA\W3dsLogin\Db\TentativeUserMapper;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Sweeps tentative users whose expiry has passed without them being
 * added to a Talk room. Deletes the NC user account, the W3DS mapping,
 * and the tentative row.
 */
class TentativeUserCleanupJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private TentativeUserMapper $tentativeUserMapper,
		private UserProvisioningService $userProvisioning,
		private IUserManager $userManager,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	private function userHasRealUsage(string $uid): bool {
		// Any Talk attendance? (covers rooms they were added to AND the
		// system-attendance entries Talk creates when posting a message.)
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))
				->from('talk_attendees')
				->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('users')))
				->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($uid)))
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();
			if ($count > 0) {
				return true;
			}
		} catch (\Throwable) {
			// table may not exist (Talk uninstalled) -- fall through
		}

		// Any posted comment? (Talk messages live in oc_comments.)
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))
				->from('comments')
				->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('users')))
				->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($uid)))
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$count = (int)$result->fetchOne();
			$result->closeCursor();
			if ($count > 0) {
				return true;
			}
		} catch (\Throwable) {
			// fall through
		}

		return false;
	}

	protected function run(mixed $argument): void {
		try {
			$expired = $this->tentativeUserMapper->findExpired(time());
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Search] TentativeUserCleanupJob: findExpired failed', ['exception' => $e]);
			return;
		}

		$deleted = 0;
		foreach ($expired as $row) {
			$uid = $row->getUid();
			try {
				// Once a tentative account has been used for anything real
				// (Talk attendance OR a posted comment), deleting it would
				// cascade-wipe their chat history. Just clear the flag and
				// move on -- the AttendeesAddedTentativeFlipListener should
				// have caught attendance, but the chat-poll path resolves
				// senders via findByW3id without going through that event,
				// so we re-check here as a safety net.
				if ($this->userHasRealUsage($uid)) {
					$this->tentativeUserMapper->clear($uid);
					continue;
				}

				$this->userProvisioning->unlinkUser($uid);

				$user = $this->userManager->get($uid);
				if ($user !== null) {
					$user->delete();
					$deleted++;
				}

				$this->tentativeUserMapper->clear($uid);
			} catch (\Throwable $e) {
				$this->logger->warning('[W3DS Search] TentativeUserCleanupJob: failed to delete tentative user', [
					'uid' => $uid,
					'exception' => $e->getMessage(),
				]);
			}
		}

		if ($deleted > 0) {
			$this->logger->info('[W3DS Search] TentativeUserCleanupJob: deleted expired tentative users', ['count' => $deleted]);
		}
	}
}
