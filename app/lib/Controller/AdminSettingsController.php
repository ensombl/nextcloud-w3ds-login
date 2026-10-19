<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCA\W3dsLogin\Service\AwarenessSettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin-only by default: neither method carries @NoAdminRequired.
 */
class AdminSettingsController extends Controller {
	public function __construct(
		IRequest $request,
		private AwarenessSettingsService $settings,
		private AwarenessClient $awarenessClient,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	public function save(): JSONResponse {
		try {
			$this->settings->save(
				$this->param('baseUrl'),
				$this->param('apiKey'),
				$this->param('webhookSecret'),
				$this->param('registryUrl'),
			);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['settings' => $this->settings->get()]);
	}

	/**
	 * Ask the service who it thinks we are. Only an approved consumer is
	 * delivered anything, however correct the settings look.
	 */
	public function test(): JSONResponse {
		if (!$this->awarenessClient->isConfigured()) {
			return new JSONResponse(['ok' => false, 'message' => 'Set the service URL and API key first.']);
		}

		$me = $this->awarenessClient->me();
		if ($me === null) {
			return new JSONResponse([
				'ok' => false,
				'message' => 'The service did not accept the API key, or could not be reached. Check the URL and key; nextcloud.log has the details.',
			]);
		}

		$status = is_string($me['status'] ?? null) ? $me['status'] : '';
		$name = is_string($me['name'] ?? null) && $me['name'] !== '' ? $me['name'] : 'this consumer';
		if (strtolower($status) === 'approved') {
			return new JSONResponse(['ok' => true, 'message' => sprintf('Connected as %s, approved.', $name)]);
		}

		return new JSONResponse([
			'ok' => false,
			'message' => sprintf(
				'The key is accepted, but %s is "%s". Nothing is delivered until an administrator of the awareness service approves it.',
				$name,
				$status !== '' ? $status : 'unknown',
			),
		]);
	}

	/**
	 * A field that was not sent is left alone.
	 */
	private function param(string $name): ?string {
		$value = $this->request->getParam($name);

		return is_string($value) ? $value : null;
	}
}
