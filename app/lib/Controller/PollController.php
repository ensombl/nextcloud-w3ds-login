<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class PollController extends Controller {
	public function __construct(
		IRequest $request,
		private ChatSyncService $chatSyncService,
		private UserProvisioningService $userProvisioning,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Poll every participant's eVault for new messages in the given room.
	 * Called by the client every ~15s while a room is open.
	 */
	#[NoAdminRequired]
	public function pollRoom(string $token): JSONResponse {
		try {
			$synced = $this->chatSyncService->pollRoom($token);
			return new JSONResponse(['synced' => $synced]);
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Sync] pollRoom endpoint failed', [
				'token' => $token,
				'exception' => $e,
			]);
			return new JSONResponse(['synced' => 0, 'error' => 'internal'], 500);
		}
	}

	/**
	 * Pull-sync the current user's eVault for any new Chat envelopes (and
	 * the messages within them) that haven't been ingested yet.
	 *
	 * Called by the frontend on the Talk room-list view so newly-replicated
	 * chats appear without waiting for the 60s PullSyncJob cron tick.
	 * pullSyncForUser is fully idempotent so a frontend poll racing with
	 * the cron job is fine.
	 */
	#[NoAdminRequired]
	public function pollUserChats(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'unauthenticated'], 401);
		}

		$w3id = $this->userProvisioning->getLinkedW3id($user->getUID());
		if ($w3id === null) {
			return new JSONResponse(['linked' => false]);
		}

		try {
			$this->chatSyncService->pullSyncForUser($w3id);
			return new JSONResponse(['linked' => true]);
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Sync] pollUserChats endpoint failed', [
				'w3id' => $w3id,
				'exception' => $e,
			]);
			return new JSONResponse(['linked' => true, 'error' => 'internal'], 500);
		}
	}
}
