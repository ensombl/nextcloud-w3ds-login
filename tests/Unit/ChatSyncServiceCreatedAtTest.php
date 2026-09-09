<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Inbound messages must be stamped with the time the sender sent them, not
 * the time the poll ingested them. Stamping "now" makes a catch-up poll
 * render yesterday's conversation as today's.
 */
class ChatSyncServiceCreatedAtTest extends TestCase {
	private function parse(?string $createdAt): \DateTime {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(ChatSyncService::class, 'parseCreatedAt');
		$method->setAccessible(true);

		return $method->invoke($service, $createdAt);
	}

	public function testKeepsIso8601SendTime(): void {
		$iso = '2026-05-01T10:00:00+00:00';

		$this->assertSame(
			(new \DateTime($iso))->getTimestamp(),
			$this->parse($iso)->getTimestamp(),
		);
	}

	public function testYesterdayStaysYesterday(): void {
		$yesterday = (new \DateTime('-1 day'))->getTimestamp();

		$this->assertSame($yesterday, $this->parse(date('c', $yesterday))->getTimestamp());
	}

	public function testAcceptsBareUnixSeconds(): void {
		$ts = (new \DateTime('-2 hours'))->getTimestamp();

		$this->assertSame($ts, $this->parse((string)$ts)->getTimestamp());
	}

	public function testFallsBackToNowWhenMissingOrBlank(): void {
		$now = time();

		$this->assertEqualsWithDelta($now, $this->parse(null)->getTimestamp(), 5);
		$this->assertEqualsWithDelta($now, $this->parse('')->getTimestamp(), 5);
		$this->assertEqualsWithDelta($now, $this->parse('   ')->getTimestamp(), 5);
	}

	public function testFallsBackToNowOnUnparseableValue(): void {
		$this->assertEqualsWithDelta(time(), $this->parse('not-a-date')->getTimestamp(), 5);
	}

	public function testDoesNotSendMessagesToTheEpoch(): void {
		// A zero/epoch stamp would sort to the top of the room permanently.
		$this->assertEqualsWithDelta(time(), $this->parse('0')->getTimestamp(), 5);
		$this->assertEqualsWithDelta(time(), $this->parse('1970-01-01T00:00:00+00:00')->getTimestamp(), 5);
	}

	public function testClampsFutureStampsToNow(): void {
		$this->assertEqualsWithDelta(
			time(),
			$this->parse((new \DateTime('+3 days'))->format('c'))->getTimestamp(),
			5,
		);
	}

	public function testKeepsStampsWithinTheClockSkewTolerance(): void {
		// A peer whose clock runs a couple of minutes fast is ordinary, not
		// garbage: keep its send time rather than rewriting it to now.
		$slightlyAhead = (new \DateTime('+2 minutes'))->getTimestamp();

		$this->assertSame(
			$slightlyAhead,
			$this->parse(date('c', $slightlyAhead))->getTimestamp(),
		);
	}

	public function testClampsStampsBeyondTheClockSkewTolerance(): void {
		$this->assertEqualsWithDelta(
			time(),
			$this->parse((new \DateTime('+10 minutes'))->format('c'))->getTimestamp(),
			5,
		);
	}
}
