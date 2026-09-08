<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\ChatSyncService;
use OCA\W3dsLogin\Service\EvaultClient;
use OCA\W3dsLogin\Service\UserProvisioningService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Chat envelopes reference participants by User profile envelope ID where one
 * resolves, and by eName where none does.
 *
 * The eName fallback is what keeps an unresolvable participant in the room:
 * eVault's membership resolution reads either shape out of the same field, so
 * an omitted participant would silently lose group-derived access.
 */
class ChatSyncServiceParticipantIdentifiersTest extends TestCase {
	private const ALICE = '@48468c9a-dc1b-5663-92fb-5e46e3d2a7f0';
	private const BOB = '@9f2f6f42-7a3c-4f0e-8c0d-3f2b1a5d6e77';
	private const ALICE_ENV = '11111111-2222-3333-4444-555555555555';
	private const BOB_ENV = '66666666-7777-8888-9999-000000000000';

	/**
	 * @param array<string, string|null> $links NC UID => linked W3ID
	 * @param string[] $ncUids
	 * @return string[]
	 */
	private function resolveParticipantW3ids(array $links, array $ncUids): array {
		$provisioning = $this->createMock(UserProvisioningService::class);
		$provisioning->method('getLinkedW3id')
			->willReturnCallback(static fn (string $uid): ?string => $links[$uid] ?? null);

		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();
		$this->setPrivate($service, 'userProvisioning', $provisioning);

		$method = new \ReflectionMethod(ChatSyncService::class, 'resolveParticipantW3ids');
		$method->setAccessible(true);

		return $method->invoke($service, $ncUids);
	}

	/**
	 * @param array<string, string> $existingEnvelopes W3ID => envelope ID
	 * @param string[] $input
	 * @return string[]
	 */
	private function resolveParticipantReferences(array $existingEnvelopes, array $input): array {
		$evault = $this->createMock(EvaultClient::class);
		$evault->method('getProfileEnvelopeId')
			->willReturnCallback(static fn (string $w3id): ?string => $existingEnvelopes[$w3id] ?? null);

		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();
		$this->setPrivate($service, 'evaultClient', $evault);
		$this->setPrivate($service, 'logger', new NullLogger());

		$method = new \ReflectionMethod(ChatSyncService::class, 'resolveParticipantReferences');
		$method->setAccessible(true);

		return $method->invoke($service, $input);
	}

	private function setPrivate(object $service, string $name, mixed $value): void {
		$prop = new \ReflectionProperty(ChatSyncService::class, $name);
		$prop->setAccessible(true);
		$prop->setValue($service, $value);
	}

	// -- eName resolution from local accounts --------------------------

	public function testResolvesLinkedUsersToTheirENames(): void {
		$result = $this->resolveParticipantW3ids(
			['alice' => self::ALICE, 'bob' => self::BOB],
			['alice', 'bob'],
		);

		$this->assertSame([self::ALICE, self::BOB], $result);
	}

	public function testSkipsUnlinkedUsers(): void {
		$result = $this->resolveParticipantW3ids(
			['alice' => self::ALICE],
			['alice', 'not-linked'],
		);

		$this->assertSame([self::ALICE], $result);
	}

	public function testDeduplicates(): void {
		$result = $this->resolveParticipantW3ids(
			['alice' => self::ALICE, 'alice_alias' => self::ALICE],
			['alice', 'alice_alias'],
		);

		$this->assertSame([self::ALICE], $result);
	}

	public function testEmptyRosterYieldsNoIdentifiers(): void {
		$this->assertSame([], $this->resolveParticipantW3ids([], []));
	}

	// -- entity references (participantIds) ----------------------------

	public function testPrefersProfileEnvelopeIds(): void {
		$refs = $this->resolveParticipantReferences(
			[self::ALICE => self::ALICE_ENV, self::BOB => self::BOB_ENV],
			[self::ALICE, self::BOB],
		);

		$this->assertSame([self::ALICE_ENV, self::BOB_ENV], $refs);
	}

	public function testFallsBackToENameWhenNoEnvelopeResolves(): void {
		$refs = $this->resolveParticipantReferences([], [self::ALICE]);

		$this->assertSame(
			[self::ALICE],
			$refs,
			'dropping the participant would cost them group-derived access; an eName still resolves',
		);
	}

	public function testNeverDropsAParticipant(): void {
		$refs = $this->resolveParticipantReferences(
			[self::ALICE => self::ALICE_ENV],
			[self::ALICE, self::BOB],
		);

		$this->assertSame(
			[self::ALICE_ENV, self::BOB],
			$refs,
			'every participant must be referenced by one shape or the other',
		);
	}

	public function testDeduplicatesReferences(): void {
		$refs = $this->resolveParticipantReferences(
			[self::ALICE => self::ALICE_ENV, self::BOB => self::ALICE_ENV],
			[self::ALICE, self::BOB],
		);

		$this->assertSame([self::ALICE_ENV], $refs);
	}

	public function testEmptyParticipantListYieldsNoReferences(): void {
		$this->assertSame([], $this->resolveParticipantReferences([], []));
	}
}
