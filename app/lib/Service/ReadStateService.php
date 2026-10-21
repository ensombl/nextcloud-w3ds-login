<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\Db\ChatVaults;
use OCA\W3dsLogin\Db\IdMappingMapper;
use OCA\W3dsLogin\Db\TalkHistory;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Rebuild who has read what in synced conversations, from the messages'
 * own read receipts.
 *
 * Day to day, receipts only ever move a read marker forward, because reading
 * in Talk counts too. That also means a marker set wrongly once -- by an
 * import that knew nothing of receipts, or one that treated everything as
 * unread -- stays wrong. This reads every message envelope of every synced
 * conversation and sets each person's marker to exactly the newest message
 * the envelopes say they have read or sent, in either direction.
 */
class ReadStateService {
	/** Everything the awareness service holds, not a recent window. */
	private const FROM = '2000-01-01T00:00:00Z';

	/** Per eVault, at most this many pages of up to 500 packets. */
	private const MAX_PAGES = 400;

	public function __construct(
		private ChatHistoryService $history,
		private ChatVaults $chatVaults,
		private IdMappingMapper $idMappingMapper,
		private TalkHistory $talkHistory,
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param ?callable(string): void $progress Called with each eVault before it is read.
	 * @return array{rooms: int, markers: int, skipped: int}
	 */
	public function rebuild(?string $onlyRoom = null, ?callable $progress = null): array {
		$rooms = $onlyRoom !== null ? [$onlyRoom] : $this->syncedRooms();
		$vaultsByRoom = [];
		$vaults = [];
		foreach ($rooms as $token) {
			$vaultsByRoom[$token] = $this->chatVaults->forRoom($token);
			$vaults = array_merge($vaults, $vaultsByRoom[$token]);
		}

		/** @var array<string, array<string, \DateTimeImmutable>> $readUntil room token => eName => newest read, by when it was sent */
		$readUntil = [];
		/** @var array<string, array<string, int>> $readUpTo room token => eName => newest read, by the comment showing it */
		$readUpTo = [];
		/** @var array<string, ?string> $roomOfChat */
		$roomOfChat = [];
		$incomplete = [];
		foreach (ChatVaults::normalise($vaults) as $vault) {
			if ($progress !== null) {
				$progress($vault);
			}
			$result = $this->history->walk(
				[ChatSyncService::MESSAGE_SCHEMA_ID],
				self::FROM,
				$vault,
				self::MAX_PAGES,
				collect: function (array $packet) use (&$readUntil, &$readUpTo, &$roomOfChat): void {
					$data = is_array($packet['data'] ?? null) ? $packet['data'] : [];
					$chatId = $data['chatId'] ?? null;
					if (($data['isReference'] ?? false) === true || !is_string($chatId) || $chatId === '') {
						return;
					}
					$token = $roomOfChat[$chatId] ??= $this->idMappingMapper->getLocalId('chat', $chatId);
					$readers = ChatSyncService::readersOf($data);
					if ($token === null || $readers === []) {
						return;
					}

					// The comment showing it, when known, is exact. Its send
					// time is the fallback: some comments were dated when
					// they were imported, not when they were sent.
					$commentId = $this->commentFor(is_string($packet['id'] ?? null) ? $packet['id'] : '', $chatId, $data);
					$sentAt = null;
					$createdAt = $data['createdAt'] ?? null;
					if (is_string($createdAt) && $createdAt !== '') {
						try {
							$sentAt = new \DateTimeImmutable($createdAt);
						} catch (\Throwable) {
						}
					}

					foreach ($readers as $reader) {
						$key = strtolower($reader);
						if ($commentId !== null && $commentId > ($readUpTo[$token][$key] ?? 0)) {
							$readUpTo[$token][$key] = $commentId;
						}
						if ($sentAt !== null && (!isset($readUntil[$token][$key]) || $sentAt > $readUntil[$token][$key])) {
							$readUntil[$token][$key] = $sentAt;
						}
					}
				},
			);
			if (!$result['complete']) {
				$incomplete[strtolower($vault)] = true;
			}
		}

		$markers = 0;
		$skipped = 0;
		$updated = 0;
		foreach ($rooms as $token) {
			// A conversation missing some of its receipts would come out
			// less read than it is; leave it as it was.
			foreach ($vaultsByRoom[$token] as $vault) {
				if (isset($incomplete[strtolower($vault)])) {
					$skipped++;
					continue 2;
				}
			}
			foreach ($this->membersWithEName($token) as $uid => $w3id) {
				$key = strtolower($w3id);
				$messageId = $this->readMarkerUpTo($token, $readUntil[$token][$key] ?? null);
				if ($messageId === null) {
					continue;
				}
				// Everyone has read what they posted themselves, and what a
				// receipt names by its comment.
				$messageId = max($messageId, $readUpTo[$token][$key] ?? 0, $this->newestOwnEntry($token, $uid) ?? 0);
				if ($this->setReadMarker($token, $uid, $messageId)) {
					$markers++;
				}
			}
			$updated++;
		}
		$this->talkHistory->clearTalkCaches();

		$this->logger->info('[W3DS Sync] Rebuilt read state from read receipts', [
			'rooms' => $updated,
			'markers' => $markers,
			'skipped' => $skipped,
		]);

		return ['rooms' => $updated, 'markers' => $markers, 'skipped' => $skipped];
	}

	/**
	 * @return list<string>
	 */
	protected function syncedRooms(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('local_id')
			->from('w3ds_id_mappings')
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('chat')));
		$result = $qb->executeQuery();
		$tokens = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();

