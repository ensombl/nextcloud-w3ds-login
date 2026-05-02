<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

use OCA\W3dsLogin\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

/**
 * Injects the Talk poller script on every logged-in page. If the current
 * user is a freshly-provisioned W3DS user who hasn't set a password yet,
 * also injects a small redirect shim that bounces them to the
 * password-setup page until they pick one.
 *
 * @implements IEventListener<Event>
 */
class BeforeTemplateRenderedListener implements IEventListener {
	private const SETUP_PATH = '/apps/w3ds_login/password-setup';

	public function __construct(
		private IUserSession $userSession,
		private IConfig $config,
		private IRequest $request,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent)) {
			return;
		}
		if (!$event->isLoggedIn()) {
			return;
		}

		Util::addScript(Application::APP_ID, 'talk-poller');

		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$mustSet = $this->config->getUserValue(
			$user->getUID(),
			Application::APP_ID,
			'must_set_password',
			'',
		);
		if ($mustSet !== '1') {
			return;
		}

		// Don't redirect-loop when the user is already on the setup page.
		if (str_contains($this->request->getRequestUri(), self::SETUP_PATH)) {
			return;
		}

		Util::addScript(Application::APP_ID, 'password-gate');
	}
}
