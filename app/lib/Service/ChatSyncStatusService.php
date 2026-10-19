<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\BackgroundJob\AwarenessBackfillJob;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;

/**
 * Lets a user ask for their W3DS conversations to be fetched again, and see
 * how that is going.
 *
 * The fetch is the same history walk a new link triggers, which can take
 * minutes, so it is queued rather than run in the request. Its progress is
 * kept per eName, because that is all the job knows about.
 */
class ChatSyncStatusService {
	public const STATE_QUEUED = 'queued';
	public const STATE_RUNNING = 'running';
	public const STATE_DONE = 'done';
	public const STATE_FAILED = 'failed';

	/**
	 * A sync that has been queued or running longer than this is presumed
	 * dead -- cron stopped, the job crashed -- and may be requested again.
	 */
	private const STALE_AFTER = 1800;

	private const KEY_PREFIX = 'chat_sync_status_';

	public function __construct(
		private IConfig $config,
		private IJobList $jobList,
		private AwarenessClient $awarenessClient,
	) {
	}

	/**
	 * Queue a fresh fetch of this eName's conversations, unless one is
	 * already under way.
	 *
	 * @return array<string, mixed> The status after the request
	 * @throws \RuntimeException With a message for the user, when it cannot run
	 */
	public function request(string $w3id): array {
		if (!$this->awarenessClient->isConfigured()) {
			throw new \RuntimeException('Chat sync is not set up on this server yet: an administrator has to connect the awareness service.');
		}

		$status = $this->get($w3id);
		if ($this->inProgress($status)) {
			return $status;
		}

		$now = time();
		$this->set($w3id, ['state' => self::STATE_QUEUED, 'requestedAt' => $now]);
		// requestedAt keeps the arguments unique, so this is a new job even
		// when an earlier one with the same eName is still in the list.
		$this->jobList->add(AwarenessBackfillJob::class, [
			'w3id' => $w3id,
			'force' => true,
			'requestedAt' => $now,
		]);

		return $this->get($w3id);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(string $w3id): array {
		$raw = $this->config->getAppValue(Application::APP_ID, $this->key($w3id), '');
		$status = $raw !== '' ? json_decode($raw, true) : null;

		return is_array($status) ? $status : ['state' => null];
	}

	public function markRunning(string $w3id): void {
		$this->set($w3id, ['state' => self::STATE_RUNNING, 'startedAt' => time()] + $this->get($w3id));
	}

	public function markDone(string $w3id, int $chats, int $messages, bool $complete): void {
		$this->set($w3id, [
			'state' => self::STATE_DONE,
			'finishedAt' => time(),
			'chats' => $chats,
			'messages' => $messages,
			'complete' => $complete,
		]);
	}

	public function markFailed(string $w3id, string $reason): void {
		$this->set($w3id, ['state' => self::STATE_FAILED, 'finishedAt' => time(), 'error' => $reason]);
	}

	/**
	 * @param array<string, mixed> $status
	 */
	private function inProgress(array $status): bool {
		if (!in_array($status['state'] ?? null, [self::STATE_QUEUED, self::STATE_RUNNING], true)) {
			return false;
		}
		$since = (int)($status['startedAt'] ?? $status['requestedAt'] ?? 0);

		return time() - $since < self::STALE_AFTER;
	}

	/**
	 * @param array<string, mixed> $status
	 */
	private function set(string $w3id, array $status): void {
		$this->config->setAppValue(Application::APP_ID, $this->key($w3id), (string)json_encode($status));
	}

	private function key(string $w3id): string {
		return self::KEY_PREFIX . md5($w3id);
	}
}
