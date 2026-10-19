<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\AvatarSyncService;
use OCP\Http\Client\IClientService;
use OCP\IAvatar;
use OCP\IAvatarManager;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Profiles sync at every login, and the avatar is the one field that costs a
 * download. It is only fetched again when the profile points somewhere new,
 * or when what is stored is missing or broken.
 */
class AvatarSyncServiceFingerprintTest extends TestCase {
	private const SOURCE = 'https://example.org/a.png';

	private IConfig $config;
	private IAvatar $avatar;
	private IClientService $clients;

	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->avatar = $this->createMock(IAvatar::class);
		$this->clients = $this->createMock(IClientService::class);
	}

	private function service(): AvatarSyncService {
		$manager = $this->createMock(IAvatarManager::class);
		$manager->method('getAvatar')->willReturn($this->avatar);

		return new AvatarSyncService($manager, $this->clients, new NullLogger(), $this->config);
	}

	public function testSkipsTheDownloadWhenThePictureIsUnchanged(): void {
		$this->config->method('getUserValue')->willReturn(hash('sha256', self::SOURCE));
		$this->avatar->method('exists')->willReturn(true);
		$this->avatar->method('isCustomAvatar')->willReturn(true);
		$this->clients->expects($this->never())->method('newClient');

		$this->assertFalse($this->service()->syncIfChanged('alice', ['avatarUrl' => self::SOURCE]));
	}

	public function testDownloadsAPictureThatChanged(): void {
		$this->config->method('getUserValue')->willReturn(hash('sha256', 'https://example.org/old.png'));
		$this->avatar->method('exists')->willReturn(true);
		$this->avatar->method('isCustomAvatar')->willReturn(true);
		$this->clients->expects($this->once())->method('newClient')->willThrowException(new \RuntimeException('offline'));
		$this->config->expects($this->never())->method('setUserValue');

		$this->assertFalse($this->service()->syncIfChanged('alice', ['avatarUrl' => self::SOURCE]));
	}

	public function testDownloadsAgainWhenTheStoredAvatarIsGone(): void {
		$this->config->method('getUserValue')->willReturn(hash('sha256', self::SOURCE));
		$this->avatar->method('exists')->willReturn(false);
		$this->clients->expects($this->once())->method('newClient')->willThrowException(new \RuntimeException('offline'));

		$this->service()->syncIfChanged('alice', ['avatarUrl' => self::SOURCE]);
	}

	public function testRemembersThePictureOnlyOnceStored(): void {
		$png = base64_encode((string)hex2bin('89504e470d0a1a0a'));
		$this->config->method('getUserValue')->willReturn('');
		$this->avatar->method('set')->willThrowException(new \Exception('Unknown filetype'));
		$this->config->expects($this->never())->method('setUserValue');

		$this->assertFalse($this->service()->syncIfChanged('alice', ['avatarUrl' => 'data:image/png;base64,' . $png]));
	}

	public function testDoesNothingWithoutAPicture(): void {
		$this->config->expects($this->never())->method('getUserValue');

		$this->assertFalse($this->service()->syncIfChanged('alice', ['displayName' => 'Alice']));
	}
}
