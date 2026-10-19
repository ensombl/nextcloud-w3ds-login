<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\BackgroundJob\AbsorbShadowAccountJob;
use OCA\W3dsLogin\Db\TalkAccountMigrator;
use OCA\W3dsLogin\Db\TentativeUserMapper;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Folds the account this app made for an eName into the account its owner
 * actually signs in with.
 *
 * Someone's eName gets an account here long before they sign in through an
 * identity provider: at a wallet login, when they turn up in a synced chat,
 * when somebody searches for them. When they later link their IdP account
 * with one wallet scan, that older account holds the eName, and their
 * conversations. Refusing the link leaves them locked out of their own
 * history; this moves it over instead.
 *
 * Only an account this app created, for this eName, may be folded. Anything
 * else -- an admin, an account made by hand or by another backend, one with
 * calendars or contacts that nothing here would carry over -- stays put, and
 * the link is refused with an explanation.
 */
class AccountMergeService {
	/** Per-user config key on a folded account, naming the one it went into. */
	public const MERGED_INTO_KEY = 'merged_into';

	public function __construct(
		private W3dsMappingMapper $mapper,
		private IUserManager $userManager,
		private IGroupManager $groupManager,
		private IConfig $config,
		private IDBConnection $db,
		private TentativeUserMapper $tentativeUserMapper,
		private TalkAccountMigrator $talkMigrator,
		private IJobList $jobList,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Move $w3id from $shadowUid to $targetUid and schedule the old account
	 * to be emptied and deleted.
	 *
	 * The caller must already have proven that the person linking holds the
	 * eName, by its wallet signature.
	 *
	 * @throws \RuntimeException With a message for the user, when the account
	 *                           holding the eName may not be taken over
	 */
	public function takeOver(string $w3id, string $shadowUid, string $targetUid): void {
		$shadow = $this->userManager->get($shadowUid);
		$target = $this->userManager->get($targetUid);
		$refusal = $this->refusalReason($shadow, $target);
		if ($refusal !== null || $shadow === null || $target === null) {
			$this->logger->warning('W3DS link: not taking over the account holding this eName', [
				'w3id' => $w3id,
				'shadowUid' => $shadowUid,
				'targetUid' => $targetUid,
				'reason' => $refusal,
			]);
			throw new \RuntimeException(
				'This W3DS identity is already linked to another Nextcloud account. Ask an administrator to unlink it.',
			);
		}

		// The mapping first, in one step: from here on every inbound chat
		// and every lookup for this eName lands on the account that stays.
		try {
			$moved = $this->mapper->reassign($w3id, $shadowUid, $targetUid);
		} catch (\Throwable $e) {
			throw new \RuntimeException('Could not link this W3DS identity. Try again.', 0, $e);
		}
		if (!$moved) {
			throw new \RuntimeException('The W3DS identity changed hands while linking. Try again.');
		}

		$this->tentativeUserMapper->clear($shadowUid);
		$this->config->setUserValue($shadowUid, Application::APP_ID, self::MERGED_INTO_KEY, $targetUid);

		$this->logger->info('W3DS link: took over the account this app made for the eName', [
			'w3id' => $w3id,
			'shadowUid' => $shadowUid,
			'targetUid' => $targetUid,
		]);

		try {
			$this->talkMigrator->migrate($shadowUid, $targetUid, $target->getDisplayName());
		} catch (\Throwable $e) {
			// The link itself stands. The old account keeps its rooms and is
			// left in place, so nothing is lost; an admin can retry.
			$this->logger->warning('W3DS link: could not move Talk conversations from the old account; keeping it', [
				'shadowUid' => $shadowUid,
				'targetUid' => $targetUid,
				'exception' => $e->getMessage(),
			]);
			return;
		}

		// Moving files can take minutes, and the wallet is waiting on this
		// response.
		$this->jobList->add(AbsorbShadowAccountJob::class, [
			'shadow' => $shadowUid,
			'target' => $targetUid,
			'w3id' => $w3id,
		]);
	}

	/**
	 * Why $shadow may not be folded into $target, or null when it may.
	 */
	public function refusalReason(?IUser $shadow, ?IUser $target): ?string {
		if ($shadow === null || $target === null) {
			return 'account missing';
		}
		if ($shadow->getUID() === $target->getUID()) {
			return 'same account';
		}
		if ($this->config->getUserValue($shadow->getUID(), Application::APP_ID, UserProvisioningService::PROVISIONED_KEY, '') !== '1') {
			return 'not created by this app';
		}
		if ($this->groupManager->isAdmin($shadow->getUID())) {
			return 'administrator';
		}
		if ($this->hasCalendarOrContactData($shadow->getUID())) {
			return 'has calendars or contacts';
		}

		return null;
	}

	/**
	 * Files and Talk move with the account; calendars and contacts do not.
	 * Nextcloud creates an empty calendar and address book for everyone, so
	 * only actual entries count.
	 */
	protected function hasCalendarOrContactData(string $uid): bool {
		$principal = 'principals/users/' . $uid;

		foreach ([['calendars', 'calendarobjects', 'calendarid'], ['addressbooks', 'cards', 'addressbookid']] as [$collections, $objects, $fk]) {
			try {
				$qb = $this->db->getQueryBuilder();
				$qb->select('o.id')
					->from($objects, 'o')
					->innerJoin('o', $collections, 'c', $qb->expr()->eq('o.' . $fk, 'c.id'))
					->where($qb->expr()->eq('c.principaluri', $qb->createNamedParameter($principal)))
					->setMaxResults(1);
				$result = $qb->executeQuery();
				$found = $result->fetchOne() !== false;
				$result->closeCursor();
				if ($found) {
					return true;
				}
			} catch (\Throwable) {
				// No DAV tables (unusual): nothing there to lose.
			}
		}

		return false;
	}
}
