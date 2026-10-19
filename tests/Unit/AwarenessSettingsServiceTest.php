<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\AwarenessSettingsService;
use OCP\IConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * The admin page writes the same keys `occ config:app:set` always did, and
 * never hands a secret back to the browser.
 */
class AwarenessSettingsServiceTest extends TestCase {
	/** @var array<string, string> */
	private array $appConfig = [];

	private function service(): AwarenessSettingsService {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $this->appConfig[$app . '/' . $key] ?? $default,
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->appConfig[$app . '/' . $key] = $value;
			},
		);
		$config->method('deleteAppValue')->willReturnCallback(
			function (string $app, string $key): void {
				unset($this->appConfig[$app . '/' . $key]);
			},
		);

		return new AwarenessSettingsService($config, $this->createMock(IURLGenerator::class));
	}

	public function testNeverReturnsTheSecrets(): void {
		$this->appConfig['w3ds_login/awareness_api_key'] = 'aaas_secret';
		$this->appConfig['w3ds_login/awareness_webhook_secret'] = 'hush';

		$settings = $this->service()->get();

		$this->assertTrue($settings['hasApiKey']);
		$this->assertTrue($settings['hasWebhookSecret']);
		$this->assertStringNotContainsString('aaas_secret', (string)json_encode($settings));
		$this->assertStringNotContainsString('hush', (string)json_encode($settings));
	}

	public function testWritesTheKeysOccUses(): void {
		$this->service()->save('https://aaas.example/ ', 'aaas_key', null, null);

		$this->assertSame('https://aaas.example', $this->appConfig['w3ds_login/awareness_base_url']);
		$this->assertSame('aaas_key', $this->appConfig['w3ds_login/awareness_api_key']);
	}

	public function testAFieldNotSentIsKept(): void {
		$this->appConfig['w3ds_login/awareness_api_key'] = 'aaas_key';

		$this->service()->save('https://aaas.example', null, null, null);

		$this->assertSame('aaas_key', $this->appConfig['w3ds_login/awareness_api_key']);
	}

	public function testNewCredentialsReRegisterTheWebhookPromptly(): void {
		$this->appConfig['w3ds_login/awareness_subscription_checked'] = (string)time();

		$this->service()->save(null, null, 'new-secret', null);

		$this->assertArrayNotHasKey('w3ds_login/awareness_subscription_checked', $this->appConfig);
	}

	public function testRefusesAnAddressThatIsNotHttp(): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->save('javascript:alert(1)', null, null, null);
	}

	public function testReportsWhetherCronHasRun(): void {
		$this->assertNull($this->service()->get()['lastCron']);

		$this->appConfig['core/lastcron'] = '1700000000';
		$this->assertSame(1700000000, $this->service()->get()['lastCron']);
	}
}
