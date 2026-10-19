<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\BackgroundJob\AwarenessSyncJob;
use OCP\IConfig;
use OCP\IURLGenerator;

/**
 * Reads and writes the awareness service settings, for the admin page.
 *
 * The keys are the ones `occ config:app:set` has always used, so an instance
 * configured by hand shows up here as configured, and either way of setting
 * them keeps working. The API key and webhook secret are never sent back to
 * the browser: the page only learns whether they are set.
 */
class AwarenessSettingsService {
	public const BASE_URL_KEY = 'awareness_base_url';
	public const API_KEY_KEY = 'awareness_api_key';
	public const WEBHOOK_SECRET_KEY = 'awareness_webhook_secret';
	public const REGISTRY_URL_KEY = 'registry_base_url';

	public function __construct(
		private IConfig $config,
		private IURLGenerator $urlGenerator,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$lastCron = (int)$this->config->getAppValue('core', 'lastcron', '0');

		return [
			'baseUrl' => $this->value(self::BASE_URL_KEY),
			'hasApiKey' => $this->value(self::API_KEY_KEY) !== '',
			'hasWebhookSecret' => $this->value(self::WEBHOOK_SECRET_KEY) !== '',
			'registryUrl' => $this->value(self::REGISTRY_URL_KEY),
			'webhookUrl' => $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute(Application::APP_ID . '.webhook.receive'),
			),
			'backgroundJobsMode' => $this->config->getAppValue('core', 'backgroundjobs_mode', 'ajax'),
			'lastCron' => $lastCron > 0 ? $lastCron : null,
			'followingSince' => $this->value('awareness_started_at') ?: null,
		];
	}

	/**
	 * Apply what the admin submitted. A null field is left as it is; an
	 * empty string clears it.
	 *
	 * @throws \InvalidArgumentException With a message for the admin
	 */
	public function save(?string $baseUrl, ?string $apiKey, ?string $webhookSecret, ?string $registryUrl): void {
		$changed = false;

		if ($baseUrl !== null) {
			$baseUrl = rtrim(trim($baseUrl), '/');
			if ($baseUrl !== '') {
				self::assertHttpUrl($baseUrl, 'service URL');
			}
			$changed = $this->set(self::BASE_URL_KEY, $baseUrl);
		}
		if ($apiKey !== null) {
			$changed = $this->set(self::API_KEY_KEY, trim($apiKey)) || $changed;
		}
		if ($webhookSecret !== null) {
			$changed = $this->set(self::WEBHOOK_SECRET_KEY, trim($webhookSecret)) || $changed;
		}
		if ($registryUrl !== null) {
			$registryUrl = rtrim(trim($registryUrl), '/');
			if ($registryUrl !== '') {
				self::assertHttpUrl($registryUrl, 'registry URL');
			}
			$this->set(self::REGISTRY_URL_KEY, $registryUrl);
		}

		if ($changed) {
			// The background job confirms the webhook subscription once an
			// hour; new credentials or a new secret should not wait for that.
			$this->config->deleteAppValue(Application::APP_ID, AwarenessSyncJob::SUBSCRIPTION_CHECKED_KEY);
		}
	}

	private static function assertHttpUrl(string $url, string $label): void {
		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		if (!in_array($scheme, ['http', 'https'], true) || !is_string(parse_url($url, PHP_URL_HOST))) {
			throw new \InvalidArgumentException(sprintf('The %s must be an http(s) address.', $label));
		}
	}

	private function value(string $key): string {
		return $this->config->getAppValue(Application::APP_ID, $key, '');
	}

	private function set(string $key, string $value): bool {
		if ($this->value($key) === $value) {
			return false;
		}
		if ($value === '') {
			$this->config->deleteAppValue(Application::APP_ID, $key);
		} else {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}

		return true;
	}
}
