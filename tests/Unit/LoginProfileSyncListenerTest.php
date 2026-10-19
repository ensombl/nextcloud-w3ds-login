<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Listener\LoginProfileSyncListener;
use OCA\W3dsLogin\Service\LinkHintService;
use OCA\W3dsLogin\Service\ProfileSyncService;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\ISession;
use OCP\IUser;
use OCP\User\Events\UserLoggedInEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every interactive login of a linked user pulls their profile; a login of
 * an unlinked one through user_oidc leaves the eName hint behind.
 */
class LoginProfileSyncListenerTest extends TestCase {
	private const W3ID = '@e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10';

	private UserProvisioningService $provisioning;
	private ProfileSyncService $profileSync;
	private LinkHintService $hints;
	private ISession $session;
	private ICache $cache;

	protected function setUp(): void {
		$this->provisioning = $this->createMock(UserProvisioningService::class);
		$this->profileSync = $this->createMock(ProfileSyncService::class);
		$this->hints = $this->createMock(LinkHintService::class);
		$this->session = $this->createMock(ISession::class);
		$this->cache = $this->createMock(ICache::class);
	}

	private function listener(): LoginProfileSyncListener {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->cache);

		return new LoginProfileSyncListener($this->provisioning, $this->profileSync, $this->hints, $this->session, $factory, new NullLogger());
	}

	private function event(bool $tokenLogin = false): UserLoggedInEvent {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		return new UserLoggedInEvent($user, 'alice', null, $tokenLogin);
	}

	private static function idToken(array $claims): string {
		$encode = static fn (array $v): string => rtrim(strtr(base64_encode((string)json_encode($v)), '+/', '-_'), '=');

		return $encode(['alg' => 'none']) . '.' . $encode($claims) . '.sig';
	}

	public function testSyncsALinkedUser(): void {
		$this->provisioning->method('getLinkedW3id')->willReturn(self::W3ID);
		$this->profileSync->expects($this->once())->method('syncProfile');

		$this->listener()->handle($this->event());
	}

	public function testOneLoginDispatchedTwiceSyncsOnce(): void {
		$this->provisioning->method('getLinkedW3id')->willReturn(self::W3ID);
		$this->profileSync->expects($this->once())->method('syncProfile');

		$listener = $this->listener();
		$listener->handle($this->event());
		$listener->handle($this->event());
	}

	public function testIgnoresAppPasswordAndTokenLogins(): void {
		$this->provisioning->expects($this->never())->method('getLinkedW3id');

		$this->listener()->handle($this->event(true));
	}

	public function testRemembersTheENameFromTheOidcSession(): void {
		$this->provisioning->method('getLinkedW3id')->willReturn(null);
		$this->session->method('get')->with('oidc.id_token')
			->willReturn(self::idToken(['preferred_username' => ltrim(self::W3ID, '@')]));
		$this->hints->expects($this->once())->method('remember')->with('alice', self::W3ID);
		$this->profileSync->expects($this->never())->method('syncProfile');

		$this->listener()->handle($this->event());
	}

	public function testAnUnlinkedLoginWithoutOidcDoesNothing(): void {
		$this->provisioning->method('getLinkedW3id')->willReturn(null);
		$this->session->method('get')->willReturn(null);
		$this->hints->expects($this->never())->method('remember');

		$this->listener()->handle($this->event());
	}

	public function testNeverBreaksALogin(): void {
		$this->provisioning->method('getLinkedW3id')->willThrowException(new \RuntimeException('db down'));

		$this->listener()->handle($this->event());

		$this->addToAssertionCount(1);
	}
}
