<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\QrCodeService;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCA\W3dsLogin\Service\W3dsAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller {
    public function __construct(
        IRequest $request,
        private W3dsAuthService $authService,
        private UserProvisioningService $provisioningService,
        private QrCodeService $qrCodeService,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Show the QR code linking page for an authenticated user.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function linkOffer(): TemplateResponse {
        $callbackUrl = $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.settings.linkCallback',
        );

        $authSession = $this->authService->createSession($callbackUrl, 'Nextcloud (link account)');

        $statusUrl = $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.auth.status',
            ['session' => $authSession['sessionId']],
        );

        $qrSvg = $this->qrCodeService->generateSvg($authSession['uri']);

        return new TemplateResponse(
            Application::APP_ID,
            'link',
            [
                'w3dsUri' => $authSession['uri'],
                'sessionId' => $authSession['sessionId'],
                'statusUrl' => $statusUrl,
                'qrSvg' => $qrSvg,
            ],
        );
    }

    /**
     * Receive the signed callback for account linking.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function linkCallback(): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Not authenticated'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $w3id = $this->request->getParam('w3id', '');
        $sessionId = $this->request->getParam('session', '');
        $signature = $this->request->getParam('signature', '');

        if (empty($w3id) || empty($sessionId) || empty($signature)) {
            return new JSONResponse(
                ['error' => 'Missing required fields'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $sessionData = $this->authService->getSessionStatus($sessionId);
        if ($sessionData === null || $sessionData['status'] !== 'pending') {
            return new JSONResponse(
                ['error' => 'Session expired or invalid'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        if (!$this->authService->verifySignature($w3id, $sessionId, $signature)) {
            return new JSONResponse(
                ['error' => 'Signature verification failed'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        try {
            $this->provisioningService->linkUser($w3id, $user->getUID());
        } catch (\RuntimeException $e) {
            return new JSONResponse(
                ['error' => $e->getMessage()],
                Http::STATUS_CONFLICT,
            );
        }

        $this->authService->markSessionComplete($sessionId, $user->getUID());

        $this->logger->info('User linked W3DS identity', [
            'uid' => $user->getUID(),
            'w3id' => $w3id,
        ]);

        return new JSONResponse(['status' => 'linked']);
    }

    /**
     * Unlink the current user's W3DS identity.
     *
     * @NoAdminRequired
     */
    public function unlink(): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Not authenticated'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $this->provisioningService->unlinkUser($user->getUID());

        $this->logger->info('User unlinked W3DS identity', [
            'uid' => $user->getUID(),
        ]);

        return new JSONResponse(['status' => 'unlinked']);
    }
}
