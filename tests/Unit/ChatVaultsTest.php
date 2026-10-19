<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\ChatVaults;
use PHPUnit\Framework\TestCase;

class ChatVaultsTest extends TestCase {
	/**
	 * The same eVault arrives spelled with and without its `@`, and from
	 * several places; it is read once.
	 */
	public function testEachEVaultIsListedOnce(): void {
		$this->assertSame(
			['@group', '@bob'],
			ChatVaults::normalise(['@group', 'group', ' @bob ', '', '@GROUP']),
		);
	}
}
