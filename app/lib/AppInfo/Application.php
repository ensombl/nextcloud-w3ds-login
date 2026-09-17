<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\AppInfo;

use OCA\W3dsLogin\BackgroundJob\AwarenessSyncJob;
use OCA\W3dsLogin\BackgroundJob\TentativeUserCleanupJob;
use OCA\W3dsLogin\Listener\AttendeesAddedTentativeFlipListener;
use OCA\W3dsLogin\Listener\AttendeesChangedListener;
use OCA\W3dsLogin\Listener\MessageSentListener;
use OCA\W3dsLogin\Listener\RoomCreatedListener;
use OCA\W3dsLogin\Provider\W3dsLoginProvider;
use OCA\W3dsLogin\Service\IdentityResolver;
use OCA\W3dsLogin\Service\IdentityResolverInterface;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;

class Application extends App implements IBootstrap {
	public const APP_ID = 'w3ds_login';

	/**
	 * The eVault-polling job this app used to run, named as a string because
	 * the class is gone. Installs that ran an earlier version still have the
	 * row in oc_jobs, and it has to be cleared or Nextcloud logs a missing
	 * class on every cron tick.
	 */
	private const RETIRED_PULL_SYNC_JOB = 'OCA\\W3dsLogin\\BackgroundJob\\PullSyncJob';

	// Talk event classes (may vary by Talk version)
	private const TALK_MESSAGE_SENT_EVENT = 'OCA\\Talk\\Events\\ChatMessageSentEvent';
	// A file share is not a chat message to Talk: ChatManager routes it
	// through addSystemMessage(), which emits SystemMessageSentEvent with the
	// `object_shared` verb. Listening only for ChatMessageSentEvent therefore
	// misses every attachment. The listener filters on verb, so ordinary
	// system messages (joins, calls, renames) are still ignored.
	private const TALK_SYSTEM_MESSAGE_SENT_EVENT = 'OCA\\Talk\\Events\\SystemMessageSentEvent';
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

		// Mention translation depends only on a narrow UID <-> eName lookup,
		// so it takes the interface rather than the database mapper.
		$context->registerServiceAlias(IdentityResolverInterface::class, IdentityResolver::class);

		// Register Talk event listeners -- Nextcloud resolves lazily,
		// so these are safe even if Talk is not installed (events just never fire)
		$context->registerEventListener(self::TALK_MESSAGE_SENT_EVENT, MessageSentListener::class);
		$context->registerEventListener(self::TALK_SYSTEM_MESSAGE_SENT_EVENT, MessageSentListener::class);
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
	}

	public function boot(IBootContext $context): void {
		$server = $context->getServerContainer();
		/** @var IJobList $jobList */
		$jobList = $server->get(IJobList::class);
		// Inbound sync reads one ordered packet stream from AaaS. The job it
		// replaces polled every linked user's eVault, and through them every
		// participant's, which is why the same message arrived once per
		// participant and had to be reconciled by content.
		if (!$jobList->has(AwarenessSyncJob::class, null)) {
			$jobList->add(AwarenessSyncJob::class);
		}
		$jobList->remove(self::RETIRED_PULL_SYNC_JOB);

		if (!$jobList->has(TentativeUserCleanupJob::class, null)) {
			$jobList->add(TentativeUserCleanupJob::class);
		}
	}
}
