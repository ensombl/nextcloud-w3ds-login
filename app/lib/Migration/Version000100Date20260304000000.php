<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version000100Date20260304000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('w3ds_login_mappings')) {
            $table = $schema->createTable('w3ds_login_mappings');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('w3id', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('nc_uid', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('created_at', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['w3id'], 'w3ds_login_w3id_idx');
            $table->addUniqueIndex(['nc_uid'], 'w3ds_login_ncuid_idx');
        }

        return $schema;
    }
}
