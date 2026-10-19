<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<W3dsMapping>
 */
class W3dsMappingMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'w3ds_login_mappings', W3dsMapping::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByW3id(string $w3id): W3dsMapping {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('w3id', $qb->createNamedParameter($w3id)));

		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByNcUid(string $ncUid): W3dsMapping {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('nc_uid', $qb->createNamedParameter($ncUid)));

		return $this->findEntity($qb);
	}

	public function deleteByNcUid(string $ncUid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('nc_uid', $qb->createNamedParameter($ncUid)));
		$qb->executeStatement();
	}

	/**
	 * Move $w3id from $fromUid to $toUid in one transaction, dropping any
	 * other mapping $toUid had. Returns false, changing nothing, when the
	 * eName is no longer mapped to $fromUid.
	 */
	public function reassign(string $w3id, string $fromUid, string $toUid): bool {
		$this->db->beginTransaction();
		try {
			$mapping = $this->findByW3id($w3id);
			if ($mapping->getNcUid() !== $fromUid) {
				$this->db->rollBack();
				return false;
			}
			$this->deleteByNcUid($toUid);
			$mapping->setNcUid($toUid);
			$this->update($mapping);
			$this->db->commit();

			return true;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function existsByW3id(string $w3id): bool {
		try {
			$this->findByW3id($w3id);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	/**
	 * @return W3dsMapping[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		return $this->findEntities($qb);
	}
}
