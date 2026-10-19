<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
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
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private UserProvisioningService $provisioningService,
		private ChatSyncStatusService $status,
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
}
