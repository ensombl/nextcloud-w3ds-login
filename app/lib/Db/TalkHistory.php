<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Keeps imported conversations in the order things were said.
 *
 * Talk orders a conversation by the id each entry got when it was written,
 * not by its timestamp. Anything written late therefore lands at the bottom:
 * history read from several eVaults, a message delivered after newer ones,
 * one only found when the conversation was opened. resequence() hands the
 * room's ids back out in time order; backdateShare() gives an imported file
 * the time it was sent.
 *
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

	/**
	 * Whether the newest entry in the room was sent after $at -- i.e. whether
	 * something sent at $at, written now, would land out of order.
	 */
	public function isOlderThanNewest(string $roomToken, \DateTimeInterface $at): bool {
		$roomId = $this->roomId($roomToken);
		if ($roomId === null) {
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('creation_timestamp'))
			->from('comments')
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$roomId)))
			->andWhere($qb->expr()->in('verb', $qb->createNamedParameter(self::REORDERED, IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$newest = $result->fetchOne();
		$result->closeCursor();

		return is_string($newest) && strtotime($newest) > $at->getTimestamp();
	}

	/**
	 * Put a room's entries back in the order they were sent.
	 *
	 * The ids the room already holds are handed out again by time, so the
	 * entries keep their text, authors and timestamps and only change place;
	 * every reference to an entry by id is moved with it. A room already in
	 * order is left untouched.
	 *
	 * @return int Entries that moved.
	 */
	public function resequence(string $roomToken): int {
		$roomId = $this->roomId($roomToken);
		if ($roomId === null) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'creation_timestamp')
			->from('comments')
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$roomId)))
			->andWhere($qb->expr()->in('verb', $qb->createNamedParameter(self::REORDERED, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('id');
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$moves = self::plan(array_map(static fn (array $r): array => [(int)$r['id'], (string)$r['creation_timestamp']], $rows));
		if ($moves === []) {
			return 0;
		}

		$this->db->beginTransaction();
		try {
			// Through temporary ids above every id in use, so no step collides
			// with an entry that has not moved yet. Not negative: id columns
			// are unsigned on MySQL and MariaDB.
			$temp = [];
			$next = $this->maxCommentId() + 1;
			foreach ($moves as $old => $new) {
				$temp[$old] = $next++;
			}

			foreach ([['comments', 'id'], ...self::REFERENCES] as [$table, $column]) {
				if (!$this->db->tableExists($table)) {
					continue;
				}
				foreach ($moves as $old => $new) {
					$this->setColumn($table, $column, $old, $temp[$old]);
				}
				foreach ($moves as $old => $new) {
					$this->setColumn($table, $column, $temp[$old], $new);
				}
			}

			$this->moveMappings($moves);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		$this->clearTalkCaches();

		return count($moves);
	}

	/**
	 * Which id each entry should have so that ids follow send time: the
	 * room's own ids, sorted, given to its entries sorted by time. Only
	 * entries that change are returned, as [old id => new id].
	 *
	 * @param list<array{0: int, 1: string}> $entries [id, sent at]
	 * @return array<int, int>
	 */
	public static function plan(array $entries): array {
		$ids = array_map(static fn (array $e): int => $e[0], $entries);
		sort($ids);

		$byTime = $entries;
		usort($byTime, static function (array $a, array $b): int {
			$order = strtotime($a[1]) <=> strtotime($b[1]);
			return $order !== 0 ? $order : $a[0] <=> $b[0];
		});

		$moves = [];
		foreach ($byTime as $i => [$id]) {
			if ($ids[$i] !== $id) {
				$moves[$id] = $ids[$i];
			}
		}

		return $moves;
	}

	/**
	 * What gets reordered: messages and files. System entries ("created the
	 * conversation", "added you") are dated when they were written, while
	 * imported messages carry when they were sent, so ordering those by time
	 * would move every notice below the whole imported history.
	 */
	private const REORDERED = ['comment', 'object_shared', 'comment_deleted'];

	/** Where a conversation with no messages sorts: last. */
	private const UNDATED = '2000-01-01 00:00:00 UTC';

	/** Every column that refers to a chat entry by id. */
	private const REFERENCES = [
		['comments', 'parent_id'],
		['comments', 'topmost_parent_id'],
		['reactions', 'parent_id'],
		['talk_attachments', 'message_id'],
		['talk_attendees', 'last_read_message'],
		['talk_attendees', 'last_mention_message'],
		['talk_attendees', 'last_mention_direct'],
		['talk_rooms', 'last_message'],
		['talk_reminders', 'message_id'],
		['talk_threads', 'id'],
		['talk_threads', 'last_message_id'],
		['talk_thread_attendees', 'thread_id'],
		['talk_scheduled_msg', 'parent_id'],
	];

	private function setColumn(string $table, string $column, int $from, int $to): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table)
			->set($column, $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq($column, $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Make the conversation list reflect what was last said.
	 *
	 * Talk sorts conversations by their last activity and previews their last
	 * message, and updates both whenever anything is written -- an imported
	 * message from last month, or a notice that someone was added -- so an
	 * imported conversation jumped to the top as if active right now. Both
	 * are set from the newest entry by the time it was sent.
	 */
	public function refreshRoomActivity(string $roomToken): void {
		$roomId = $this->roomId($roomToken);
		if ($roomId === null) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'creation_timestamp')
			->from('comments')
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$roomId)))
			->andWhere($qb->expr()->in('verb', $qb->createNamedParameter(self::REORDERED, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('creation_timestamp', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$newest = $result->fetch();
		$result->closeCursor();
		if ($newest === false) {
			// Nothing said here yet, or only before the history window. The
			// notice written when this server created the room is not
			// activity, and a conversation record's own dates change with
			// every membership edit, so neither says when anyone last spoke:
			// it goes below every conversation with messages, until somebody
			// writes in it.
			$qb = $this->db->getQueryBuilder();
			$qb->update('talk_rooms')
				->set('last_activity', $qb->createNamedParameter(new \DateTimeImmutable(self::UNDATED), IQueryBuilder::PARAM_DATE))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$this->clearTalkCaches();
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('talk_rooms')
			->set('last_message', $qb->createNamedParameter((int)$newest['id'], IQueryBuilder::PARAM_INT))
			->set('last_activity', $qb->createNamedParameter(new \DateTimeImmutable((string)$newest['creation_timestamp']), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->executeStatement();

		$this->clearTalkCaches();
	}

	/**
	 * The read marker for someone who has read every message sent up to
	 * $until, or none when null: the newest entry before the first message
	 * they have not read.
	 *
	 * Never 0 for a room with entries. Talk takes 0 as "never opened" and
	 * shows the conversation as unread whatever it holds, so someone who has
	 * read nothing is marked up to the notices before the first message --
	 * which Talk does not count as unread anyway.
	 */
	public function readMarkerUpTo(string $roomToken, ?\DateTimeInterface $until): ?int {
		$roomId = $this->roomId($roomToken);
		if ($roomId === null) {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->min('id'))
			->from('comments')
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$roomId)))
			->andWhere($qb->expr()->in('verb', $qb->createNamedParameter(self::REORDERED, IQueryBuilder::PARAM_STR_ARRAY)));
		if ($until !== null) {
			$until = \DateTimeImmutable::createFromInterface($until)->setTimezone(new \DateTimeZone('UTC'));
			$qb->andWhere($qb->expr()->gt('creation_timestamp', $qb->createNamedParameter($until, IQueryBuilder::PARAM_DATE)));
		}
		$result = $qb->executeQuery();
		$firstUnread = $result->fetchOne();
		$result->closeCursor();

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('id'))
			->from('comments')
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$roomId)));
		if ($firstUnread !== null && $firstUnread !== false) {
			$qb->andWhere($qb->expr()->lt('id', $qb->createNamedParameter((int)$firstUnread, IQueryBuilder::PARAM_INT)));
		}
		$result = $qb->executeQuery();
		$marker = $result->fetchOne();
		$result->closeCursor();
		if ($marker !== null && $marker !== false) {
			return (int)$marker;
		}

		// The first entry is itself unread: mark just before it.
		return $firstUnread !== null && $firstUnread !== false ? max(1, (int)$firstUnread - 1) : null;
	}

	/**
	 * The newest message or file this person posted in the room: they have
	 * read at least that far, whatever the receipts say.
	 */
	public function newestOwnEntry(string $roomToken, string $uid): ?int {
		$roomId = $this->roomId($roomToken);
		if ($roomId === null) {
			return null;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('id'))
			->from('comments')
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$roomId)))
			->andWhere($qb->expr()->in('verb', $qb->createNamedParameter(self::REORDERED, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('actor_type', $qb->createNamedParameter('users')))
			->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($uid)));
		$result = $qb->executeQuery();
		$id = $result->fetchOne();
		$result->closeCursor();

		return $id === null || $id === false ? null : (int)$id;
	}

	private function maxCommentId(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->max('id'))->from('comments');
		$result = $qb->executeQuery();
		$max = (int)$result->fetchOne();
		$result->closeCursor();

		return $max;
	}

	/**
	 * This app's own records naming entries by id: which envelope each
	 * mirrors, which comment shows a message's copies, and the texts each
	 * has shown.
	 *
	 * @param array<int, int> $moves
	 */
	private function moveMappings(array $moves): void {
		foreach ($moves as $old => $new) {
			$this->moveMapping((string)$old, 'resequence:' . $new);
		}
		foreach ($moves as $new) {
			$this->moveMapping('resequence:' . $new, (string)$new);
		}
	}

	private function moveMapping(string $from, string $to): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('w3ds_id_mappings')
			->set('local_id', $qb->createNamedParameter($to))
			->where($qb->expr()->in('entity_type', $qb->createNamedParameter(['message', 'message_comment'], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->eq('local_id', $qb->createNamedParameter($from)))
			->executeStatement();

		// Texts are keyed "<comment id>|<hash>", on both sides.
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'local_id')
			->from('w3ds_id_mappings')
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('message_text')))
			->andWhere($qb->expr()->like('local_id', $qb->createNamedParameter($this->db->escapeLikeParameter($from . '|') . '%')));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		foreach ($rows as $row) {
			$key = $to . substr((string)$row['local_id'], strlen($from));
			$qb = $this->db->getQueryBuilder();
			$qb->update('w3ds_id_mappings')
				->set('local_id', $qb->createNamedParameter($key))
				->set('global_id', $qb->createNamedParameter($key))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	/**
	 * Talk caches each room's last message id, unread counts and threads;
	 * all of them name entries by id.
	 */
	public function clearTalkCaches(): void {
		try {
			$factory = \OCP\Server::get(\OCP\ICacheFactory::class);
			$prefixes = ['talk.threads'];
			$cachePrefix = 'OCA\\Talk\\CachePrefix';
			foreach (['CHAT_LAST_MESSAGE_ID', 'CHAT_UNREAD_COUNT'] as $name) {
				if (defined($cachePrefix . '::' . $name)) {
					$prefixes[] = (string)constant($cachePrefix . '::' . $name);
				}
			}
			foreach ($prefixes as $prefix) {
				$factory->createDistributed($prefix)->clear();
			}
		} catch (\Throwable) {
			// Stale for at most their TTL.
		}
	}

	private function roomId(string $roomToken): ?int {
		if (!$this->db->tableExists('talk_rooms')) {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('talk_rooms')
			->where($qb->expr()->eq('token', $qb->createNamedParameter($roomToken)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$roomId = $result->fetchOne();
		$result->closeCursor();

		return $roomId === false ? null : (int)$roomId;
	}
}
