<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\W3dsMapping;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCA\W3dsLogin\Service\EvaultClient;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Deciding whether an inbound chat belongs here at all.
 *
 * Awareness delivery is a broadcast: every chat created anywhere in the
 * ecosystem arrives, and almost none of them involve this server. Creating a
 * room for each one exposes strangers' conversation metadata and participant
 * lists to whoever administers this instance, and provisions an account for
 * every person mentioned.
 *
 * The decision has to be made without side effects, which is the whole
 * difficulty: the ordinary participant resolution provisions an account for
 * any identity it does not recognise, so asking it whether participants
 * resolve always answers yes -- by creating the account that makes it true.
 */
class ChatSyncServiceChatMembershipTest extends TestCase {
	private const LINKED = '@linked-user';
	private const STRANGER = '@stranger';
	private const PROFILE_ID = '11111111-2222-3333-4444-555555555555';

	/**
	 * @param list<string> $linked eNames that have an account on this server.
	 * @param array<string, string> $profiles Envelope ID to eName, as the eVault would resolve.
	 */
	private function service(array $linked, array $profiles = []): ChatSyncService {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();

		$mapper = $this->createMock(W3dsMappingMapper::class);
		$mapper->method('findByW3id')->willReturnCallback(
			static function (string $w3id) use ($linked): W3dsMapping {
				if (!in_array($w3id, $linked, true)) {
					throw new DoesNotExistException('no such mapping');
				}

				return new W3dsMapping();
			},
		);

		$evault = $this->createMock(EvaultClient::class);
		$evault->method('resolveW3idFromProfileEnvelopeId')->willReturnCallback(
			static fn (string $id): ?string => $profiles[$id] ?? null,
		);

		// Provisioning is the side effect this decision must not have.
		$provisioning = $this->createMock(UserProvisioningService::class);
		$provisioning->expects($this->never())->method('findOrCreateUser');

		foreach ([
			'w3dsMappingMapper' => $mapper,
			'evaultClient' => $evault,
			'userProvisioning' => $provisioning,
		] as $name => $value) {
			$prop = new \ReflectionProperty(ChatSyncService::class, $name);
			$prop->setAccessible(true);
			$prop->setValue($service, $value);
		}

		return $service;
	}

	private function involvesLinkedUser(ChatSyncService $service, array $data, string $ownerW3id): bool {
		$method = new \ReflectionMethod(ChatSyncService::class, 'involvesLinkedUser');
		$method->setAccessible(true);

		return (bool)$method->invoke($service, $data, $ownerW3id);
	}

	/**
	 * The ordinary case, and the reason the app exists: one person here, one
	 * person on another platform.
	 */
	public function testAChatWithOneLinkedParticipantIsAccepted(): void {
		$service = $this->service([self::LINKED]);
		$data = ['participantIds' => [self::STRANGER, self::LINKED]];

		$this->assertTrue($this->involvesLinkedUser($service, $data, self::STRANGER));
	}

	/**
	 * The vault we are reading from belongs to a participant by definition, so
	 * a chat owned by a linked user is ours even if the participant list is
	 * unhelpful.
	 */
	public function testAChatOwnedByALinkedUserIsAccepted(): void {
		$service = $this->service([self::LINKED]);

		$this->assertTrue($this->involvesLinkedUser($service, ['participantIds' => []], self::LINKED));
	}

	/**
	 * Moderators appear in their own field, and a room may list somebody there
	 * without repeating them as a participant.
	 */
	public function testALinkedAdminIsEnough(): void {
		$service = $this->service([self::LINKED]);
		$data = ['participantIds' => [self::STRANGER], 'admins' => [self::LINKED]];

		$this->assertTrue($this->involvesLinkedUser($service, $data, self::STRANGER));
	}

	/**
	 * Participants are named by profile envelope ID as often as by eName,
	 * depending on what resolved when the sending platform wrote the chat.
	 */
	public function testAParticipantNamedByProfileEnvelopeIdIsRecognised(): void {
		$service = $this->service([self::LINKED], [self::PROFILE_ID => self::LINKED]);
		$data = ['participantIds' => [self::PROFILE_ID]];

		$this->assertTrue($this->involvesLinkedUser($service, $data, self::STRANGER));
	}

	/**
	 * The case that was creating rooms and accounts for people who have never
	 * heard of this server.
	 */
	public function testAChatBetweenStrangersIsRejected(): void {
		$service = $this->service([self::LINKED]);
		$data = ['participantIds' => ['@someone-else', '@another-person']];

		$this->assertFalse($this->involvesLinkedUser($service, $data, self::STRANGER));
	}

	/**
	 * A participant list we cannot interpret is not evidence of membership.
	 * Guessing yes would restore the original bug.
	 */
	public function testAnUnresolvableParticipantIsNotTreatedAsLinked(): void {
		$service = $this->service([self::LINKED]);
		$data = ['participantIds' => ['some-envelope-id-that-resolves-to-nothing']];

		$this->assertFalse($this->involvesLinkedUser($service, $data, self::STRANGER));
	}

	/**
	 * Malformed payloads are ordinary on a broadcast stream and must not be
	 * mistaken for membership.
	 */
	public function testAChatWithNoParticipantFieldsIsRejected(): void {
		$service = $this->service([self::LINKED]);

		$this->assertFalse($this->involvesLinkedUser($service, [], self::STRANGER));
		$this->assertFalse($this->involvesLinkedUser($service, ['participantIds' => 'not-a-list'], self::STRANGER));
	}

	/**
	 * The check existing is not the same as the check running. An earlier
	 * version of this membership filter was left defined but uncalled when the
	 * code that used it was removed, and every chat in the ecosystem was
	 * ingested for weeks without a single test noticing.
	 *
	 * Asserted against the source because the alternative -- standing up Talk,
	 * a room manager and a participant service to observe a room not being
	 * created -- tests the mocks rather than the decision.
	 */
	public function testTheGateIsConsultedBeforeAChatBecomesARoom(): void {
		$source = file_get_contents(__DIR__ . '/../../app/lib/Service/ChatSyncService.php');
		$this->assertIsString($source);

		$start = strpos($source, 'public function handleInboundChat(');
		$this->assertNotFalse($start, 'handleInboundChat must exist');

		$resolve = strpos($source, 'resolveChatParticipantUids($data', $start);
		$this->assertNotFalse($resolve, 'participant resolution must follow');

		$gate = strpos($source, 'involvesLinkedUser($data', $start);
		$this->assertNotFalse($gate, 'handleInboundChat must ask whether the chat involves a linked user');
		$this->assertLessThan(
			$resolve,
			$gate,
			'the gate must run before participant resolution, which provisions accounts',
		);
	}
}
