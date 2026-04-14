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
