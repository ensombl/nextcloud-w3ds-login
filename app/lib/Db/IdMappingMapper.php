<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<IdMapping>
 */
class IdMappingMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'w3ds_id_mappings', IdMapping::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByLocalId(string $entityType, string $localId): IdMapping {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
			->andWhere($qb->expr()->eq('local_id', $qb->createNamedParameter($localId)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByGlobalId(string $entityType, string $globalId): IdMapping {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
			->andWhere($qb->expr()->eq('global_id', $qb->createNamedParameter($globalId)));

		return $this->findEntity($qb);
	}

	public function getGlobalId(string $entityType, string $localId): ?string {
		try {
			return $this->findByLocalId($entityType, $localId)->getGlobalId();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function getLocalId(string $entityType, string $globalId): ?string {
		try {
			return $this->findByGlobalId($entityType, $globalId)->getLocalId();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function storeMapping(string $entityType, string $localId, string $globalId, string $ownerW3id): IdMapping {
		$now = time();
		$mapping = new IdMapping();
		$mapping->setEntityType($entityType);
		$mapping->setLocalId($localId);
		$mapping->setGlobalId($globalId);
		$mapping->setOwnerW3id($ownerW3id);
		$mapping->setCreatedAt($now);
		$mapping->setUpdatedAt($now);

		return $this->insert($mapping);
	}

	/**
	 * @return IdMapping[]
	 */
	public function findAllByOwner(string $ownerW3id, string $entityType): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_w3id', $qb->createNamedParameter($ownerW3id)))
			->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));

		return $this->findEntities($qb);
	}
}
