<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\AwarenessPoller;
use OCA\W3dsLogin\Service\ChatSyncStatusService;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * The "Sync W3DS chats" button: fetch the signed-in user's conversations
 * again, and report on it.
 */
class ChatSyncController extends Controller {
	/** A poll younger than this is fresh enough for a page to skip. */
	private const POLL_MIN_INTERVAL = 25;

	/**
	 * The same, for a page with a conversation open: someone is waiting on
	 * the next message, so only a poll from moments ago will do.
	 */
	private const OPEN_CHAT_POLL_MIN_INTERVAL = 3;

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private UserProvisioningService $provisioningService,
		private ChatSyncStatusService $status,
		private AwarenessPoller $poller,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @NoAdminRequired
	 * @UserRateThrottle(limit=5, period=600)
	 */
	public function start(): JSONResponse {
		$w3id = $this->linkedW3id();
		if ($w3id === null) {
			return new JSONResponse(
				['error' => 'Link your W3DS identity first: your chats sync once your account is connected to your wallet.'],
				Http::STATUS_CONFLICT,
			);
		}

		try {
			return new JSONResponse($this->status->request($w3id));
		} catch (\RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		} catch (\Throwable $e) {
			$this->logger->error('Could not queue a W3DS chat sync', ['w3id' => $w3id, 'exception' => $e]);
			return new JSONResponse(['error' => 'Could not start the sync. Try again in a moment.'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function status(): JSONResponse {
		$w3id = $this->linkedW3id();
		if ($w3id === null) {
			return new JSONResponse(['state' => null, 'linked' => false]);
		}

		return new JSONResponse(['linked' => true] + $this->status->get($w3id));
	}

	private function linkedW3id(): ?string {
		$user = $this->userSession->getUser();

		return $user === null ? null : $this->provisioningService->getLinkedW3id($user->getUID());
	}

	/**
	 * Poll the awareness service now, unless that happened moments ago.
	 *
	 * Called by open Talk pages: every 30 seconds, and every 10 while a
	 * conversation is open -- immediately on opening one. That keeps
	 * messages arriving on a server running neither the poll worker nor a
	 * fast cron. One poll serves every user, so most calls find a recent one
	 * and return straight away; the poller's lock keeps the rest from
	 * overlapping.
	 *
	 * The response is what the page's sync indicator shows.
	 *
	 * @NoAdminRequired
	 * @UserRateThrottle(limit=40, period=60)
	 */
	public function poll(bool $chatOpen = false): JSONResponse {
		if (!$this->poller->isConfigured()) {
			return new JSONResponse([
				'polled' => false,
				'configured' => false,
				'error' => 'Incoming W3DS messages are not set up on this server. An administrator can connect them under Administration settings → W3DS.',
			]);
		}

		$minInterval = $chatOpen ? self::OPEN_CHAT_POLL_MIN_INTERVAL : self::POLL_MIN_INTERVAL;
		$since = $this->poller->secondsSinceLastPoll();
		if ($since !== null && $since < $minInterval) {
			return new JSONResponse(['polled' => false, 'configured' => true, 'lastPolledAt' => time() - $since]);
		}

		try {
			$applied = $this->poller->poll();
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Awareness] Poll from a Talk page failed', ['exception' => $e->getMessage()]);
			return new JSONResponse([
				'polled' => false,
				'configured' => true,
				'error' => 'Could not reach the W3DS awareness service. Retrying.',
			]);
		}

		$since = $this->poller->secondsSinceLastPoll();

		return new JSONResponse([
			// Null means another poll holds the lock and is fetching right
			// now; the page simply asks again shortly.
			'polled' => $applied !== null,
			'configured' => true,
			'applied' => $applied ?? 0,
			'lastPolledAt' => $since !== null ? time() - $since : null,
		]);
	}
}
