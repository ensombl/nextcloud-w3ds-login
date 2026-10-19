<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * How far each slice of history has been read, so the next read asks the
 * awareness service only for what arrived since.
 *
 * The service filters by the time it received a packet, so a mark is the
 * moment a complete read began; the next read starts there, less a margin
 * for packets still in flight. A read that stopped short sets no mark and is
 * repeated from the previous one.
 *
 * Kept in the id-mapping table, which already carries a timestamp per row.
 */
class FetchMarks {
	private const ENTITY = 'fetch_mark';

	/** Re-read this much before a mark, for packets received out of order. */
	public const OVERLAP = 600;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Where to start reading $key from: the mark less the overlap, but never
	 * before $earliest. Null $earliest means "from the beginning" when there
	 * is no mark.
	 */
	public function from(string $key, ?int $earliest): ?string {
		$mark = $this->get($key);
		$start = $mark !== null ? $mark - self::OVERLAP : $earliest;
		if ($start !== null && $earliest !== null) {
			$start = max($start, $earliest);
		}

		return $start === null ? null : gmdate('Y-m-d\TH:i:s\Z', $start);
	}

	public function get(string $key): ?int {
		$qb = $this->db->getQueryBuilder();
		$qb->select('updated_at')
			->from('w3ds_id_mappings')
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter(self::ENTITY)))
			->andWhere($qb->expr()->eq('local_id', $qb->createNamedParameter($key)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();

		return $value === false ? null : (int)$value;
	}

	public function set(string $key, int $at): void {
		$qb = $this->db->getQueryBuilder();
		$updated = $qb->update('w3ds_id_mappings')
			->set('updated_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter(self::ENTITY)))
			->andWhere($qb->expr()->eq('local_id', $qb->createNamedParameter($key)))
			->executeStatement();
		if ($updated > 0) {
			return;
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('w3ds_id_mappings')
				->values([
					'entity_type' => $qb->createNamedParameter(self::ENTITY),
					'local_id' => $qb->createNamedParameter($key),
					'global_id' => $qb->createNamedParameter($key),
					'owner_w3id' => $qb->createNamedParameter(''),
					'created_at' => $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT),
					'updated_at' => $qb->createNamedParameter($at, IQueryBuilder::PARAM_INT),
					'origin' => $qb->createNamedParameter('local'),
				])
				->executeStatement();
		} catch (\Throwable) {
			// Written concurrently: either value is a valid mark.
		}
	}

	/** Forget every mark, so the next read covers the whole window again. */
	public function clear(?string $prefix = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('w3ds_id_mappings')
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter(self::ENTITY)));
		if ($prefix !== null) {
			$qb->andWhere($qb->expr()->like('local_id', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%')));
		}
		$qb->executeStatement();
	}
}
