<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\AppInfo;

use OCA\W3dsLogin\BackgroundJob\PullSyncJob;
use OCA\W3dsLogin\BackgroundJob\TentativeUserCleanupJob;
use OCA\W3dsLogin\Listener\AttendeesAddedTentativeFlipListener;
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
		// Only roster-change events. ParticipantModifiedEvent fires on read-marker
		// updates and similar per-user state changes, which would re-push the chat
		// constantly and risk shrinking it if the live participant read is partial.
		$context->registerEventListener(self::TALK_ATTENDEES_ADDED_EVENT, AttendeesChangedListener::class);
		$context->registerEventListener(self::TALK_ATTENDEES_REMOVED_EVENT, AttendeesChangedListener::class);
		$context->registerEventListener(self::TALK_ATTENDEE_REMOVED_EVENT, AttendeesChangedListener::class);

		// On Talk's AttendeesAddedEvent, flip a tentative-provisioned W3DS
		// user to permanent so the cleanup job stops considering them.
		$context->registerEventListener(self::TALK_ATTENDEES_ADDED_EVENT, AttendeesAddedTentativeFlipListener::class);

		// Inject the Talk room poller script on all logged-in pages
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, BeforeTemplateRenderedListener::class);
	}

	public function boot(IBootContext $context): void {
		$server = $context->getServerContainer();
		/** @var IJobList $jobList */
		$jobList = $server->get(IJobList::class);
		if (!$jobList->has(PullSyncJob::class, null)) {
			$jobList->add(PullSyncJob::class);
		}
		if (!$jobList->has(TentativeUserCleanupJob::class, null)) {
			$jobList->add(TentativeUserCleanupJob::class);
		}
	}
}
