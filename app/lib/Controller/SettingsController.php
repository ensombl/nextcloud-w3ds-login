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
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SettingsController extends Controller {
    public function __construct(
        IRequest $request,
        private W3dsAuthService $authService,
        private UserProvisioningService $provisioningService,
        private QrCodeService $qrCodeService,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private IUserSession $userSession,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Start a linking session and return JSON with QR data.
     *
     * @NoAdminRequired
     */
    public function linkStart(): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Not authenticated'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $callbackUrl = $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.auth.callback',
        );

        $authSession = $this->authService->createSession(
            $callbackUrl,
            'Nextcloud',
            $user->getUID(),
        );

        $statusUrl = $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.settings.linkStatus',
            ['session' => $authSession['sessionId']],
        );

        $qrSvg = $this->qrCodeService->generateSvg($authSession['uri']);

        return new JSONResponse([
            'qrSvg' => $qrSvg,
            'w3dsUri' => $authSession['uri'],
            'statusUrl' => $statusUrl,
            'sessionId' => $authSession['sessionId'],
        ]);
    }

    /**
     * Poll endpoint for the linking modal to check status.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function linkStatus(): JSONResponse {
        $sessionId = $this->request->getParam('session', '');

        if (empty($sessionId)) {
            return new JSONResponse(
                ['error' => 'Missing session parameter'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $sessionData = $this->authService->getSessionStatus($sessionId);
        if ($sessionData === null) {
            return new JSONResponse(['status' => 'expired']);
        }

        // Verify the polling user owns this session
        $user = $this->userSession->getUser();
        if ($user === null || ($sessionData['ncUid'] ?? null) !== $user->getUID()) {
            return new JSONResponse(['status' => 'expired']);
        }

        if ($sessionData['status'] === 'authenticated') {
            $this->authService->consumeSession($sessionId);
            return new JSONResponse(['status' => 'linked']);
        }

        if ($sessionData['status'] === 'failed') {
            $this->authService->consumeSession($sessionId);
            return new JSONResponse([
                'status' => 'failed',
                'error' => $sessionData['error'] ?? 'Unknown error',
            ]);
        }

        return new JSONResponse(['status' => $sessionData['status']]);
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

        $email = $user->getEMailAddress();
        if (empty($email)) {
            return new JSONResponse(
                ['error' => 'Set an email address before unlinking'],
                Http::STATUS_FORBIDDEN,
            );
        }

        $this->provisioningService->unlinkUser($user->getUID());

        $this->logger->info('User unlinked W3DS identity', [
            'uid' => $user->getUID(),
        ]);

        return new JSONResponse(['status' => 'unlinked']);
    }
}
