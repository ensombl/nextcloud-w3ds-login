<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops the short-lived w3ds_tentative_users table introduced in the
 * abandoned ISearchPlugin approach. The "Add W3DS users" button replaces
 * that flow with explicit on-add provisioning, so the table is unused.
 */
class Version000400Date20260502000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('w3ds_tentative_users')) {
			/** @psalm-suppress UndefinedDocblockClass */
			$schema->dropTable('w3ds_tentative_users');
		}

		return $schema;
	}
}
