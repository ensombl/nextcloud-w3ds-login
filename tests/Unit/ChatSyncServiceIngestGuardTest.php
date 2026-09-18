<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Listener\MessageSentListener;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Inbound messages must never travel back outbound.
 *
 * Inbound and outbound are separate paths that never call each other, but they
 * meet inside Talk. Displaying an inbound message means calling
 * ChatManager::sendMessage(); materialising an inbound attachment means calling
 * IShareManager::createShare(). Both raise the very same event Talk raises when
 * a person types, and the event carries nothing to say who caused it -- so the
 * message we are only mirroring for display was written back out as a new
 * envelope under this user's identity and fanned out to every participant.
 *
 * Every guard that came before this one tried to answer the question after the
 * fact, from a cache entry or a mapping row, and each was blind at the only
 * moment that mattered:
 *
 *  - the `share:<id>` mapping is written *after* createShare() returns,
 *  - `origin = 'inbound'` is written *after* sendMessage() returns,
 *  - the sync lock keys on a comment id that does not exist yet,
 *  - the inbound-post lock keyed on the *sender's* uid, while the echo is
 *    pushed under the *recipient's*, so it never matched for a message from
 *    another person -- the ordinary case.
 *
 * The Talk call that raises the event is synchronous and still on the stack, so
 * the question is answerable exactly: did we cause this? These tests drive that
 * ordering rather than asserting a constant.
 */
class ChatSyncServiceIngestGuardTest extends TestCase {
	private const SENDER = 'alice';

	/**
	 * @param IUser|null $sender Who the ingest is attributed to, if resolvable.
	 */
	private function service(?IUser $sender = null, ?IUserSession $session = null): ChatSyncService {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($sender);

		foreach ([
			'userManager' => $users,
			'userSession' => $session ?? $this->createMock(IUserSession::class),
		] as $name => $value) {
			$prop = new \ReflectionProperty(ChatSyncService::class, $name);
			$prop->setAccessible(true);
			$prop->setValue($service, $value);
		}

		return $service;
	}

	/**
	 * Run a callable as the inbound path runs its Talk writes.
	 *
	 * @param callable(): void $write
	 */
	private function duringIngest(ChatSyncService $service, callable $write): void {
		$method = new \ReflectionMethod(ChatSyncService::class, 'duringIngest');
		$method->setAccessible(true);
		$method->invoke($service, self::SENDER, $write);
	}

	/**
	 * Outside an ingest, an event is a person typing. Sync must run.
	 */
	public function testAnEventOutsideIngestIsTreatedAsHumanInput(): void {
		$this->assertFalse($this->service()->isIngesting());
	}

	/**
	 * The guard has to be observable at the moment Talk raises the event,
	 * which is *inside* the write, not after it. This is the property every
	 * previous guard lacked.
	 */
	public function testTheGuardIsVisibleWhileTheTalkWriteIsStillRunning(): void {
		$service = $this->service();
		$observed = null;

		$this->duringIngest($service, function () use ($service, &$observed): void {
			// Stands in for Talk dispatching to MessageSentListener from
			// inside sendMessage() / createShare().
			$observed = $service->isIngesting();
		});

		$this->assertTrue($observed, 'the listener must see the guard mid-write');
	}

	/**
	 * The echo itself: a message posted for display is already in an eVault,
	 * and pushing it again would duplicate it under the wrong identity and fan
	 * it out to the whole room.
	 *
	 * Asserted by watching whether the listener reads the event at all. It
	 * returns before touching getComment(), so an untouched event is proof the
	 * push never started.
	 */
	public function testTheListenerIgnoresAnEventRaisedByInboundSync(): void {
		$service = $this->service();
		$listener = new MessageSentListener($service, new NullLogger());
		$event = new DummyTalkEvent();

		$this->duringIngest($service, static function () use ($listener, $event): void {
			$listener->handle($event);
		});

		$this->assertFalse(
			$event->wasRead,
			'the listener must not begin processing an event it caused',
		);
	}

