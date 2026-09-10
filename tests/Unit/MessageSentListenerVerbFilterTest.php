<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Listener\MessageSentListener;
use OCA\W3dsLogin\Service\AttachmentSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Which Talk comments are worth replicating.
 *
 * Talk does not treat a file share as a chat message: ChatManager routes it
 * through addSystemMessage(), so it arrives as SystemMessageSentEvent with the
 * `object_shared` verb while user text arrives as ChatMessageSentEvent with
 * `comment`. We listen for both events, which means the verb is the only thing
 * separating a real attachment from room bookkeeping (joins, calls, renames).
 *
 * Getting this wrong fails in both directions: too strict and attachments never
 * leave Nextcloud, too loose and every join/leave becomes an envelope in every
 * participant's eVault.
 */
class MessageSentListenerVerbFilterTest extends TestCase {
	private function syncableVerbs(): array {
		$c = new \ReflectionClassConstant(MessageSentListener::class, 'SYNCABLE_VERBS');

		return $c->getValue();
	}

	private function isSyncable(string $verb): bool {
		return in_array($verb, $this->syncableVerbs(), true);
	}

	public function testUserTextIsSynced(): void {
		$this->assertTrue($this->isSyncable('comment'));
	}

	public function testFileSharesAreSynced(): void {
		$this->assertTrue($this->isSyncable(AttachmentSyncService::TALK_SHARE_VERB));
		// Pinned to the literal Talk uses, so a rename of our own constant
		// cannot quietly stop matching real comments.
		$this->assertTrue($this->isSyncable('object_shared'));
	}

	/**
	 * Talk's own verbs for room bookkeeping. None of these are conversation,
	 * and replicating them would put unrenderable envelopes in every peer's
	 * eVault.
	 */
	public function testRoomBookkeepingIsNotSynced(): void {
		foreach (['system', 'comment_deleted', 'message_deleted', 'reaction', 'reaction_deleted', 'voice-message', 'record-audio', 'record-video'] as $verb) {
			$this->assertFalse($this->isSyncable($verb), "verb: $verb");
		}
	}

	public function testNothingElseSneaksIn(): void {
		$this->assertSame(['comment', 'object_shared'], $this->syncableVerbs());
	}
}
