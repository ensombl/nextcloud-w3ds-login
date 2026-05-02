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
		return $this->readInTx(fn () => $this->findByLocalId($entityType, $localId)->getGlobalId());
	}

	public function getLocalId(string $entityType, string $globalId): ?string {
		return $this->readInTx(fn () => $this->findByGlobalId($entityType, $globalId)->getLocalId());
	}

	/**
	 * NC33 throws "dirty table reads" if we SELECT from w3ds_id_mappings after
	 * any earlier write to it in the same request (Talk room/message listeners
	 * fire storeMapping during the same HTTP request that pollRoom runs in).
	 * Wrapping the read in a transaction makes the prior writes visible and
	 * silences the guard.
	 */
	private function readInTx(callable $read): ?string {
		$this->db->beginTransaction();
		try {
			$result = $read();
			$this->db->commit();
			return $result;
		} catch (DoesNotExistException) {
			$this->db->commit();
			return null;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function storeMapping(string $entityType, string $localId, string $globalId, string $ownerW3id, string $origin = 'local'): IdMapping {
		$now = time();
		$mapping = new IdMapping();
		$mapping->setEntityType($entityType);
		$mapping->setLocalId($localId);
		$mapping->setGlobalId($globalId);
		$mapping->setOwnerW3id($ownerW3id);
		$mapping->setCreatedAt($now);
		$mapping->setUpdatedAt($now);
		$mapping->setOrigin($origin);

		return $this->insert($mapping);
	}

	/**
	 * Read the `origin` column for a mapping. Returns null if no row matches.
	 * Used by ChatSyncService::pushChat to short-circuit loopback when a
	 * locally-created room turns out to be a mirror of an inbound MetaEnvelope.
	 */
	public function getOrigin(string $entityType, string $localId): ?string {
		return $this->readInTx(fn () => $this->findByLocalId($entityType, $localId)->getOrigin());
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
