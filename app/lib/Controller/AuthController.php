<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\BackgroundJob\InitialSyncJob;
use OCA\W3dsLogin\Service\QrCodeService;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCA\W3dsLogin\Service\W3dsAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class AuthController extends Controller {
	public function __construct(
		IRequest $request,
		private W3dsAuthService $authService,
		private UserProvisioningService $provisioningService,
		private QrCodeService $qrCodeService,
		private IURLGenerator $urlGenerator,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IJobList $jobList,
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

		$qrSvg = $this->qrCodeService->generateSvg($authSession['uri']);

		return new TemplateResponse(
			Application::APP_ID,
			'auth',
			[
				'w3dsUri' => $authSession['uri'],
				'sessionId' => $authSession['sessionId'],
				'statusUrl' => $statusUrl,
				'qrSvg' => $qrSvg,
			],
			'guest',
		);
	}

	/**
	 * CORS preflight for the callback endpoint.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function preflight(): JSONResponse {
		$response = new JSONResponse(null, Http::STATUS_NO_CONTENT);
		$this->addCorsHeaders($response);
		return $response;
	}

	/**
	 * Receive the signed authentication callback from the eID wallet.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 */
	public function callback(): JSONResponse {
		// The wallet sends JSON with "ename" (docs say "w3id", accept both)
		$w3id = $this->request->getParam('w3id', '') ?: $this->request->getParam('ename', '');
		$sessionId = $this->request->getParam('session', '');
		$signature = $this->request->getParam('signature', '');

		// Fallback: parse raw body if params are empty (Nextcloud may not
		// auto-parse JSON for public/CSRF-free routes)
		if (empty($w3id) && empty($sessionId) && empty($signature)) {
			$raw = file_get_contents('php://input');
			$body = json_decode($raw ?: '', true) ?? [];
			$w3id = $body['w3id'] ?? $body['ename'] ?? '';
			$sessionId = $body['session'] ?? '';
			$signature = $body['signature'] ?? '';

			$this->logger->debug('W3DS callback raw body', [
				'raw_length' => strlen($raw ?: ''),
				'parsed_keys' => array_keys($body),
				'content_type' => $this->request->getHeader('Content-Type'),
			]);
		}

		if (empty($w3id) || empty($sessionId) || empty($signature)) {
			// Log all received params to discover wallet's actual field names
			$allParams = [];
			foreach (['w3id', 'eName', 'ename', 'e_name', 'session', 'signature', 'appVersion'] as $k) {
				$v = $this->request->getParam($k);
				if ($v !== null) {
					$allParams[$k] = substr((string)$v, 0, 40);
				}
			}
			$raw = file_get_contents('php://input') ?: '';
			$this->logger->warning('W3DS callback missing fields', [
				'has_w3id' => !empty($w3id),
				'has_session' => !empty($sessionId),
				'has_signature' => !empty($signature),
				'known_params' => $allParams,
				'raw_body' => substr($raw, 0, 500),
				'content_type' => $this->request->getHeader('Content-Type'),
			]);
			return $this->corsResponse(
				['error' => 'Missing required fields'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$sessionData = $this->authService->getSessionStatus($sessionId);
		if ($sessionData === null) {
			return $this->corsResponse(
				['error' => 'Session expired or invalid'],
				Http::STATUS_UNAUTHORIZED,
			);
		}

		if ($sessionData['status'] !== 'pending') {
			return $this->corsResponse(
				['error' => 'Session already consumed'],
				Http::STATUS_CONFLICT,
			);
		}

		if (!$this->authService->verifySignature($w3id, $sessionId, $signature)) {
			$this->logger->warning('W3DS signature verification failed', [
				'w3id' => $w3id,
				'session' => $sessionId,
			]);
			return $this->corsResponse(
				['error' => 'Signature verification failed'],
				Http::STATUS_UNAUTHORIZED,
			);
		}

		// Check if this is a linking session (has ncUid) or a login session
		$ncUid = $sessionData['ncUid'] ?? null;

		if ($ncUid !== null) {
			// Linking flow: attach W3ID to existing account
			if ($this->userManager->get($ncUid) === null) {
				$this->logger->warning('W3DS link: user no longer exists', ['ncUid' => $ncUid]);
				return $this->corsResponse(
					['error' => 'User account no longer exists'],
					Http::STATUS_BAD_REQUEST,
				);
			}
			try {
				$this->provisioningService->linkUser($w3id, $ncUid);
			} catch (\RuntimeException $e) {
				$this->logger->warning('W3DS link failed: ' . $e->getMessage(), [
					'w3id' => $w3id,
					'ncUid' => $ncUid,
				]);
				$this->authService->markSessionFailed($sessionId, $e->getMessage());
				return $this->corsResponse(
					['error' => $e->getMessage()],
					Http::STATUS_CONFLICT,
				);
			}
			$this->authService->markSessionComplete($sessionId, $ncUid);

			// Queue initial sync now that user has a W3DS identity
			$this->jobList->add(InitialSyncJob::class, ['ncUid' => $ncUid]);

			$this->logger->info('User linked W3DS identity', [
				'uid' => $ncUid,
				'w3id' => $w3id,
			]);
			return $this->corsResponse(['status' => 'ok']);
		}

		// Login flow: find or create user
		$user = $this->provisioningService->findOrCreateUser($w3id);
		if ($user === null) {
			return $this->corsResponse(
				['error' => 'Failed to provision user'],
				Http::STATUS_INTERNAL_SERVER_ERROR,
			);
		}

		$this->authService->markSessionComplete($sessionId, $user->getUID());

		return $this->corsResponse(['status' => 'ok']);
	}

	private function corsResponse(mixed $data, int $status = Http::STATUS_OK): JSONResponse {
		$response = new JSONResponse($data, $status);
		$this->addCorsHeaders($response);
		return $response;
	}

	private function addCorsHeaders(JSONResponse $response): void {
		$response->addHeader('Access-Control-Allow-Origin', '*');
		$response->addHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
		$response->addHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization');
		$response->addHeader('Access-Control-Max-Age', '86400');
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
			return new JSONResponse(['status' => 'expired']);
		}

		if ($sessionData['status'] === 'authenticated') {
			$userId = $this->authService->consumeSession($sessionId);
			if ($userId !== null) {
				$loginToken = $this->authService->createLoginToken($userId);
				return new JSONResponse([
					'status' => 'authenticated',
					'loginUrl' => $this->urlGenerator->linkToRouteAbsolute(
						Application::APP_ID . '.auth.completeLogin',
						['token' => $loginToken],
					),
				]);
			}
		}

		return new JSONResponse(['status' => $sessionData['status']]);
	}

	/**
	 * Complete the login by consuming a one-time token, creating a session,
	 * and redirecting the user to the Nextcloud home page.
	 *
	 * @PublicPage
	 * @NoCSRFRequired
	 * @UseSession
	 */
	public function completeLogin(): RedirectResponse {
		$token = $this->request->getParam('token', '');
		$loginPage = $this->urlGenerator->linkToRouteAbsolute('core.login.showLoginForm');

		if (empty($token)) {
			return new RedirectResponse($loginPage);
		}

		$userId = $this->authService->consumeLoginToken($token);
		if ($userId === null) {
			$this->logger->warning('Invalid or expired login token');
			return new RedirectResponse($loginPage);
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			$this->logger->error('Login token referenced non-existent user', ['uid' => $userId]);
			return new RedirectResponse($loginPage);
		}

		// Establish the Nextcloud session. setLoginName() is required so later
		// flows that call $userSession->getLoginName() (e.g. the Security tab
		// password-change controller) can resolve the user -- without it,
		// checkPassword() receives null and always returns false.
		$this->userSession->setUser($user);
		$this->userSession->setLoginName($userId);
		$this->userSession->createSessionToken($this->request, $userId, $userId);

		// Queue initial sync of user's Talk chats to/from their eVault
		$this->jobList->add(InitialSyncJob::class, ['ncUid' => $userId]);

		$this->logger->info('W3DS login completed', ['uid' => $userId]);

		return new RedirectResponse($this->urlGenerator->getAbsoluteURL('/'));
	}
}
