<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000200Date20260410000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('w3ds_id_mappings')) {
            $table = $schema->createTable('w3ds_id_mappings');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('entity_type', Types::STRING, [
                'notnull' => true,
                'length' => 32,
            ]);
            $table->addColumn('local_id', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('global_id', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('owner_w3id', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('updated_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['entity_type', 'local_id'], 'w3ds_idmap_type_local');
            $table->addUniqueIndex(['entity_type', 'global_id'], 'w3ds_idmap_type_global');
            $table->addIndex(['owner_w3id'], 'w3ds_idmap_owner');
        }

        if (!$schema->hasTable('w3ds_sync_cursors')) {
            $table = $schema->createTable('w3ds_sync_cursors');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('w3id', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('schema_id', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('cursor', Types::STRING, [
                'notnull' => false,
                'length' => 512,
            ]);
            $table->addColumn('last_sync_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['w3id', 'schema_id'], 'w3ds_sync_w3id_schema');
        }

        return $schema;
    }
}
