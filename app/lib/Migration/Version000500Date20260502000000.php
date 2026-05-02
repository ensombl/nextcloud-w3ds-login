<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Recreates the w3ds_tentative_users table for lazy provisioning via the
 * collaborator-search picker. Profiles surfaced in autocomplete results
 * are provisioned with a tentative row; AttendeesAddedTentativeFlipListener
 * clears it on add, TentativeUserCleanupJob deletes any still-tentative
 * accounts after their expiry.
 */
class Version000500Date20260502000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('w3ds_tentative_users')) {
			$table = $schema->createTable('w3ds_tentative_users');

			$table->addColumn('uid', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('expires_at', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);

			$table->setPrimaryKey(['uid']);
			$table->addIndex(['expires_at'], 'w3ds_tent_exp');
		}

		return $schema;
	}
}
