<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Controller\AuthController;
use OCA\W3dsLogin\Service\LinkHintService;
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
 * Someone who signed in through an identity provider is linked to the eName
 * that provider named, and no other. The wallet signature proves which eName
 * the scanner holds; the hint says which one this account is for. When they
 * disagree, linking would attach a different person's chats to the account.
 */
class AuthControllerLinkHintTest extends TestCase {
	private const UID = 'e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10';
	private const HINTED = '@e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10';
	private const OTHER = '@00000000-0000-4000-8000-000000000000';

	/**
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private function link(string $walletEName, ?string $hint, bool $expectLinked): array {
		$request = $this->createMock(IRequest::class);
		$params = ['w3id' => $walletEName, 'session' => 's1', 'signature' => 'sig'];
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default,
		);

		$auth = $this->createMock(W3dsAuthService::class);
		$auth->method('getSessionStatus')->willReturn(['status' => 'pending', 'ncUid' => self::UID]);
		$auth->method('verifySignature')->willReturn(true);
		$auth->expects($expectLinked ? $this->never() : $this->once())->method('markSessionFailed');
		$auth->expects($expectLinked ? $this->once() : $this->never())->method('markSessionComplete');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(IUser::class));

		$provisioning = $this->createMock(UserProvisioningService::class);
		$provisioning->expects($expectLinked ? $this->once() : $this->never())
			->method('linkUser')->with($walletEName, self::UID);

		$hints = $this->createMock(LinkHintService::class);
		$hints->method('getHint')->willReturn($hint);

		$controller = (new \ReflectionClass(AuthController::class))->newInstanceWithoutConstructor();
		$this->set($controller, Controller::class, 'request', $request);
		$this->set($controller, AuthController::class, 'authService', $auth);
		$this->set($controller, AuthController::class, 'provisioningService', $provisioning);
		$this->set($controller, AuthController::class, 'userManager', $userManager);
		$this->set($controller, AuthController::class, 'jobList', $this->createMock(IJobList::class));
		$this->set($controller, AuthController::class, 'logger', new NullLogger());
		$this->set($controller, AuthController::class, 'hints', $hints);

		$response = $controller->callback();

		return [$response->getStatus(), $response->getData()];
	}

	private function set(object $target, string $class, string $property, mixed $value): void {
		$prop = new \ReflectionProperty($class, $property);
		$prop->setAccessible(true);
		$prop->setValue($target, $value);
	}

	public function testLinksTheHintedWallet(): void {
		[$status] = $this->link(self::HINTED, self::HINTED, true);

		$this->assertSame(Http::STATUS_OK, $status);
	}

	public function testRefusesADifferentWallet(): void {
		[$status, $data] = $this->link(self::OTHER, self::HINTED, false);

		$this->assertSame(Http::STATUS_CONFLICT, $status);
		$this->assertStringContainsString(self::HINTED, $data['error']);
	}

	public function testLinksAnyWalletWithoutAHint(): void {
		[$status] = $this->link(self::OTHER, null, true);

		$this->assertSame(Http::STATUS_OK, $status);
	}
}
