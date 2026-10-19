<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Settings;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\AwarenessSettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Where an administrator connects the awareness service, which is what
 * delivers incoming W3DS chats. Until now that took `occ config:app:set`.
 */
class AdminSettings implements ISettings {
	public function __construct(
		private AwarenessSettingsService $settings,
		private IInitialState $initialState,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState('admin-settings', [
			'settings' => $this->settings->get(),
			'saveUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin_settings.save'),
			'testUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.admin_settings.test'),
		]);

		return new TemplateResponse(Application::APP_ID, 'settings-admin');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 10;
	}
}
