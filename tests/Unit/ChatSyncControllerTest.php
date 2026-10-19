<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Controller\ChatSyncController;
use OCA\W3dsLogin\Db\ChatVaults;
use OCA\W3dsLogin\Service\AwarenessPoller;
use OCA\W3dsLogin\Service\ChatHistoryService;
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
	private function controller(?string $w3id, ChatSyncStatusService $status, ?AwarenessPoller $poller = null, ?ChatHistoryService $history = null, array $rooms = []): ChatSyncController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$provisioning = $this->createMock(UserProvisioningService::class);
		$provisioning->method('getLinkedW3id')->willReturn($w3id);
		$vaults = $this->createMock(ChatVaults::class);
		$vaults->method('roomsOf')->willReturn($rooms);

		return new ChatSyncController($this->createMock(IRequest::class), $session, $provisioning, $status, $poller ?? $this->createMock(AwarenessPoller::class), $history ?? $this->createMock(ChatHistoryService::class), $vaults, new NullLogger());
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

	/**
	 * Every open Talk page asks every 30 seconds; one recent poll serves
	 * them all.
	 */
	public function testAPageSkipsAPollThatJustHappened(): void {
		$poller = $this->createMock(AwarenessPoller::class);
		$poller->method('isConfigured')->willReturn(true);
		$poller->method('secondsSinceLastPoll')->willReturn(3);
		$poller->expects($this->never())->method('poll');

		$response = $this->controller('@alice', $this->createMock(ChatSyncStatusService::class), $poller)->poll();

		$this->assertFalse($response->getData()['polled']);
	}

	public function testAPagePollsWhenNothingDidRecently(): void {
		$poller = $this->createMock(AwarenessPoller::class);
		$poller->method('isConfigured')->willReturn(true);
		$poller->method('secondsSinceLastPoll')->willReturnOnConsecutiveCalls(120, 0);
		$poller->expects($this->once())->method('poll')->willReturn(4);

		$data = $this->controller('@alice', $this->createMock(ChatSyncStatusService::class), $poller)->poll()->getData();

		$this->assertTrue($data['polled']);
		$this->assertSame(4, $data['applied']);
		$this->assertEqualsWithDelta(time(), $data['lastPolledAt'], 2);
	}

	/**
	 * Opening a conversation fetches now, not up to half a minute later.
	 */
	public function testAnOpenConversationPollsEvenAfterARecentPoll(): void {
		$poller = $this->createMock(AwarenessPoller::class);
		$poller->method('isConfigured')->willReturn(true);
		$poller->method('secondsSinceLastPoll')->willReturn(10);
		$poller->expects($this->once())->method('poll')->willReturn(0);

		$this->controller('@alice', $this->createMock(ChatSyncStatusService::class), $poller)->poll(chatOpen: true);
	}

	/**
	 * The indicator says why nothing arrives, rather than claiming to sync.
	 */
	public function testAnUnconnectedServerIsReportedToThePage(): void {
		$poller = $this->createMock(AwarenessPoller::class);
		$poller->method('isConfigured')->willReturn(false);
		$poller->expects($this->never())->method('poll');

		$data = $this->controller('@alice', $this->createMock(ChatSyncStatusService::class), $poller)->poll()->getData();

		$this->assertFalse($data['configured']);
		$this->assertStringContainsString('administrator', $data['error']);
	}

	/**
	 * The conversation on screen is fetched from its own eVault and its
	 * participants', not just left to the stream.
	 */
	public function testTheOpenConversationIsFetched(): void {
		$poller = $this->createMock(AwarenessPoller::class);
		$poller->method('isConfigured')->willReturn(true);
		$history = $this->createMock(ChatHistoryService::class);
		$history->expects($this->once())->method('fetchOpenRoom')->with('room1');

		$this->controller('@alice', $this->createMock(ChatSyncStatusService::class), $poller, $history, ['room1'])
			->poll(chatOpen: true, chatToken: 'room1');
	}

	/**
	 * The token comes from the page, so only a room the user is in.
	 */
	public function testARoomTheUserIsNotInIsNotFetched(): void {
		$poller = $this->createMock(AwarenessPoller::class);
		$poller->method('isConfigured')->willReturn(true);
		$history = $this->createMock(ChatHistoryService::class);
		$history->expects($this->never())->method('fetchOpenRoom');

		$this->controller('@alice', $this->createMock(ChatSyncStatusService::class), $poller, $history, ['room1'])
			->poll(chatOpen: true, chatToken: 'someone-elses-room');
	}
}
