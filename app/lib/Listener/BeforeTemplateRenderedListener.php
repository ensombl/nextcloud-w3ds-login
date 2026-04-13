<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

use OCA\W3dsLogin\AppInfo\Application;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Injects the Talk poller script into logged-in page responses so that
 * open chat rooms poll participant eVaults for new messages every 15s.
 *
 * @implements IEventListener<Event>
 */
class BeforeTemplateRenderedListener implements IEventListener {
    public function handle(Event $event): void {
        if (!($event instanceof BeforeTemplateRenderedEvent)) {
            return;
        }
        if (!$event->isLoggedIn()) {
            return;
        }
        Util::addScript(Application::APP_ID, 'talk-poller');
    }
}
