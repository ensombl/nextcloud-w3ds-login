<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\TalkHistory;
use PHPUnit\Framework\TestCase;

/**
 * Talk shows a conversation in id order, so ids are handed back out in the
 * order things were sent.
 */
class TalkHistoryTest extends TestCase {
	/**
	 * The case from the bug report: two of Merul's messages were written
	 * before Sergio's earlier one, so his question showed under the answers.
	 */
	public function testAnEarlierMessageWrittenLateTakesItsPlace(): void {
		$moves = TalkHistory::plan([
			[1199, '2026-10-19 06:05:53'],
			[1200, '2026-10-19 06:06:04'],
			[1532, '2026-10-19 05:25:44'],
			[1692, '2026-10-19 07:45:59'],
		]);

		$this->assertSame([1532 => 1199, 1199 => 1200, 1200 => 1532], $moves);
	}

	public function testARoomInOrderIsLeftAlone(): void {
		$this->assertSame([], TalkHistory::plan([
			[10, '2026-10-01 10:00:00'],
			[11, '2026-10-01 10:00:05'],
			[30, '2026-10-02 09:00:00'],
		]));
	}

	/**
	 * Entries sent in the same second keep their existing relative order.
	 */
	public function testTiesKeepTheirOrder(): void {
		$this->assertSame([], TalkHistory::plan([
			[5, '2026-10-01 10:00:00'],
			[6, '2026-10-01 10:00:00'],
		]));
	}

	public function testOnlyTheRoomsOwnIdsAreUsed(): void {
		$moves = TalkHistory::plan([
			[100, '2026-10-02 00:00:00'],
			[250, '2026-10-01 00:00:00'],
		]);

		$this->assertEquals([100 => 250, 250 => 100], $moves);
	}
}
