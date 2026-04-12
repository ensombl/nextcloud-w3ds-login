<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class PollController extends Controller {
    public function __construct(
        IRequest $request,
        private ChatSyncService $chatSyncService,
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
}
