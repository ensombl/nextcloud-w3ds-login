<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Forwarded messages from other W3DS platforms.
 *
 * The Message schema has no forward concept: `type` is limited to
 * text/image/file/system and `additionalProperties` is false. Peers extend it
 * regardless -- real envelopes also carry `link`, `readByAt` and `file`, none
 * of which are in the schema -- and a forward is expressed as a `forward` type
 * with an empty `content` plus a `forwardedFrom` pointer:
 *
 *     {"type": "forward", "content": "",
 *      "forwardedFrom": {"vault": "@ename", "messageId": "...", "chatId": "..."}}
 *
 * Everything worth rendering lives in the referenced envelope, so a forward
 * displayed as-is is an empty bubble. Shape confirmed against envelopes read
 * from a live eVault, not inferred.
 */
class ChatSyncServiceForwardTest extends TestCase {
	private function service(): ChatSyncService {
		return (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();
	}

	private function attribute(string $content, ?array $forwardedFrom): string {
		$m = new \ReflectionMethod(ChatSyncService::class, 'attributeForward');
		$m->setAccessible(true);

		// No user manager on a constructor-less instance, so the author never
		// resolves to a display name: this exercises the unknown-author path.
		return (string)$m->invoke($this->service(), $content, $forwardedFrom, '@owner');
	}

	// -------- attribution --------

	/**
	 * Talk has no forward concept, so the distinction the sending platform
	 * drew survives only if it is carried in the text.
	 */
	public function testAForwardIsMarkedAsOne(): void {
		$result = $this->attribute('somerthing', [
			'vault' => '@alice',
			'messageId' => 'msg-1',
		]);

		$this->assertStringContainsString('Forwarded', $result);
		$this->assertStringContainsString('somerthing', $result);
	}

	/**
	 * An unknown author must not leak a raw eName at the user; saying only
	 * that it was forwarded is more useful than an opaque identifier.
	 */
	public function testAnUnresolvableAuthorIsNotShownAsARawIdentifier(): void {
		$result = $this->attribute('hello', ['vault' => '@16894677-6b61-5f0b-9d39-df0e03614ca6']);

		$this->assertStringNotContainsString('@16894677', $result);
		$this->assertStringContainsString('Forwarded', $result);
	}

	/**
	 * A forward with no text of its own still has to say what it is, rather
	 * than arriving blank.
	 */
	public function testAnEmptyForwardStillCarriesItsHeader(): void {
		$this->assertNotSame('', $this->attribute('', ['vault' => '@alice']));
	}

	/**
	 * An ordinary message must be left exactly as it is.
	 */
	public function testANormalMessageIsUntouched(): void {
		$this->assertSame('just a message', $this->attribute('just a message', null));
	}

	// -------- resolution --------

	/**
	 * Without a resolvable reference there is nothing to fetch, so the message
	 * must degrade to something readable instead of an empty bubble.
	 */
	public function testAForwardWithoutAUsableReferenceDegradesGracefully(): void {
		$m = new \ReflectionMethod(ChatSyncService::class, 'resolveForwardedMessage');
		$m->setAccessible(true);

		// Missing messageId: nothing to dereference.
		$data = ['type' => 'forward', 'content' => '', 'forwardedFrom' => ['vault' => '@alice']];

		$service = $this->service();
		$logger = new \ReflectionProperty(ChatSyncService::class, 'logger');
		$logger->setAccessible(true);
		$logger->setValue($service, new \Psr\Log\NullLogger());

		[, $raw, , $type, $forwardedFrom] = $m->invoke($service, $data, '', '', 'forward');

		$this->assertSame('forward', $type, 'type is only rewritten once an original is resolved');
		$this->assertSame('', $raw);
		$this->assertIsArray($forwardedFrom, 'the pointer is kept so attribution still renders');
	}

	/**
	 * A message that is not a forward must pass through the resolver
	 * unchanged, and must not report a forward pointer.
	 */
	public function testANonForwardPassesThroughUnchanged(): void {
		$m = new \ReflectionMethod(ChatSyncService::class, 'resolveForwardedMessage');
		$m->setAccessible(true);

		$data = ['type' => 'text', 'content' => 'hello'];
		[$out, $raw, $content, $type, $forwardedFrom] = $m->invoke($this->service(), $data, 'hello', 'hello', 'text');

		$this->assertSame($data, $out);
		$this->assertSame('hello', $raw);
		$this->assertSame('hello', $content);
		$this->assertSame('text', $type);
		$this->assertNull($forwardedFrom);
	}
}
