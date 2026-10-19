<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\TalkAccountMigrator;
use PHPUnit\Framework\TestCase;

/**
 * The parts of moving an account's Talk presence that rewrite text: a
 * one-to-one room's name and mentions inside messages. Both must touch the
 * old uid and nothing that merely starts with it.
 */
class TalkAccountMigratorTest extends TestCase {
	private const FROM = 'e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10_1a2b3c4d';

	public function testRenamesAOneToOneRoomAndKeepsItSorted(): void {
		$this->assertSame(
			'["alice","zed"]',
			TalkAccountMigrator::rewriteOneToOneName(json_encode([self::FROM, 'zed']), self::FROM, 'alice'),
		);
	}

	public function testLeavesOtherRoomNamesAlone(): void {
		$this->assertSame('["bob","zed"]', TalkAccountMigrator::rewriteOneToOneName('["bob","zed"]', self::FROM, 'alice'));
		$this->assertSame('Team chat', TalkAccountMigrator::rewriteOneToOneName('Team chat', self::FROM, 'alice'));
	}

	public function testRewritesQuotedAndBareMentions(): void {
		$this->assertSame(
			'hi @"alice" and @"alice"!',
			TalkAccountMigrator::rewriteMentions('hi @"' . self::FROM . '" and @' . self::FROM . '!', self::FROM, 'alice'),
		);
	}

	public function testDoesNotRewriteALongerUid(): void {
		$message = 'ping @' . self::FROM . 'x and @' . self::FROM . '_more';

		$this->assertSame($message, TalkAccountMigrator::rewriteMentions($message, self::FROM, 'alice'));
	}
}
