<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCA\W3dsLogin\Service\W3dsAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class AuthController extends Controller {
    public function __construct(
        IRequest $request,
        private W3dsAuthService $authService,
        private UserProvisioningService $provisioningService,
        private IURLGenerator $urlGenerator,
        private IUserSession $userSession,
        private ISession $session,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Show the QR code authentication page.
     *
     * @PublicPage
     * @NoCSRFRequired
     */
    public function offer(): TemplateResponse {
        $callbackUrl = $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.auth.callback',
        );

        $authSession = $this->authService->createSession($callbackUrl);

        $statusUrl = $this->urlGenerator->linkToRouteAbsolute(
            Application::APP_ID . '.auth.status',
            ['session' => $authSession['sessionId']],
        );

        return new TemplateResponse(
            Application::APP_ID,
            'auth',
            [
                'w3dsUri' => $authSession['uri'],
                'sessionId' => $authSession['sessionId'],
                'statusUrl' => $statusUrl,
            ],
            'guest',
        );
    }

    /**
     * Receive the signed authentication callback from the eID wallet.
     *
     * @PublicPage
     * @NoCSRFRequired
     */
    public function callback(): JSONResponse {
        $w3id = $this->request->getParam('w3id', '');
        $sessionId = $this->request->getParam('session', '');
        $signature = $this->request->getParam('signature', '');
        $appVersion = $this->request->getParam('appVersion', '');

        if (empty($w3id) || empty($sessionId) || empty($signature)) {
            return new JSONResponse(
                ['error' => 'Missing required fields'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $sessionData = $this->authService->getSessionStatus($sessionId);
        if ($sessionData === null) {
            return new JSONResponse(
                ['error' => 'Session expired or invalid'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        if ($sessionData['status'] !== 'pending') {
            return new JSONResponse(
                ['error' => 'Session already consumed'],
                Http::STATUS_CONFLICT,
            );
        }

        if (!$this->authService->verifySignature($w3id, $sessionId, $signature)) {
            $this->logger->warning('W3DS signature verification failed', [
                'w3id' => $w3id,
                'session' => $sessionId,
            ]);
            return new JSONResponse(
                ['error' => 'Signature verification failed'],
                Http::STATUS_UNAUTHORIZED,
            );
        }

        $user = $this->provisioningService->findOrCreateUser($w3id);
        if ($user === null) {
            return new JSONResponse(
                ['error' => 'Failed to provision user'],
                Http::STATUS_INTERNAL_SERVER_ERROR,
            );
        }

        $this->authService->markSessionComplete($sessionId, $user->getUID());

        return new JSONResponse(['status' => 'ok']);
    }

    /**
     * Poll endpoint for the QR code page to check authentication status.
     *
     * @PublicPage
     * @NoCSRFRequired
     */
    public function status(): JSONResponse {
        $sessionId = $this->request->getParam('session', '');

        if (empty($sessionId)) {
            return new JSONResponse(
                ['error' => 'Missing session parameter'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $sessionData = $this->authService->getSessionStatus($sessionId);
        if ($sessionData === null) {
            return new JSONResponse([
                'status' => 'expired',
            ]);
        }

        if ($sessionData['status'] === 'authenticated') {
            $userId = $this->authService->consumeSession($sessionId);
            if ($userId !== null) {
                $loginToken = $this->createLoginToken($userId);
                return new JSONResponse([
                    'status' => 'authenticated',
                    'loginUrl' => $this->urlGenerator->linkToRouteAbsolute(
                        Application::APP_ID . '.auth.completeLogin',
                        ['token' => $loginToken],
                    ),
                ]);
            }
        }

        return new JSONResponse([
            'status' => $sessionData['status'],
        ]);
    }

    /**
     * Generate a one-time login token and store it in the session cache.
     */
    private function createLoginToken(string $userId): string {
        $token = bin2hex(random_bytes(32));

        // Store the login token temporarily so completeLogin can verify it
        $this->session->set('w3ds_login_token', $token);
        $this->session->set('w3ds_login_user', $userId);

        return $token;
    }
}
