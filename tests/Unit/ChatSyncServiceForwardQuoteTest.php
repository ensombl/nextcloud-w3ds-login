<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\IdMappingMapper;
use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Rendering a forward as a real quote rather than as text.
 *
 * Prefixing the message with "Forwarded from X" is indistinguishable from a
 * user simply typing that, so it cannot be trusted as provenance. Talk's own
 * reply mechanism renders a quoted block attributed to the original author by
 * the server, which is both the native representation and unforgeable.
 *
 * That is only possible when the forwarded original exists in this room, so
 * the textual attribution stays as the fallback.
 */
class ChatSyncServiceForwardQuoteTest extends TestCase {
	private function service(?IdMappingMapper $mapper = null): ChatSyncService {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();

		if ($mapper !== null) {
			$prop = new \ReflectionProperty(ChatSyncService::class, 'idMappingMapper');
			$prop->setAccessible(true);
			$prop->setValue($service, $mapper);
		}

		return $service;
	}

	private function resolve(?IdMappingMapper $mapper, ?array $forwardedFrom): ?string {
		$m = new \ReflectionMethod(ChatSyncService::class, 'localCommentForForward');
		$m->setAccessible(true);

		return $m->invoke($this->service($mapper), $forwardedFrom, 'room-token');
	}

	private function mapperReturning(?string $localId): IdMappingMapper {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('getLocalId')->willReturn($localId);

		return $mapper;
	}

	/**
	 * When the forwarded message is already here, quote it: that is what makes
	 * Talk render a genuine attributed quote block.
	 */
	public function testAKnownOriginalResolvesToItsLocalComment(): void {
		$this->assertSame(
			'213',
			$this->resolve($this->mapperReturning('213'), ['messageId' => 'envelope-id']),
		);
	}

	/**
	 * A message never synced here has no local parent, so the caller must fall
	 * back to naming the author in the text.
	 */
	public function testAnUnknownOriginalHasNoLocalComment(): void {
		$this->assertNull($this->resolve($this->mapperReturning(null), ['messageId' => 'envelope-id']));
	}

	/**
	 * Attachments are recorded as `share:<id>`, which is a share and not a
	 * comment: quoting it would fail.
	 */
	public function testAShareIsNotQuotable(): void {
		$this->assertNull($this->resolve($this->mapperReturning('share:6'), ['messageId' => 'envelope-id']));
	}

	public function testAMissingOrEmptyPointerIsIgnored(): void {
		$mapper = $this->mapperReturning('213');

		$this->assertNull($this->resolve($mapper, null));
		$this->assertNull($this->resolve($mapper, []));
		$this->assertNull($this->resolve($mapper, ['messageId' => '']));
		$this->assertNull($this->resolve($mapper, ['messageId' => 42]));
	}

	/**
	 * A lookup failure must not take the whole message down; posting it
	 * unquoted is strictly better than losing it.
	 */
	public function testALookupFailureDegradesToNoQuote(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('getLocalId')->willThrowException(new \RuntimeException('db down'));

		$this->assertNull($this->resolve($mapper, ['messageId' => 'envelope-id']));
	}

	/**
	 * postTalkMessage() has to accept a parent for any of this to reach Talk.
	 */
	public function testPostingSupportsAReplyParent(): void {
		$params = (new \ReflectionMethod(ChatSyncService::class, 'postTalkMessage'))->getParameters();
		$names = array_map(static fn ($p) => $p->getName(), $params);

		$this->assertContains('replyToLocalId', $names);
	}
}
