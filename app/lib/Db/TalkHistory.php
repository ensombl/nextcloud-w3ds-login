<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Puts imported attachments at the time they were sent.
 *
 * Talk posts a file share's chat entry at the moment the share is created,
 * and offers no way to say otherwise. Text can be posted with its original
 * time; a picture imported from someone's history therefore showed up as
 * sent today, below everything else. Writing the original time onto the
 * share and its chat entry is the only way to give it the right date.
 */
class TalkHistory {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function backdateShare(int $shareId, string $roomToken, \DateTimeInterface $at): void {
		if (!$this->db->tableExists('talk_rooms')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('share')
			->set('stime', $qb->createNamedParameter($at->getTimestamp(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('talk_rooms')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($roomToken)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$roomId = $result->fetchOne();
		$result->closeCursor();
		if ($roomId === false) {
			return;
		}

		// The entry names its share in its parameters: {"share":"116",...}.
		$qb = $this->db->getQueryBuilder();
		$qb->update('comments')
			->set('creation_timestamp', $qb->createNamedParameter($at, IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$roomId)))
			->andWhere($qb->expr()->eq('verb', $qb->createNamedParameter('object_shared')))
			->andWhere($qb->expr()->like('message', $qb->createNamedParameter('%"share":"' . $shareId . '"%')))
			->executeStatement();
	}

	/**
	 * Whether the room already has an entry from this person at this moment:
	 * a message with this text, or a file share. Used to recognise a copy of
	 * a message that was imported before copies could be told apart by id.
	 */
	public function hasEntry(string $roomToken, string $actorId, \DateTimeInterface $at, ?string $text): bool {
		if (!$this->db->tableExists('talk_rooms')) {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('talk_rooms')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($roomToken)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$roomId = $result->fetchOne();
		$result->closeCursor();
		if ($roomId === false) {
			return false;
		}

		$from = \DateTimeImmutable::createFromInterface($at)->modify('-2 seconds');
		$to = \DateTimeImmutable::createFromInterface($at)->modify('+2 seconds');

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('comments')
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$roomId)))
			->andWhere($qb->expr()->eq('actor_type', $qb->createNamedParameter('users')))
			->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($actorId)))
			->andWhere($qb->expr()->gte('creation_timestamp', $qb->createNamedParameter($from, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('creation_timestamp', $qb->createNamedParameter($to, IQueryBuilder::PARAM_DATE)))
			->setMaxResults(1);
		if ($text === null) {
			$qb->andWhere($qb->expr()->eq('verb', $qb->createNamedParameter('object_shared')));
		} else {
			$qb->andWhere($qb->expr()->eq('verb', $qb->createNamedParameter('comment')))
				->andWhere($qb->expr()->eq('message', $qb->createNamedParameter($text)));
		}
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();

		return $found;
	}
}
