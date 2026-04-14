<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\AppInfo;

use OCA\W3dsLogin\BackgroundJob\PullSyncJob;
use OCA\W3dsLogin\Listener\AttendeesChangedListener;
use OCA\W3dsLogin\Listener\BeforeTemplateRenderedListener;
use OCA\W3dsLogin\Listener\MessageSentListener;
use OCA\W3dsLogin\Listener\RoomCreatedListener;
use OCA\W3dsLogin\Provider\W3dsLoginProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\BackgroundJob\IJobList;

class Application extends App implements IBootstrap {
    public const APP_ID = 'w3ds_login';

    // Talk event classes (may vary by Talk version)
    private const TALK_MESSAGE_SENT_EVENT = 'OCA\\Talk\\Events\\ChatMessageSentEvent';
    private const TALK_ROOM_CREATED_EVENT = 'OCA\\Talk\\Events\\RoomCreatedEvent';
    private const TALK_ATTENDEES_ADDED_EVENT = 'OCA\\Talk\\Events\\AttendeesAddedEvent';
    private const TALK_ATTENDEES_REMOVED_EVENT = 'OCA\\Talk\\Events\\AttendeesRemovedEvent';
    private const TALK_ATTENDEE_REMOVED_EVENT = 'OCA\\Talk\\Events\\AttendeeRemovedEvent';
    private const TALK_PARTICIPANT_MODIFIED_EVENT = 'OCA\\Talk\\Events\\ParticipantModifiedEvent';

    public function __construct() {
        parent::__construct(self::APP_ID);

        $vendorAutoload = __DIR__ . '/../../vendor/autoload.php';
        if (file_exists($vendorAutoload)) {
            require_once $vendorAutoload;
        }
    }

    public function register(IRegistrationContext $context): void {
        $context->registerAlternativeLogin(W3dsLoginProvider::class);

        // Register Talk event listeners -- Nextcloud resolves lazily,
        // so these are safe even if Talk is not installed (events just never fire)
        $context->registerEventListener(self::TALK_MESSAGE_SENT_EVENT, MessageSentListener::class);
        $context->registerEventListener(self::TALK_ROOM_CREATED_EVENT, RoomCreatedListener::class);
        $context->registerEventListener(self::TALK_ATTENDEES_ADDED_EVENT, AttendeesChangedListener::class);
        $context->registerEventListener(self::TALK_ATTENDEES_REMOVED_EVENT, AttendeesChangedListener::class);
        $context->registerEventListener(self::TALK_ATTENDEE_REMOVED_EVENT, AttendeesChangedListener::class);
        $context->registerEventListener(self::TALK_PARTICIPANT_MODIFIED_EVENT, AttendeesChangedListener::class);

        // Inject the Talk room poller script on all logged-in pages
        $context->registerEventListener(BeforeTemplateRenderedEvent::class, BeforeTemplateRenderedListener::class);
    }

    public function boot(IBootContext $context): void {
        // Register the pull sync timed job (registerTimedJob not available on this NC version)
        $server = $context->getServerContainer();
        /** @var IJobList $jobList */
        $jobList = $server->get(IJobList::class);
        if (!$jobList->has(PullSyncJob::class, null)) {
            $jobList->add(PullSyncJob::class);
        }
    }
}
