<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<SyncCursor>
 */
class SyncCursorMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'w3ds_sync_cursors', SyncCursor::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findByW3idAndSchema(string $w3id, string $schemaId): SyncCursor {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('w3id', $qb->createNamedParameter($w3id)))
            ->andWhere($qb->expr()->eq('schema_id', $qb->createNamedParameter($schemaId)));

        return $this->findEntity($qb);
    }

    public function upsertCursor(string $w3id, string $schemaId, ?string $cursor): void {
        try {
            $entity = $this->findByW3idAndSchema($w3id, $schemaId);
            $entity->setCursor($cursor);
            $entity->setLastSyncAt(time());
            $this->update($entity);
        } catch (DoesNotExistException) {
            $entity = new SyncCursor();
            $entity->setW3id($w3id);
            $entity->setSchemaId($schemaId);
            $entity->setCursor($cursor);
            $entity->setLastSyncAt(time());
            $this->insert($entity);
        }
    }

    public function getCursor(string $w3id, string $schemaId): ?string {
        try {
            return $this->findByW3idAndSchema($w3id, $schemaId)->getCursor();
        } catch (DoesNotExistException) {
            return null;
        }
    }
}
