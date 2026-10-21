<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Listener\MessageSentListener;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCP\Comments\IComment;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Talk announces an edit with a `message_edited` system message, raised with
 * the edited comment as its parent. That is what carries an edit out.
 */
class MessageSentListenerEditTest extends TestCase {
	private function comment(string $id, string $verb, string $actorId, string $message): IComment {
		$comment = $this->createMock(IComment::class);
		$comment->method('getId')->willReturn($id);
		$comment->method('getVerb')->willReturn($verb);
		$comment->method('getActorType')->willReturn('users');
		$comment->method('getActorId')->willReturn($actorId);
		$comment->method('getMessage')->willReturn($message);

		return $comment;
	}

	private function event(IComment $notice, IComment $edited, string $editorId): Event {
		$attendee = new class($editorId) {
			public function __construct(
				private string $id,
			) {
			}
			public function getActorType(): string {
				return 'users';
			}
			public function getActorId(): string {
				return $this->id;
			}
		};
		$participant = new class($attendee) {
			public function __construct(
				private object $attendee,
			) {
			}
			public function getAttendee(): object {
				return $this->attendee;
			}
		};
		$room = new class() {
			public function getToken(): string {
				return 'room';
			}
		};

		return new class($notice, $edited, $participant, $room) extends Event {
			public function __construct(
				private IComment $notice,
				private IComment $edited,
				private object $participant,
				private object $room,
			) {
				parent::__construct();
			}
			public function getComment(): IComment {
				return $this->notice;
			}
			public function getParent(): IComment {
				return $this->edited;
			}
			public function getParticipant(): ?object {
				return null;
			}
			public function getRoom(): object {
				return $this->room;
			}
		};
	}

	/**
	 * Talk writes the notice as whoever made the edit, and raises the event
	 * without a participant.
	 */
	private function notice(string $editor = 'alice'): IComment {
		return $this->comment('900', 'system', $editor, json_encode(['message' => 'message_edited', 'parameters' => ['message' => '77']]));
	}

	public function testTheAuthorsEditIsPushed(): void {
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->once())->method('pushMessageEdit')->with('alice', '77', 'new words', 'room');
		$sync->expects($this->never())->method('pushMessage');

		(new MessageSentListener($sync, new NullLogger()))
			->handle($this->event($this->notice(), $this->comment('77', 'comment', 'alice', 'new words'), 'alice'));
	}

	public function testAModeratorsEditOfSomeoneElsesMessageStaysHere(): void {
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->never())->method('pushMessageEdit');

		(new MessageSentListener($sync, new NullLogger()))
			->handle($this->event($this->notice('moderator'), $this->comment('77', 'comment', 'alice', 'new words'), 'moderator'));
	}

	public function testAnEditWrittenByInboundSyncIsNotPushedBack(): void {
		$sync = $this->createMock(ChatSyncService::class);
		$sync->method('isIngesting')->willReturn(true);
		$sync->expects($this->never())->method('pushMessageEdit');

		(new MessageSentListener($sync, new NullLogger()))
			->handle($this->event($this->notice(), $this->comment('77', 'comment', 'alice', 'new words'), 'alice'));
	}

	public function testOtherSystemMessagesAreNotEdits(): void {
		$sync = $this->createMock(ChatSyncService::class);
		$sync->expects($this->never())->method('pushMessageEdit');
		$sync->expects($this->never())->method('pushMessage');

		$joined = $this->comment('900', 'system', 'alice', json_encode(['message' => 'user_added', 'parameters' => []]));
		(new MessageSentListener($sync, new NullLogger()))
			->handle($this->event($joined, $this->comment('77', 'comment', 'alice', 'x'), 'alice'));
	}
}
