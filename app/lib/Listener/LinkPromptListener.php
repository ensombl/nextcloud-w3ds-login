<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\LinkHintService;
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
 * Asks a signed-in user who has no W3DS identity linked to connect one, on
 * whichever page they land. Linking takes one wallet scan and is what turns
 * on profile hydration and Talk sync for them.
 *
 * @implements IEventListener<Event>
 */
class LinkPromptListener implements IEventListener {
	public function __construct(
		private IUserSession $userSession,
		private UserProvisioningService $provisioningService,
		private LinkHintService $hints,
		private IInitialState $initialState,
		private IURLGenerator $urlGenerator,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BeforeTemplateRenderedEvent || !$event->isLoggedIn()) {
			return;
		}

		try {
			$user = $this->userSession->getUser();
			if ($user === null) {
				return;
			}
			$uid = $user->getUID();
			if ($this->provisioningService->getLinkedW3id($uid) !== null || !$this->hints->shouldPrompt($uid)) {
				return;
			}

			$this->initialState->provideInitialState('link-prompt', [
				'eName' => $this->hints->getHint($uid),
				'linkStartUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.settings.linkStart'),
				'dismissUrl' => $this->urlGenerator->linkToRoute(Application::APP_ID . '.settings.linkDismiss'),
			]);
			Util::addScript(Application::APP_ID, 'link-prompt');
			Util::addStyle(Application::APP_ID, 'link-prompt');
		} catch (\Throwable $e) {
			$this->logger->info('Could not add the W3DS link prompt', [
				'exception' => $e->getMessage(),
			]);
		}
	}
}
