<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Which eVaults hold a conversation's messages.
 *
 * A message is not stored with whoever reads it. Platforms write it to the
 * conversation's own eVault when it has one (a group with an eName), and
 * otherwise to the sender's, or to each member's. So fetching one
 * conversation's history means asking every one of those: the conversation's
 * own eVault, the eVault the conversation itself came from, and every
 * participant's.
 *
 * Participants come from two places: the people actually in the Talk room,
 * and the eNames the conversation named when it arrived -- which includes
 * the ones nobody here has an account for, and the group's own eName, which
 * no room membership would reveal.
 */
class ChatVaults {
	/**
	 * Rows remembering a room's eVaults, kept in the id-mapping table. The
	 * table is unique per global id, and one eName belongs to many rooms, so
	 * both ids are the pair "<room token>|<eName>".
	 */
	private const ENTITY = 'chat_vault';

	public function __construct(
		private IDBConnection $db,
		private IdMappingMapper $idMappingMapper,
	) {
	}

	/**
	 * Note eVaults a conversation named, for later history fetches.
	 *
	 * @param iterable<string> $w3ids
	 */
	public function remember(string $roomToken, iterable $w3ids): void {
		foreach ($w3ids as $w3id) {
			if (!is_string($w3id) || !str_starts_with($w3id, '@')) {
				continue;
			}
			$key = $roomToken . '|' . $w3id;
			if ($this->idMappingMapper->getGlobalId(self::ENTITY, $key) !== null) {
				continue;
			}
			try {
				$this->idMappingMapper->storeMapping(self::ENTITY, $key, $key, $w3id, 'inbound');
			} catch (\Throwable) {
				// Recorded concurrently; either row is the same fact.
			}
		}
	}

	/**
	 * Every eVault that may hold messages of this room.
	 *
	 * @return list<string> eNames, `@`-prefixed
	 */
	public function forRoom(string $roomToken): array {
		$vaults = [];

		// The eVault the conversation came from.
		$qb = $this->db->getQueryBuilder();
		$qb->select('owner_w3id')
			->from('w3ds_id_mappings')
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('chat')))
			->andWhere($qb->expr()->eq('local_id', $qb->createNamedParameter($roomToken)));
		$vaults = array_merge($vaults, $this->column($qb));

		// The group's own eVault and the participants it named.
		$qb = $this->db->getQueryBuilder();
		$qb->select('owner_w3id')
			->from('w3ds_id_mappings')
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter(self::ENTITY)))
			->andWhere($qb->expr()->like('local_id', $qb->createNamedParameter($this->db->escapeLikeParameter($roomToken . '|') . '%')));
		$vaults = array_merge($vaults, $this->column($qb));

		// Everyone in the room who has an eName.
		if ($this->db->tableExists('talk_attendees')) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.w3id')
				->from('talk_attendees', 'a')
				->innerJoin('a', 'talk_rooms', 'r', $qb->expr()->eq('a.room_id', 'r.id'))
				->innerJoin('a', 'w3ds_login_mappings', 'm', $qb->expr()->eq('a.actor_id', 'm.nc_uid'))
				->where($qb->expr()->eq('r.token', $qb->createNamedParameter($roomToken)))
				->andWhere($qb->expr()->eq('a.actor_type', $qb->createNamedParameter('users')));
			$vaults = array_merge($vaults, $this->column($qb));
		}

		return self::normalise($vaults);
	}

	/**
	 * Tokens of the synced rooms this user is in.
	 *
	 * @return list<string>
	 */
	public function roomsOf(string $uid): array {
		if (!$this->db->tableExists('talk_attendees')) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('r.token')
			->from('talk_attendees', 'a')
			->innerJoin('a', 'talk_rooms', 'r', $qb->expr()->eq('a.room_id', 'r.id'))
			->innerJoin('r', 'w3ds_id_mappings', 'c', $qb->expr()->andX(
				$qb->expr()->eq('c.local_id', 'r.token'),
				$qb->expr()->eq('c.entity_type', $qb->createNamedParameter('chat')),
			))
			->where($qb->expr()->eq('a.actor_type', $qb->createNamedParameter('users')))
			->andWhere($qb->expr()->eq('a.actor_id', $qb->createNamedParameter($uid)));

		return array_values(array_unique($this->column($qb)));
	}

	/**
	 * @param list<string> $vaults
	 * @return list<string>
	 */
	public static function normalise(array $vaults): array {
		$out = [];
		foreach ($vaults as $vault) {
			$vault = trim($vault);
			if ($vault === '') {
				continue;
			}
			if (!str_starts_with($vault, '@')) {
				$vault = '@' . $vault;
			}
			$out[strtolower($vault)] ??= $vault;
		}

		return array_values($out);
	}

	/**
	 * @return list<string>
	 */
	private function column(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$values = [];
		while (($value = $result->fetchOne()) !== false) {
			if (is_string($value) && $value !== '') {
				$values[] = $value;
			}
		}
		$result->closeCursor();

		return $values;
	}
}
