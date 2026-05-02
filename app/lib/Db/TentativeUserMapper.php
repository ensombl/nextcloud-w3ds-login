<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TentativeUser>
 */
class TentativeUserMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'w3ds_tentative_users', TentativeUser::class);
	}

	public function markTentative(string $uid, int $expiresAt): void {
		try {
			$existing = $this->findByUid($uid);
			$existing->setExpiresAt($expiresAt);
			$this->update($existing);
		} catch (DoesNotExistException) {
			$row = new TentativeUser();
			$row->setUid($uid);
			$row->setExpiresAt($expiresAt);
			$this->insert($row);
		}
	}

	public function clear(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}

	public function isTentative(string $uid): bool {
		try {
			$this->findByUid($uid);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByUid(string $uid): TentativeUser {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)));
		return $this->findEntity($qb);
	}

	/**
	 * @return list<TentativeUser>
	 */
	public function findExpired(int $now): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lte('expires_at', $qb->createNamedParameter($now)));
		return $this->findEntities($qb);
	}
}
