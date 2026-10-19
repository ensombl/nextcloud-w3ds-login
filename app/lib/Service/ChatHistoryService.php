<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\Db\ChatVaults;
use OCA\W3dsLogin\Db\FetchMarks;
use OCP\ICache;
use OCP\ICacheFactory;
use Psr\Log\LoggerInterface;

/**
 * Fetches conversations' messages from every eVault that holds them.
 *
 * The live poll reads the awareness stream for the whole instance, which
 * carries new messages wherever they are stored. History is different: the
 * stream can only be narrowed by eVault, and a conversation's messages are
 * spread across its own eVault and its participants' (see {@see ChatVaults}).
 * Each of those is read separately, and every packet goes through the same
 * processor the live poll uses, which places it in its room exactly once.
 */
class ChatHistoryService {
	/** Pause before re-reading a page that failed, in seconds. */
	private const RETRY_DELAYS = [2, 5, 15];

	/** How recently a room was fetched for opening it to count as fresh. */
	private const OPEN_ROOM_FRESH_FOR = 60;

	/** Opening a conversation reads its last week; the history fetch reads more. */
	private const OPEN_ROOM_WINDOW_DAYS = 7;

	/** Bounds one room-open fetch, which runs while someone waits. */
	private const OPEN_ROOM_MAX_VAULTS = 25;
	private const OPEN_ROOM_MAX_PAGES = 2;

	private ICache $cache;

	public function __construct(
		private AwarenessClient $awarenessClient,
		private AwarenessPacketProcessor $processor,
		private ChatVaults $chatVaults,
		private ChatSyncService $chatSync,
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
		private FetchMarks $marks,
	) {
		$this->cache = $cacheFactory->createDistributed('w3ds_login_chat_history');
	}

	/**
	 * Every eVault holding messages of any synced room this user is in.
	 *
	 * @return list<string>
	 */
	public function vaultsForUser(string $uid): array {
		$vaults = [];
		foreach ($this->chatVaults->roomsOf($uid) as $token) {
			$vaults = array_merge($vaults, $this->chatVaults->forRoom($token));
		}

		return ChatVaults::normalise($vaults);
	}

	/**
	 * Read the given ontologies from each eVault and apply what belongs here,
	 * oldest first.
	 *
	 * Talk orders a conversation by the order things were posted into it,
	 * not by their timestamps. Applying each eVault in turn posted one
	 * person's messages, then the next person's, so a conversation came out
	 * shuffled; everything is gathered first and applied in the order it was
	 * sent.
	 *
	 * Each eVault is read from where its last complete read began (see
	 * {@see FetchMarks}), or from $windowStart if it never was, or when a
	 * $full read is asked for.
	 *
	 * @param list<string> $vaults
	 * @param string[] $ontologies
	 * @return array{applied: int, complete: bool}
	 */
	public function fetchFromVaults(array $vaults, array $ontologies, int $windowStart, int $maxPagesPerVault, bool $full = false): array {
		$packets = [];
		$complete = true;
		foreach ($vaults as $vault) {
			// Only what arrived since this eVault was last read in full.
			$key = 'vault:' . $vault;
			$from = $full ? gmdate('Y-m-d\TH:i:s\Z', $windowStart) : $this->marks->from($key, $windowStart);
			$startedAt = time();
			$result = $this->walk($ontologies, $from, $vault, $maxPagesPerVault, collect: function (array $packet) use (&$packets): void {
				$id = is_string($packet['id'] ?? null) ? $packet['id'] : '';
				// One envelope can be listed under more than one eVault.
				$packets[$id !== '' ? $id : '#' . count($packets)] = $packet;
			});
			if ($result['complete']) {
				$this->marks->set($key, $startedAt);
			}
			$complete = $complete && $result['complete'];
		}

		$ordered = array_values($packets);
		usort($ordered, static fn (array $a, array $b): int => strcmp(self::sentAt($a), self::sentAt($b)));

		$applied = 0;
		foreach ($ordered as $packet) {
			if ($this->processor->process($packet, reapply: true)) {
				$applied++;
			}
		}
		$this->chatSync->flushResequencing();

		return ['applied' => $applied, 'complete' => $complete];
	}

	/**
	 * When a packet's content was sent, as a sortable UTC string. Falls back
	 * to when the service received it.
	 *
	 * @param array<string, mixed> $packet
	 */
	private static function sentAt(array $packet): string {
		$data = is_array($packet['data'] ?? null) ? $packet['data'] : [];
		foreach ([$data['createdAt'] ?? null, $packet['receivedAt'] ?? null] as $value) {
			if (is_string($value) && ($time = strtotime($value)) !== false) {
				return gmdate('Y-m-d\TH:i:s', $time);
			}
		}

		return '';
	}

