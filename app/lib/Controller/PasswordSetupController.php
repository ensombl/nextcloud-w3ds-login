<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\HintException;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class PasswordSetupController extends Controller {
	private const MIN_LENGTH = 10;
	private const CONFIG_KEY = 'must_set_password';

	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private IConfig $config,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function show(): TemplateResponse {
		return $this->render();
	}

	/**
	 * @NoAdminRequired
	 * @UseSession
	 */
	public function submit(): Http\Response {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new TemplateResponse(
				Application::APP_ID,
				'password-setup',
				['error' => 'You are not signed in.'],
				'guest',
			);
		}

		$password = (string)$this->request->getParam('password', '');
		$confirm = (string)$this->request->getParam('confirm', '');

		if ($password === '' || $confirm === '') {
			return $this->render('Please fill in both fields.');
		}
		if ($password !== $confirm) {
			return $this->render('The two passwords do not match.');
		}
		if (strlen($password) < self::MIN_LENGTH) {
			return $this->render('Password must be at least ' . self::MIN_LENGTH . ' characters.');
		}

		try {
			if (!$user->setPassword($password)) {
				return $this->render('Nextcloud rejected that password. Please choose another one.');
			}
		} catch (HintException $e) {
			return $this->render($e->getHint() ?: 'Password rejected.');
		} catch (\Throwable $e) {
			$this->logger->error('Failed to set password for W3DS user', [
				'uid' => $user->getUID(),
				'exception' => $e,
			]);
			return $this->render('Could not save the new password. Please try again.');
		}

		$this->config->deleteUserValue($user->getUID(), Application::APP_ID, self::CONFIG_KEY);

		$this->logger->info('W3DS user set their password', ['uid' => $user->getUID()]);

		return new RedirectResponse($this->urlGenerator->getAbsoluteURL('/'));
	}

	private function render(?string $error = null): TemplateResponse {
		return new TemplateResponse(
			Application::APP_ID,
			'password-setup',
			[
				'error' => $error,
				'minLength' => self::MIN_LENGTH,
				'submitUrl' => $this->urlGenerator->linkToRoute(
					Application::APP_ID . '.password_setup.submit',
				),
			],
			'guest',
		);
	}
}
