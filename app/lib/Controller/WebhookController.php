<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class WebhookController extends Controller {
    public function __construct(
        IRequest $request,
        private ChatSyncService $chatSyncService,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Receive an awareness protocol packet from an eVault.
     *
     * @PublicPage
     * @NoCSRFRequired
     */
    public function receive(): JSONResponse {
        $body = $this->request->getParams();

        // Also try raw JSON body
        if (empty($body['schemaId'])) {
            $rawBody = file_get_contents('php://input');
            if (!empty($rawBody)) {
                $decoded = json_decode($rawBody, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            }
        }

        $globalId = $body['id'] ?? '';
        $ownerW3id = $body['w3id'] ?? '';
        $schemaId = $body['schemaId'] ?? '';
        $data = $body['data'] ?? [];

        if (empty($globalId) || empty($schemaId) || empty($data)) {
            $this->logger->warning('Webhook received with missing fields', [
                'hasId' => !empty($globalId),
                'hasSchemaId' => !empty($schemaId),
                'hasData' => !empty($data),
            ]);
            // Return 200 per protocol -- fire-and-forget, no retries
            return new JSONResponse(['status' => 'ignored']);
        }

        try {
            match ($schemaId) {
                ChatSyncService::CHAT_SCHEMA_ID => $this->chatSyncService->handleInboundChat($globalId, $ownerW3id, $data),
                ChatSyncService::MESSAGE_SCHEMA_ID => $this->chatSyncService->handleInboundMessage($globalId, $ownerW3id, $data),
                default => $this->logger->debug('Webhook for unknown schema, ignoring', ['schemaId' => $schemaId]),
            };
        } catch (\Throwable $e) {
            $this->logger->error('Webhook processing error', [
                'globalId' => $globalId,
                'schemaId' => $schemaId,
                'exception' => $e,
            ]);
        }

        // Always return 200
        return new JSONResponse(['status' => 'ok']);
    }
}