	/**
	 * The counterpart, and the more important half: the guard must not mute
	 * genuine input. Outside an ingest the listener proceeds and reads the
	 * event, which is where the real push would begin.
	 */
	public function testTheListenerProcessesAnEventFromAPersonTyping(): void {
		$service = $this->service();
		$listener = new MessageSentListener($service, new NullLogger());
		$event = new DummyTalkEvent();

		$listener->handle($event);

		$this->assertTrue(
			$event->wasRead,
			'a human-typed message must still reach the outbound path',
		);
	}

	/**
	 * The guard must not become a blanket mute. A person typing in the same
	 * room while an ingest is in flight is ordinary, and their message has to
	 * sync -- the room-scoped lock this replaces swallowed exactly that case
	 * for its whole 600s window.
	 */
	public function testAMessageTypedAfterAnIngestStillSyncs(): void {
		$service = $this->service();

		$this->duringIngest($service, function (): void {
			// inbound work happens here
		});

		$this->assertFalse(
			$service->isIngesting(),
			'the guard must not outlive the write it wraps',
		);
	}

	/**
	 * The scope that makes this safe: one request's ingest must not silence
	 * another request's genuine send.
	 *
	 * Nextcloud serves each request in its own process with its own service
	 * instances, so a property set while handling an inbound packet is
	 * invisible to the request handling somebody's keystrokes. That is exactly
	 * what the replaced guard got wrong: it lived in a cache and a database
	 * row, both shared across requests, so an inbound message in one room
	 * suppressed real messages in that room for the next ten minutes.
	 *
	 * Two instances stand in for two concurrent requests.
	 */
	public function testAnIngestInOneRequestDoesNotSuppressAnotherRequestsSend(): void {
		$receivingRequest = $this->service();
		$typingRequest = $this->service();

		$seenByTypingRequest = null;
		$seenByReceivingRequest = null;

		$this->duringIngest($receivingRequest, static function () use (
			$receivingRequest,
			$typingRequest,
			&$seenByReceivingRequest,
			&$seenByTypingRequest,
		): void {
			$seenByReceivingRequest = $receivingRequest->isIngesting();
			$seenByTypingRequest = $typingRequest->isIngesting();
		});

		$this->assertTrue($seenByReceivingRequest, 'the echo of our own write must be suppressed');
		$this->assertFalse(
			$seenByTypingRequest,
			'a person typing elsewhere must still reach the outbound path',
		);
	}

	/**
	 * A user session that records what it was told, so these tests can watch
	 * identity being taken and given back.
	 *
	 * @param list<string> $log Receives each uid the session was set to, with
	 *                          '(nobody)' for a clear.
	 */
	private function recordingSession(array &$log, ?IUser $initial = null): IUserSession {
		$current = $initial;

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(
			static function () use (&$current): ?IUser {
				return $current;
			},
		);

		$session->method('setUser')->willReturnCallback(
			static function (?IUser $user) use (&$current, &$log): void {
				$current = $user;
				$log[] = $user?->getUID() ?? '(nobody)';
			},
		);

		return $session;
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}

	/**
	 * Talk attributes a file share to whoever is logged in, because it writes
	 * that message itself from a listener we never call. Inbound sync runs
	 * from cron and from a webhook, where nobody is, so every attachment from
	 * another platform was credited to a guest.
	 */
	public function testTheSenderIsAssumedForTheDurationOfTheWrite(): void {
		$log = [];
		$session = $this->recordingSession($log);
		$service = $this->service($this->user(self::SENDER), $session);

		$duringWrite = null;
		$this->duringIngest($service, static function () use ($session, &$duringWrite): void {
			$duringWrite = $session->getUser()?->getUID();
		});

		$this->assertSame(self::SENDER, $duringWrite, 'Talk must see the sender while it writes');
	}

	/**
	 * Identity is borrowed, not kept. One run ingests many packets in
	 * sequence, so a sender left in place would be credited with every later
	 * attachment in that run.
	 */
	public function testTheSessionIsHandedBackAfterTheWrite(): void {
		$log = [];
		$service = $this->service($this->user(self::SENDER), $this->recordingSession($log));

		$this->duringIngest($service, static function (): void {
		});

		$this->assertSame([self::SENDER, '(nobody)'], $log);
	}

