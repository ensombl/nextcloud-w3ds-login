<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Moves one account's place in Talk to another account: room memberships,
 * one-to-one conversations, authored messages, mentions, reactions and read
 * markers.
 *
 * Written against the tables rather than Talk's API on purpose. Removing and
 * re-adding a participant through the API resets their role, posts "added"
 * and "removed" messages into every room, and fires the roster events this
 * app pushes to the eVault -- a merge would announce itself to every peer.
 * Rewriting the rows in place keeps attendee ids, and with them roles, read
 * state and notification levels, and fires nothing.
 *
 * Idempotent: running it again after a partial run, or after new rows were
 * written for the old account, only moves what is still left.
 */
class TalkAccountMigrator {
	private const ONE_TO_ONE = 1;
	private const BATCH = 500;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function migrate(string $fromUid, string $toUid, string $toDisplayName): void {
		if ($fromUid === $toUid) {
			return;
		}

		$this->db->beginTransaction();
		try {
			if ($this->db->tableExists('talk_attendees')) {
				$this->moveAttendees($fromUid, $toUid, $toDisplayName);
			}
			if ($this->db->tableExists('talk_rooms')) {
				$this->renameOneToOneRooms($fromUid, $toUid);
			}
			$this->moveAuthoredComments($fromUid, $toUid);
			$this->rewriteMentionsInChats($fromUid, $toUid);
			$this->moveReactions($fromUid, $toUid);
			$this->moveReadMarkers($fromUid, $toUid);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * A one-to-one room is named by the JSON list of its two users, sorted.
	 */
	public static function rewriteOneToOneName(string $name, string $from, string $to): string {
		$users = json_decode($name, true);
		if (!is_array($users) || !in_array($from, $users, true)) {
			return $name;
		}

		$users = array_map(static fn ($u) => $u === $from ? $to : $u, $users);
		sort($users);

		return json_encode($users) ?: $name;
	}

	/**
	 * Talk writes a mention as `@uid`, or `@"uid"` when the uid has
	 * characters a bare mention would end at. A bare mention only matches
	 * the whole uid, never the front of a longer one.
	 */
	public static function rewriteMentions(string $message, string $from, string $to): string {
		$message = str_replace('@"' . $from . '"', '@"' . $to . '"', $message);

		return (string)preg_replace(
			'/@' . preg_quote($from, '/') . '(?![A-Za-z0-9_.@\-])/',
			'@"' . addcslashes($to, '\\$') . '"',
			$message,
		);
	}

	private function moveAttendees(string $from, string $to, string $toDisplayName): void {
		$rows = $this->selectAll('talk_attendees', ['id', 'room_id'], $this->eqActor('actor_id', $from));
		foreach ($rows as $row) {
			$roomId = (int)$row['room_id'];
			$attendeeId = (int)$row['id'];

			if ($this->targetAttends($roomId, $to)) {
				// Already a member in their own right: keep that membership
				// and drop the duplicate along with its live sessions.
				if ($this->db->tableExists('talk_sessions')) {
					$qb = $this->db->getQueryBuilder();
					$qb->delete('talk_sessions')
						->where($qb->expr()->eq('attendee_id', $qb->createNamedParameter($attendeeId, IQueryBuilder::PARAM_INT)))
						->executeStatement();
				}
				$qb = $this->db->getQueryBuilder();
				$qb->delete('talk_attendees')
					->where($qb->expr()->eq('id', $qb->createNamedParameter($attendeeId, IQueryBuilder::PARAM_INT)))
					->executeStatement();
				continue;
			}

			$qb = $this->db->getQueryBuilder();
			$qb->update('talk_attendees')
				->set('actor_id', $qb->createNamedParameter($to))
				->set('display_name', $qb->createNamedParameter($toDisplayName))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($attendeeId, IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	private function targetAttends(int $roomId, string $to): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('talk_attendees')
			->where($qb->expr()->eq('room_id', $qb->createNamedParameter($roomId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('actor_type', $qb->createNamedParameter('users')))
			->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($to)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();

		return $found;
	}

	private function renameOneToOneRooms(string $from, string $to): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name')
			->from('talk_rooms')
			->where($qb->expr()->eq('type', $qb->createNamedParameter(self::ONE_TO_ONE, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->like('name', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter(json_encode($from) ?: $from) . '%')));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		foreach ($rows as $row) {
			$name = self::rewriteOneToOneName((string)$row['name'], $from, $to);
			if ($name === $row['name'] || $this->oneToOneExists($name)) {
				// The two already have a conversation of their own; this one
				// becomes a former one-to-one when the old account is deleted.
				continue;
			}

			$qb = $this->db->getQueryBuilder();
			$qb->update('talk_rooms')
				->set('name', $qb->createNamedParameter($name))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	private function oneToOneExists(string $name): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('talk_rooms')
			->where($qb->expr()->eq('type', $qb->createNamedParameter(self::ONE_TO_ONE, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('name', $qb->createNamedParameter($name)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$found = $result->fetchOne() !== false;
		$result->closeCursor();

		return $found;
	}

	/**
	 * Without this, deleting the old account turns its whole history into
	 * messages from "Deleted user". Outbound sync is keyed by comment id, so
	 * nothing is re-sent.
	 */
	private function moveAuthoredComments(string $from, string $to): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update('comments')
			->set('actor_id', $qb->createNamedParameter($to))
			->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('users')))
			->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($from)))
			->executeStatement();
	}

	private function rewriteMentionsInChats(string $from, string $to): void {
		$lastId = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'message')
				->from('comments')
				->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
				->andWhere($qb->expr()->like('message', $qb->createNamedParameter('%@%' . $this->db->escapeLikeParameter($from) . '%')))
				->andWhere($qb->expr()->gt('id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
				->orderBy('id')
				->setMaxResults(self::BATCH);
			$result = $qb->executeQuery();
			$rows = $result->fetchAll();
			$result->closeCursor();

			foreach ($rows as $row) {
				$lastId = (int)$row['id'];
				$message = (string)$row['message'];
				$rewritten = self::rewriteMentions($message, $from, $to);
				if ($rewritten === $message) {
					continue;
				}
				$update = $this->db->getQueryBuilder();
				$update->update('comments')
					->set('message', $update->createNamedParameter($rewritten))
					->where($update->expr()->eq('id', $update->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
					->executeStatement();
			}
		} while (count($rows) === self::BATCH);
	}

	/**
	 * One reaction per person, emoji and message: a reaction both accounts
	 * left is kept once, under the account that stays.
	 */
	private function moveReactions(string $from, string $to): void {
		if (!$this->db->tableExists('reactions')) {
			return;
		}

		$rows = $this->selectAll('reactions', ['id', 'parent_id', 'reaction'], $this->eqActor('actor_id', $from));
		foreach ($rows as $row) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from('reactions')
				->where($qb->expr()->eq('parent_id', $qb->createNamedParameter((int)$row['parent_id'], IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('actor_type', $qb->createNamedParameter('users')))
				->andWhere($qb->expr()->eq('actor_id', $qb->createNamedParameter($to)))
				->andWhere($qb->expr()->eq('reaction', $qb->createNamedParameter((string)$row['reaction'])))
				->setMaxResults(1);
			$result = $qb->executeQuery();
			$duplicate = $result->fetchOne() !== false;
			$result->closeCursor();

			$write = $this->db->getQueryBuilder();
			if ($duplicate) {
				$write->delete('reactions');
			} else {
				$write->update('reactions')->set('actor_id', $write->createNamedParameter($to));
			}
			$write->where($write->expr()->eq('id', $write->createNamedParameter((int)$row['id'], IQueryBuilder::PARAM_INT)))
				->executeStatement();
		}
	}

	private function moveReadMarkers(string $from, string $to): void {
		$qb = $this->db->getQueryBuilder();
		$qb->select('object_type', 'object_id')
			->from('comments_read_markers')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($from)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		foreach ($rows as $row) {
			$match = function (IQueryBuilder $qb, string $uid) use ($row): IQueryBuilder {
				return $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
					->andWhere($qb->expr()->eq('object_type', $qb->createNamedParameter((string)$row['object_type'])))
					->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$row['object_id'])));
			};

			$exists = $this->db->getQueryBuilder();
			$exists->select('user_id')->from('comments_read_markers');
			$result = $match($exists, $to)->setMaxResults(1)->executeQuery();
			$duplicate = $result->fetchOne() !== false;
			$result->closeCursor();

			$write = $this->db->getQueryBuilder();
			if ($duplicate) {
				$write->delete('comments_read_markers');
			} else {
				$write->update('comments_read_markers')->set('user_id', $write->createNamedParameter($to));
			}
			$match($write, $from)->executeStatement();
		}
	}

	/**
	 * @return callable(IQueryBuilder): void
	 */
	private function eqActor(string $column, string $uid): callable {
		return static function (IQueryBuilder $qb) use ($column, $uid): void {
			$qb->where($qb->expr()->eq('actor_type', $qb->createNamedParameter('users')))
				->andWhere($qb->expr()->eq($column, $qb->createNamedParameter($uid)));
		};
	}

	/**
	 * @param list<string> $columns
	 * @param callable(IQueryBuilder): void $filter
	 * @return list<array<string, mixed>>
	 */
	private function selectAll(string $table, array $columns, callable $filter): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(...$columns)->from($table);
		$filter($qb);
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		return $rows;
	}
}
