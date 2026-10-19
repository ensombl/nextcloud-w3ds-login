<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Controller\ChatSyncController;
use OCA\W3dsLogin\Service\ChatSyncStatusService;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The sync button tells the user why it cannot help, when it cannot.
 */
class ChatSyncControllerTest extends TestCase {
	private function controller(?string $w3id, ChatSyncStatusService $status): ChatSyncController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$provisioning = $this->createMock(UserProvisioningService::class);
		$provisioning->method('getLinkedW3id')->willReturn($w3id);

		return new ChatSyncController($this->createMock(IRequest::class), $session, $provisioning, $status, new NullLogger());
	}

	public function testAnUnlinkedUserIsToldToLinkFirst(): void {
		$status = $this->createMock(ChatSyncStatusService::class);
		$status->expects($this->never())->method('request');

		$response = $this->controller(null, $status)->start();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertStringContainsString('Link', $response->getData()['error']);
	}

	public function testAnUnconnectedServerIsReported(): void {
		$status = $this->createMock(ChatSyncStatusService::class);
		$status->method('request')->willThrowException(new \RuntimeException('an administrator has to connect the awareness service'));

		$response = $this->controller('@alice', $status)->start();

		$this->assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		$this->assertStringContainsString('administrator', $response->getData()['error']);
	}

	public function testQueuesForALinkedUser(): void {
		$status = $this->createMock(ChatSyncStatusService::class);
		$status->expects($this->once())->method('request')->with('@alice')->willReturn(['state' => 'queued']);

		$response = $this->controller('@alice', $status)->start();

		$this->assertSame(['state' => 'queued'], $response->getData());
	}
}
