<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Controller\AuthController;
use OCA\W3dsLogin\Service\AccountMergeService;
use OCA\W3dsLogin\Service\IdentityLinkedElsewhereException;
use OCA\W3dsLogin\Service\LinkHintService;
use OCA\W3dsLogin\Service\ProfileSyncService;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCA\W3dsLogin\Service\W3dsAuthService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\BackgroundJob\IJobList;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Linking an account to an eName this app already made an account for.
 *
 * That older account exists for nearly everyone -- a wallet login, or simply
 * turning up in a synced chat, is enough -- so refusing the link outright
 * left people who sign in through an IdP unable to link at all. The wallet
 * signature proves the eName is theirs; the older account is folded in.
 */
class AuthControllerLinkTakeoverTest extends TestCase {
	private const UID = 'oidc-user';
	private const SHADOW = 'e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10_1a2b3c4d';
	private const W3ID = '@e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10';

	private W3dsAuthService $auth;
	private UserProvisioningService $provisioning;
	private AccountMergeService $merge;
	private ProfileSyncService $profileSync;
	private LinkHintService $hints;

	protected function setUp(): void {
		$this->auth = $this->createMock(W3dsAuthService::class);
		$this->auth->method('verifySignature')->willReturn(true);
		$this->provisioning = $this->createMock(UserProvisioningService::class);
		$this->merge = $this->createMock(AccountMergeService::class);
		$this->profileSync = $this->createMock(ProfileSyncService::class);
		$this->hints = $this->createMock(LinkHintService::class);
	}

	/**
	 * @return array{0: int, 1: mixed}
	 */
	private function runCallback(?string $ncUid): array {
		$request = $this->createMock(IRequest::class);
		$params = ['w3id' => self::W3ID, 'session' => 's1', 'signature' => 'sig'];
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default,
		);
		$this->auth->method('getSessionStatus')->willReturn(['status' => 'pending', 'ncUid' => $ncUid]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($ncUid ?? self::SHADOW);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$controller = (new \ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();
		$this->set($controller, Controller::class, 'request', $request);
		$this->set($controller, AuthController::class, 'authService', $this->auth);
		$this->set($controller, AuthController::class, 'provisioningService', $this->provisioning);
		$this->set($controller, AuthController::class, 'userManager', $userManager);
		$this->set($controller, AuthController::class, 'jobList', $this->createMock(IJobList::class));
		$this->set($controller, AuthController::class, 'logger', new NullLogger());
		$this->set($controller, AuthController::class, 'hints', $this->hints);
		$this->set($controller, AuthController::class, 'profileSync', $this->profileSync);
		$this->set($controller, AuthController::class, 'accountMerge', $this->merge);

		$response = $controller->callback();

		return [$response->getStatus(), $response->getData()];
	}

	private function set(object $target, string $class, string $property, mixed $value): void {
		$prop = new \ReflectionProperty($class, $property);
		$prop->setAccessible(true);
		$prop->setValue($target, $value);
	}

	public function testTakesOverTheAccountThisAppMadeForTheEName(): void {
		$this->provisioning->method('linkUser')->willThrowException(new IdentityLinkedElsewhereException(self::SHADOW));
		$this->merge->expects($this->once())->method('takeOver')->with(self::W3ID, self::SHADOW, self::UID);
		$this->auth->expects($this->once())->method('markSessionComplete');
		$this->profileSync->expects($this->once())->method('syncProfile');

		[$status] = $this->runCallback(self::UID);

		$this->assertSame(Http::STATUS_OK, $status);
	}

	public function testRefusesWhenTheOtherAccountMayNotBeTakenOver(): void {
		$this->provisioning->method('linkUser')->willThrowException(new IdentityLinkedElsewhereException('someone'));
		$this->merge->method('takeOver')->willThrowException(new \RuntimeException('Ask an administrator to unlink it.'));
		$this->auth->expects($this->once())->method('markSessionFailed');
		$this->auth->expects($this->never())->method('markSessionComplete');
		$this->profileSync->expects($this->never())->method('syncProfile');

		[$status, $data] = $this->runCallback(self::UID);

		$this->assertSame(Http::STATUS_CONFLICT, $status);
		$this->assertStringContainsString('administrator', $data['error']);
	}

	public function testAWalletForAnotherENameIsRefusedBeforeAnyTakeover(): void {
		$this->hints->method('getHint')->willReturn('@00000000-0000-4000-8000-000000000000');
		$this->provisioning->expects($this->never())->method('linkUser');
		$this->merge->expects($this->never())->method('takeOver');

		[$status] = $this->runCallback(self::UID);

		$this->assertSame(Http::STATUS_CONFLICT, $status);
	}

	public function testWalletLoginSyncsTheWholeProfile(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::SHADOW);
		$this->provisioning->method('findOrCreateUser')->willReturn($user);
		$this->profileSync->expects($this->once())->method('syncProfile')->with($user, self::W3ID);

		[$status] = $this->runCallback(null);

		$this->assertSame(Http::STATUS_OK, $status);
	}
}
