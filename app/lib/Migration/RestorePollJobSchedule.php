<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Migration;

use OCA\W3dsLogin\BackgroundJob\AwarenessSyncJob;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Let the awareness poll run on every cron run again.
 *
 * Until 0.11 the poll was declared time-insensitive, and Nextcloud records
 * that on the job's row the first time it runs -- and never takes it back.
 * On an instance with a maintenance window, the poll therefore kept running
 * only inside that window, so incoming messages waited for the night.
 */
class RestorePollJobSchedule implements IRepairStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function getName(): string {
		return 'Run the W3DS awareness poll outside the maintenance window';
	}

	public function run(IOutput $output): void {
		$qb = $this->db->getQueryBuilder();
		$changed = $qb->update('jobs')
			->set('time_sensitive', $qb->createNamedParameter(1))
			->where($qb->expr()->eq('class', $qb->createNamedParameter(AwarenessSyncJob::class)))
			->andWhere($qb->expr()->eq('time_sensitive', $qb->createNamedParameter(0)))
			->executeStatement();
		if ($changed > 0) {
			$output->info('The awareness poll runs on every cron run again');
		}
	}
}