	/**
	 * Walk one slice of the stream, optionally restricted to one eVault.
	 * With $collect, packets are handed to it instead of applied.
	 *
	 * @param string[] $ontologies
	 * @return array{applied: int, complete: bool}
	 */
	public function walk(array $ontologies, ?string $from, ?string $evault, int $maxPages, bool $retry = true, ?callable $collect = null): array {
		$cursor = null;
		$applied = 0;

		for ($page = 0; $page < $maxPages; $page++) {
			$result = $retry
				? $this->fetchWithRetry($cursor, $ontologies, $from, $evault)
				: $this->awarenessClient->fetchPackets($cursor, $ontologies, $from, $evault);

			// A failed read ends this walk rather than skipping past it:
			// carrying on would leave a hole with nothing to show for it.
			if ($result === null) {
				$this->logger->warning('[W3DS Awareness] Stopped reading history: the service did not answer', [
					'evault' => $evault,
				]);

				return ['applied' => $applied, 'complete' => false];
			}

			foreach ($result['packets'] as $packet) {
				if ($collect !== null) {
					$collect($packet);
					continue;
				}
				// Re-apply rather than skip. Much of this has been seen by the
				// live poll already; the handlers are idempotent, and a
				// conversation ingested before someone here was in it only
				// gains them when its chat is handed over again.
				if ($this->processor->process($packet, reapply: true)) {
					$applied++;
				}
			}

			if (!$result['hasMore'] || $result['nextCursor'] === null) {
				return ['applied' => $applied, 'complete' => true];
			}
			$cursor = $result['nextCursor'];
		}

		return ['applied' => $applied, 'complete' => false];
	}

	/**
	 * Bring one room's recent messages in now, because someone opened it.
	 *
	 * The live poll carries messages as they are sent; this catches what it
	 * could not -- anything from before this server followed the stream, or
	 * delivered while it was down -- for the room someone is looking at.
	 * Skipped when the room was fetched in the last minute, and bounded,
	 * because the person is waiting.
	 *
	 * @return int|null Packets applied, or null when the room was fresh.
	 */
	public function fetchOpenRoom(string $roomToken): ?int {
		$key = 'open_' . md5($roomToken);
		if ($this->cache->get($key) !== null) {
			return null;
		}
		$this->cache->set($key, 1, self::OPEN_ROOM_FRESH_FOR);

		$vaults = array_slice($this->chatVaults->forRoom($roomToken), 0, self::OPEN_ROOM_MAX_VAULTS);
		if ($vaults === []) {
			return 0;
		}

		$windowStart = time() - self::OPEN_ROOM_WINDOW_DAYS * 86400;
		$applied = 0;
		foreach ($vaults as $vault) {
			$key = 'vault:' . $vault;
			$marked = $this->marks->get($key) !== null;
			$startedAt = time();
			// No retries: someone is waiting, and the next open tries again.
			$result = $this->walk(
				[ChatSyncService::MESSAGE_SCHEMA_ID, AwarenessClient::FILE_ONTOLOGY],
				$this->marks->from($key, $windowStart),
				$vault,
				self::OPEN_ROOM_MAX_PAGES,
				retry: false,
			);
			$applied += $result['applied'];
			// Only advance a mark the read continued from: an eVault never
			// read in full still has history before this window.
			if ($marked && $result['complete']) {
				$this->marks->set($key, $startedAt);
			}
		}

		$this->chatSync->flushResequencing();

		return $applied;
	}

	/**
	 * @param string[] $ontologies
	 * @return array{packets: list<array<string, mixed>>, nextCursor: ?string, hasMore: bool}|null
	 */
	private function fetchWithRetry(?string $cursor, array $ontologies, ?string $from, ?string $evault): ?array {
		$result = $this->awarenessClient->fetchPackets($cursor, $ontologies, $from, $evault);
		foreach (self::RETRY_DELAYS as $delay) {
			if ($result !== null) {
				break;
			}
			$this->pause($delay);
			$result = $this->awarenessClient->fetchPackets($cursor, $ontologies, $from, $evault);
		}

		return $result;
	}

	/** Separate so tests need not wait. */
	protected function pause(int $seconds): void {
		sleep($seconds);
	}
}