		return $tokens;
	}

	/**
	 * The room's members who have an eName, the people this app made for
	 * peers included: their markers are what Talk shows as "read" ticks.
	 *
	 * @return array<string, string> uid => eName
	 */
	protected function membersWithEName(string $roomToken): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.actor_id', 'm.w3id')
			->from('talk_attendees', 'a')
			->innerJoin('a', 'talk_rooms', 'r', $qb->expr()->eq('a.room_id', 'r.id'))
			->innerJoin('a', 'w3ds_login_mappings', 'm', $qb->expr()->eq('a.actor_id', 'm.nc_uid'))
			->where($qb->expr()->eq('r.token', $qb->createNamedParameter($roomToken)))
			->andWhere($qb->expr()->eq('a.actor_type', $qb->createNamedParameter('users')));
		$result = $qb->executeQuery();
		$members = [];
		while ($row = $result->fetch()) {
			$members[(string)$row['actor_id']] = (string)$row['w3id'];
		}
		$result->closeCursor();

		return $members;
	}

	/**
	 * The Talk comment showing a message envelope, from the mappings sync
	 * keeps: directly, or through the message's inner id for a copy.
	 *
	 * @param array<string, mixed> $data
	 */
	protected function commentFor(string $envelopeId, string $chatId, array $data): ?int {
		$localId = $envelopeId !== '' ? $this->idMappingMapper->getLocalId('message', $envelopeId) : null;
		if ($localId !== null && ctype_digit($localId)) {
			return (int)$localId;
		}
		$innerId = $data['id'] ?? null;
		if (is_string($innerId) && $innerId !== '') {
			$localId = $this->idMappingMapper->getLocalId('message_comment', $chatId . '|' . $innerId);
			if ($localId !== null && ctype_digit($localId)) {
				return (int)$localId;
			}
		}

		return null;
	}

	protected function newestOwnEntry(string $roomToken, string $uid): ?int {
		return $this->talkHistory->newestOwnEntry($roomToken, $uid);
	}

	protected function readMarkerUpTo(string $roomToken, ?\DateTimeImmutable $until): ?int {
		return $this->talkHistory->readMarkerUpTo($roomToken, $until);
	}

	/**
	 * @return bool Whether the marker changed.
	 */
	protected function setReadMarker(string $roomToken, string $uid, int $messageId): bool {
		try {
			$room = \OCP\Server::get(\OCA\Talk\Manager::class)->getRoomByToken($roomToken);
			$participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
			$participant = $participantService->getParticipant($room, $uid, false);
			if ($participant->getAttendee()->getLastReadMessage() === $messageId) {
				return false;
			}
			$participantService->updateLastReadMessage($participant, $messageId);

			return true;
		} catch (\Throwable $e) {
			$this->logger->info('[W3DS Sync] Could not set a read marker', [
				'roomToken' => $roomToken,
				'uid' => $uid,
				'exception' => $e->getMessage(),
			]);

			return false;
		}
	}
}
