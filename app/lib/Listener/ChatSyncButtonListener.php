<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Puts a "Sync W3DS chats" button into Talk, for linked users.
 *
 * Talk has no extension point for its conversation list, so the button is
 * added by a small script once Talk has rendered.
 *
 * @implements IEventListener<Event>
 */
class ChatSyncButtonListener implements IEventListener {
	private const TALK_APP_ID = 'spreed';

	public function __construct(
		private IUserSession $userSession,
		private UserProvisioningService $provisioningService,
		private IInitialState $initialState,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent || !$event->isLoggedIn()
			|| $event->getResponse()->getApp() !== self::TALK_APP_ID) {
			return;
		}

		try {
			$user = $this->userSession->getUser();
			if ($user === null || $this->provisioningService->getLinkedW3id($user->getUID()) === null) {
				// Unlinked users have nothing to sync; the link prompt is
				// what they need, and it is shown on its own.
				return;
			}

			$this->initialState->provideInitialState('chat-sync', [
				'url' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.chat_sync.start'),
			]);
			Util::addScript(Application::APP_ID, 'chat-sync');
			Util::addStyle(Application::APP_ID, 'chat-sync');
		} catch (\Throwable $e) {
			$this->logger->info('Could not add the W3DS chat sync button', [
				'exception' => $e->getMessage(),
			]);
		}
	}
}
