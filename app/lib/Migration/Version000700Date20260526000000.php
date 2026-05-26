<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rename the W3DS-provisioned marker preference from `must_set_password` to
 * `provisioned`. The flag is no longer about forcing a password setup; it
 * remains as a tag so DedupeMappingsCommand::sweepOrphans() can still
 * identify NC accounts created by this app that lost the mapping-insert
 * race and have no row in w3ds_login_mappings.
 */
class Version000700Date20260526000000 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('preferences')
			->set('configkey', $qb->createNamedParameter('provisioned'))
			->where($qb->expr()->eq('appid', $qb->createNamedParameter('w3ds_login')))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter('must_set_password')));
		$affected = $qb->executeStatement();

		if ($affected > 0) {
			$output->info(sprintf('Renamed %d w3ds_login preference rows: must_set_password -> provisioned', $affected));
		}
	}
}
