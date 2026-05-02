<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds an `origin` column to `w3ds_id_mappings` so we can distinguish
 * mappings created by ingesting an inbound MetaEnvelope (a peer's chat
 * mirrored locally) from mappings created by pushing a locally-authored
 * entity outbound. ChatSyncService::pushChat consults this to short-circuit
 * loopback when Talk listeners fire on the rooms we just created from
 * pullSyncForUser.
 */
class Version000600Date20260502000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('w3ds_id_mappings')) {
			$table = $schema->getTable('w3ds_id_mappings');
			if (!$table->hasColumn('origin')) {
				$table->addColumn('origin', Types::STRING, [
					'notnull' => true,
					'length' => 16,
					'default' => 'local',
				]);
			}
		}

		return $schema;
	}
}
