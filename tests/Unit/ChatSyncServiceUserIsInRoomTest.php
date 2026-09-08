<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\ChatSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Membership matching has to accept either identifier shape. We write the
 * User profile envelope ID where one resolves and the eName otherwise, and
 * other platforms made their own choices, so both turn up in the same fields.
 */
class ChatSyncServiceUserIsInRoomTest extends TestCase {
	private const PROFILE_ID = '11111111-2222-3333-4444-555555555555';
	private const ENAME = '@48468c9a-dc1b-5663-92fb-5e46e3d2a7f0';

	private function userIsInRoom(array $parsed, string $profileId, string $w3id): bool {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();
		$method = new \ReflectionMethod(ChatSyncService::class, 'userIsInRoom');
		$method->setAccessible(true);

		return (bool)$method->invoke($service, $parsed, $profileId, $w3id);
	}

	public function testMatchesProfileEnvelopeIdInParticipants(): void {
		// The reference pushChat writes when a profile envelope resolves.
		$parsed = ['participantIds' => ['other-id', self::PROFILE_ID]];

		$this->assertTrue($this->userIsInRoom($parsed, self::PROFILE_ID, self::ENAME));
	}

	public function testMatchesENameInParticipants(): void {
		// The reference pushChat falls back to when no envelope resolves.
		$parsed = ['participantIds' => ['other-id', self::ENAME]];

		$this->assertTrue($this->userIsInRoom($parsed, self::PROFILE_ID, self::ENAME));
	}

	public function testMatchesAMixedParticipantList(): void {
		// Both shapes coexist in one list whenever some participants have a
		// profile envelope and others do not.
		$parsed = ['participantIds' => ['someone-else-envelope-id', '@someone-else', self::ENAME]];

		$this->assertTrue($this->userIsInRoom($parsed, self::PROFILE_ID, self::ENAME));
	}

	public function testMatchesENameInAdmins(): void {
		$parsed = ['admins' => [self::ENAME]];

		$this->assertTrue($this->userIsInRoom($parsed, self::PROFILE_ID, self::ENAME));
	}

	public function testMatchesENameAsOwner(): void {
		$parsed = ['owner' => self::ENAME, 'participantIds' => ['someone-else']];

		$this->assertTrue($this->userIsInRoom($parsed, self::PROFILE_ID, self::ENAME));
	}

	public function testMatchesLegacyProfileIdAsOwner(): void {
		$parsed = ['owner' => self::PROFILE_ID];

		$this->assertTrue($this->userIsInRoom($parsed, self::PROFILE_ID, self::ENAME));
	}

	public function testRejectsRoomTheUserIsNotIn(): void {
		$parsed = [
			'owner' => 'someone-else',
			'participantIds' => ['a', '@someone-else'],
			'admins' => ['b'],
		];

		$this->assertFalse($this->userIsInRoom($parsed, self::PROFILE_ID, self::ENAME));
	}

	public function testHandlesMissingAndMalformedFields(): void {
		$this->assertFalse($this->userIsInRoom([], self::PROFILE_ID, self::ENAME));
		$this->assertFalse($this->userIsInRoom(['participantIds' => 'not-an-array'], self::PROFILE_ID, self::ENAME));
		$this->assertFalse($this->userIsInRoom(['owner' => ['nested']], self::PROFILE_ID, self::ENAME));
		$this->assertFalse($this->userIsInRoom(['participantIds' => [null, 42, ['x']]], self::PROFILE_ID, self::ENAME));
	}

	public function testWorksWhenOnlyENameIsKnown(): void {
		// pullSyncForUser passes the eName as the profile ID when the eVault
		// has no User profile envelope yet.
		$parsed = ['participantIds' => [self::ENAME]];

		$this->assertTrue($this->userIsInRoom($parsed, self::ENAME, self::ENAME));
	}
}
