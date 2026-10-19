<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\Db\ChatVaults;
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
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
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
	 * Read the given ontologies from each eVault and apply what belongs here.
	 *
	 * @param list<string> $vaults
	 * @param string[] $ontologies
	 * @return array{applied: int, complete: bool}
	 */
	public function fetchFromVaults(array $vaults, array $ontologies, ?string $from, int $maxPagesPerVault): array {
		$applied = 0;
		$complete = true;
		foreach ($vaults as $vault) {
			$result = $this->walk($ontologies, $from, $vault, $maxPagesPerVault);
			$applied += $result['applied'];
			$complete = $complete && $result['complete'];
		}

		return ['applied' => $applied, 'complete' => $complete];
	}

	/**
	 * Walk one slice of the stream, optionally restricted to one eVault.
	 *
	 * @param string[] $ontologies
	 * @return array{applied: int, complete: bool}
	 */
	public function walk(array $ontologies, ?string $from, ?string $evault, int $maxPages, bool $retry = true): array {
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

		$from = gmdate('Y-m-d\TH:i:s\Z', time() - self::OPEN_ROOM_WINDOW_DAYS * 86400);
		$applied = 0;
		foreach ($vaults as $vault) {
			// No retries: someone is waiting, and the next open tries again.
			$applied += $this->walk(
				[ChatSyncService::MESSAGE_SCHEMA_ID, AwarenessClient::FILE_ONTOLOGY],
				$from,
				$vault,
				self::OPEN_ROOM_MAX_PAGES,
				retry: false,
			)['applied'];
		}

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
