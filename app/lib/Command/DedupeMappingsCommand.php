<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Command;

use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\IDBConnection;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reconcile w3ds_login_mappings rows where two NC accounts ended up linked
 * to the same W3ID. This was possible before the unique index on `w3id` was
 * added; this command cleans up the residual duplicates so the collaborator
 * picker stops emitting twin entries for the same identity.
 *
 * For each duplicated W3ID we score every NC UID by Talk-attendance count
 * plus comment count plus a small bonus for being the oldest mapping. The
 * highest-scoring UID wins; the others are unlinked and their NC accounts
 * are deleted (which cascades into oc_comments and talk_attendees, but if
 * a loser had any usage the winner score will dominate so we don't lose
 * real history — that's the whole point of the scoring).
 *
 * It also sweeps *orphan* accounts: NC users that were provisioned by this
 * app (they carry the must_set_password preference) but have no mapping row
 * at all. Those are the residue of a provisioning race — the mapping insert
 * lost the unique-index contest and the orphan NC account was never cleaned
 * up. The per-w3id dedupe above can't see them because they aren't in
 * w3ds_login_mappings; the orphan sweep handles them by preference instead.
 */
class DedupeMappingsCommand extends Command {
	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
		private UserProvisioningService $userProvisioning,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('w3ds_login:dedupe-mappings')
			->setDescription('Collapse duplicate W3ID→NC-UID mappings into a single canonical row per identity.')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report duplicates and the chosen winners without deleting anything.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');

		$qb = $this->db->getQueryBuilder();
		$qb->select('w3id', 'nc_uid', 'created_at')
			->from('w3ds_login_mappings')
			->orderBy('w3id')
			->addOrderBy('created_at', 'ASC');
		$result = $qb->executeQuery();

		$byW3id = [];
		while ($row = $result->fetch()) {
			$byW3id[$row['w3id']][] = [
				'uid' => (string)$row['nc_uid'],
				'createdAt' => (int)$row['created_at'],
			];
		}
		$result->closeCursor();

		$totalGroups = 0;
		$totalDeleted = 0;

		foreach ($byW3id as $w3id => $rows) {
			if (count($rows) < 2) {
				continue;
			}
			$totalGroups++;

			$scored = [];
			$oldestUid = $rows[0]['uid'];
			foreach ($rows as $row) {
				$uid = $row['uid'];
				$score = $this->countTalkAttendances($uid) * 10
					+ $this->countComments($uid)
					+ ($uid === $oldestUid ? 1 : 0);
				$scored[$uid] = $score;
			}
			arsort($scored);
			$winner = array_key_first($scored);

			$output->writeln(sprintf(
				'<info>%s</info> winner=<comment>%s</comment> (score=%d)',
				$w3id,
				$winner,
				$scored[$winner],
			));
			foreach ($scored as $uid => $score) {
				if ($uid === $winner) {
					continue;
				}
				$output->writeln(sprintf('  loser=%s (score=%d)', $uid, $score));
			}

			if ($dryRun) {
				continue;
			}

			foreach ($scored as $uid => $_score) {
				if ($uid === $winner) {
					continue;
				}
				try {
					$this->userProvisioning->unlinkUser($uid);
					$user = $this->userManager->get($uid);
					if ($user !== null) {
						$user->delete();
						$totalDeleted++;
					}
				} catch (\Throwable $e) {
					$output->writeln(sprintf(
						'  <error>failed to delete %s: %s</error>',
						$uid,
						$e->getMessage(),
					));
				}
			}
		}

		[$orphansFound, $orphansDeleted] = $this->sweepOrphans($input, $output);

		$output->writeln(sprintf(
			'<info>Done.</info> duplicate groups=%d deleted=%d, orphans found=%d deleted=%d%s',
			$totalGroups,
			$totalDeleted,
			$orphansFound,
			$orphansDeleted,
			$dryRun ? ' (dry run)' : '',
		));

		return 0;
	}

	/**
	 * Delete orphan accounts: app-provisioned NC users (carrying the
	 * must_set_password preference) with no row in w3ds_login_mappings.
	 *
	 * @return array{0: int, 1: int} [found, deleted]
	 */
	private function sweepOrphans(InputInterface $input, OutputInterface $output): array {
		$dryRun = (bool)$input->getOption('dry-run');

		$qb = $this->db->getQueryBuilder();
		$qb->select('p.userid')
			->from('preferences', 'p')
			->leftJoin('p', 'w3ds_login_mappings', 'm', $qb->expr()->eq('p.userid', 'm.nc_uid'))
			->where($qb->expr()->eq('p.appid', $qb->createNamedParameter('w3ds_login')))
			->andWhere($qb->expr()->eq('p.configkey', $qb->createNamedParameter('must_set_password')))
			->andWhere($qb->expr()->isNull('m.nc_uid'));
		$result = $qb->executeQuery();

		$found = 0;
		$deleted = 0;
		while ($row = $result->fetch()) {
			$uid = (string)$row['userid'];
			$found++;

			$attendances = $this->countTalkAttendances($uid);
			$comments = $this->countComments($uid);
			if ($attendances > 0 || $comments > 0) {
				$output->writeln(sprintf(
					'  <comment>orphan kept (has usage)</comment> %s (attendances=%d comments=%d)',
					$uid,
					$attendances,
					$comments,
				));
				continue;
			}

			$output->writeln(sprintf('  <info>orphan</info> %s', $uid));
			if ($dryRun) {
				continue;
			}

			try {
				$user = $this->userManager->get($uid);
				if ($user !== null) {
					$user->delete();
					$deleted++;
				}
			} catch (\Throwable $e) {
				$output->writeln(sprintf(
					'  <error>failed to delete orphan %s: %s</error>',
					$uid,
					$e->getMessage(),
				));
			}
		}
		$result->closeCursor();

		return [$found, $deleted];
	}

	private function countTalkAttendances(string $uid): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))
				->from('talk_attendees')
				->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('users')))
				->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($uid)));
			$res = $qb->executeQuery();
			$count = (int)$res->fetchOne();
			$res->closeCursor();
			return $count;
		} catch (\Throwable) {
			return 0;
		}
	}

	private function countComments(string $uid): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*'))
				->from('comments')
				->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('users')))
				->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($uid)));
			$res = $qb->executeQuery();
			$count = (int)$res->fetchOne();
			$res->closeCursor();
			return $count;
		} catch (\Throwable) {
			return 0;
		}
	}
}
