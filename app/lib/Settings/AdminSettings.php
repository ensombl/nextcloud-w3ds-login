<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Settings;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\AwarenessClient;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Admin settings for the Awareness as a Service connection.
 *
 * Inbound chat sync reads from AaaS, which needs an approved consumer and an
 * API key issued from its portal. There is no sensible default base URL to
 * ship: instances are deployed per environment, and pointing at the wrong one
 * would silently deliver nothing, so it is asked for rather than guessed.
 */
class AdminSettings implements ISettings {
	public function __construct(
		private AwarenessClient $awarenessClient,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		// Shows whether the key actually works, not merely whether one was
		// typed in. A key that is present but unapproved looks identical from
		// the outside and silently receives nothing.
		$consumer = $this->awarenessClient->me();

		return new TemplateResponse(
			Application::APP_ID,
			'settings-admin',
			[
				'baseUrl' => $this->awarenessClient->baseUrl(),
				'configured' => $this->awarenessClient->isConfigured(),
				'consumerName' => is_string($consumer['name'] ?? null) ? $consumer['name'] : '',
				'consumerStatus' => is_string($consumer['status'] ?? null) ? $consumer['status'] : '',
				'saveUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.settings.saveAwareness'),
				'webhookUrl' => $this->urlGenerator->getAbsoluteURL(
					$this->urlGenerator->linkToRoute(Application::APP_ID . '.webhook.receive'),
				),
			],
		);
	}

	public function getSection(): string {
		return 'security';
	}

	public function getPriority(): int {
		return 80;
	}
}