	/**
	 * The case that makes this dangerous rather than merely wrong: a packet
	 * that throws must not leave its sender behind for the next one.
	 */
	public function testAFailedWriteStillHandsTheSessionBack(): void {
		$log = [];
		$session = $this->recordingSession($log);
		$service = $this->service($this->user(self::SENDER), $session);

		try {
			$this->duringIngest($service, static function (): void {
				throw new \RuntimeException('eVault unreachable mid-download');
			});
			$this->fail('the exception should propagate');
		} catch (\RuntimeException) {
			// expected
		}

		$this->assertNull($session->getUser(), 'a failed packet must not leak its sender');
	}

	/**
	 * Ingesting two packets in a row must credit each to its own sender. This
	 * is the shape of a real cron run, where one process drains a page of
	 * packets one after another.
	 */
	public function testConsecutivePacketsAreAttributedToTheirOwnSenders(): void {
		$log = [];
		$session = $this->recordingSession($log);

		$seen = [];
		foreach (['alice', 'bob'] as $uid) {
			$service = $this->service($this->user($uid), $session);
			$this->duringIngest($service, static function () use ($session, &$seen): void {
				$seen[] = $session->getUser()?->getUID();
			});
		}

		$this->assertSame(['alice', 'bob'], $seen);
	}

	/**
	 * Restored to whoever was there rather than to nobody. Both callers start
	 * with an empty session today, so this only matters if inbound sync is
	 * ever reached from a request that has a user -- at which point clearing
	 * it would log that person out mid-request.
	 */
	public function testAnExistingSessionIsRestoredRatherThanCleared(): void {
		$log = [];
		$browsing = $this->user('someone-already-logged-in');
		$session = $this->recordingSession($log, $browsing);
		$service = $this->service($this->user(self::SENDER), $session);

		$this->duringIngest($service, static function (): void {
		});

		$this->assertSame('someone-already-logged-in', $session->getUser()?->getUID());
	}

	/**
	 * A sender with no local account is ordinary: a message can arrive from
	 * someone this instance has never provisioned. Ingest continues, since
	 * text messages carry their author explicitly and do not depend on the
	 * session at all.
	 */
	public function testAnUnknownSenderLeavesTheSessionUntouched(): void {
		$log = [];
		$service = $this->service(null, $this->recordingSession($log));

		$ran = false;
		$this->duringIngest($service, static function () use (&$ran): void {
			$ran = true;
		});

		$this->assertTrue($ran, 'ingest must not be abandoned for an unknown sender');
		$this->assertSame([], $log, 'nothing to assume, so nothing to restore');
	}

	/**
	 * Ingest nests: a forwarded attachment materialises a share while the
	 * forward is still being posted. A boolean flag would be cleared by the
	 * inner write and leave the outer one unguarded, so the depth has to
	 * unwind rather than reset.
	 */
	public function testNestedIngestsKeepTheGuardUpUntilTheOutermostCompletes(): void {
		$service = $this->service();
		$afterInner = null;

		$this->duringIngest($service, function () use ($service, &$afterInner): void {
			$this->duringIngest($service, function (): void {
				// inner write, e.g. createShare() inside a forward
			});

			$afterInner = $service->isIngesting();
		});

		$this->assertTrue($afterInner, 'an inner write must not clear the outer guard');
		$this->assertFalse($service->isIngesting());
	}

	/**
	 * A failed ingest must not leave the guard raised, or outbound sync would
	 * be dead for the rest of the request.
	 */
	public function testTheGuardIsReleasedWhenTheTalkWriteThrows(): void {
		$service = $this->service();

		try {
			$this->duringIngest($service, function (): void {
				throw new \RuntimeException('Talk write failed');
			});
			$this->fail('the exception should propagate');
		} catch (\RuntimeException) {
			// expected
		}

		$this->assertFalse($service->isIngesting());
	}
}

/**
 * Minimal stand-in for Talk's ChatMessageSentEvent / SystemMessageSentEvent.
 *
 * The listener probes for getComment() before doing anything else, so recording
 * that call is how these tests observe "the outbound path started" without
 * needing Talk installed.
 */
class DummyTalkEvent extends \OCP\EventDispatcher\Event {
	public bool $wasRead = false;

	public function getComment(): ?object {
		$this->wasRead = true;

		return null;
	}
}
