<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Cross-replica message identity. The signature decides whether an inbound
 * envelope is a replica of a message we already posted or a genuinely new
 * one, so over-broad matching loses real messages.
 */
class ChatSyncServiceMessageIdentityTest extends TestCase {
	private const SENDER = 'alice';
	private const CHAT = 'chat-global-id';

	private function signature(array $data, string $content, string $sender = self::SENDER): string {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(ChatSyncService::class, 'messageIdentitySignature');
		$method->setAccessible(true);

		return (string)$method->invoke($service, $sender, self::CHAT, $data, $content);
	}

	private function innerId(string $chat, string $sender, string $localId): string {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(ChatSyncService::class, 'deriveMessageInnerId');
		$method->setAccessible(true);

		return (string)$method->invoke($service, $chat, $sender, $localId);
	}

	/**
	 * Legacy fallback only: with no source vault supplied there is nothing to
	 * number occurrences against, so identity degrades to content plus
	 * timestamp. Two "ok"s a few minutes apart stay distinct here, but two in
	 * the same second would not -- which is why the real path passes a vault
	 * and numbers occurrences instead. See ChatSyncServiceRepeatedMessageTest.
	 */
	public function testRepeatedContentAtDifferentTimesIsNotDeduped(): void {
		// The reported message-loss case: "ok" sent twice in the same room.
		$first = $this->signature(['createdAt' => '2026-05-01T10:00:00+00:00'], 'ok');
		$second = $this->signature(['createdAt' => '2026-05-01T10:05:00+00:00'], 'ok');

		$this->assertNotSame($first, $second);
	}

	public function testSameMessageReplicaIsDeduped(): void {
		$data = ['createdAt' => '2026-05-01T10:00:00+00:00'];

		$this->assertSame(
			$this->signature($data, 'ok'),
			$this->signature($data, 'ok'),
		);
	}

	public function testInnerIdTakesPrecedenceOverContentAndTimestamp(): void {
		// Replicas carry different createdAt values; a shared inner id must
		// still collapse them to one identity.
		$a = $this->signature(['id' => 'msg-1', 'createdAt' => '2026-05-01T10:00:00+00:00'], 'ok');
		$b = $this->signature(['id' => 'msg-1', 'createdAt' => '2026-05-01T10:09:00+00:00'], 'ok');

		$this->assertSame($a, $b);
	}

	public function testDifferentInnerIdsAreDistinct(): void {
		$a = $this->signature(['id' => 'msg-1'], 'ok');
		$b = $this->signature(['id' => 'msg-2'], 'ok');

		$this->assertNotSame($a, $b);
	}

	public function testDifferentSendersAreDistinct(): void {
		$data = ['createdAt' => '2026-05-01T10:00:00+00:00'];

		$this->assertNotSame(
			$this->signature($data, 'ok', 'alice'),
			$this->signature($data, 'ok', 'bob'),
		);
	}

	public function testBlankAndNonStringInnerIdFallsBackToContentSignature(): void {
		$data = ['createdAt' => '2026-05-01T10:00:00+00:00'];

		$expected = $this->signature($data, 'ok');

		$this->assertSame($expected, $this->signature($data + ['id' => ''], 'ok'));
		$this->assertSame($expected, $this->signature($data + ['id' => 12345], 'ok'));
	}

	public function testMissingCreatedAtStillProducesAStableSignature(): void {
		$this->assertSame($this->signature([], 'ok'), $this->signature([], 'ok'));
		$this->assertNotSame($this->signature([], 'ok'), $this->signature([], 'yes'));
	}

	public function testDerivedInnerIdIsDeterministicAndUuidShaped(): void {
		$a = $this->innerId(self::CHAT, '@alice', '42');
		$b = $this->innerId(self::CHAT, '@alice', '42');

		$this->assertSame($a, $b);
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$a,
		);
	}

	public function testDerivedInnerIdVariesWithEachInput(): void {
		$base = $this->innerId(self::CHAT, '@alice', '42');

		$this->assertNotSame($base, $this->innerId('other-chat', '@alice', '42'));
		$this->assertNotSame($base, $this->innerId(self::CHAT, '@bob', '42'));
		$this->assertNotSame($base, $this->innerId(self::CHAT, '@alice', '43'));
	}
}
