<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\IdMapping;
use OCA\W3dsLogin\Db\IdMappingMapper;
use OCA\W3dsLogin\Service\ChatSyncService;
use OCA\W3dsLogin\Service\EvaultClient;
use OCA\W3dsLogin\Service\MentionTranslator;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Edits travel both ways: one made in Talk updates the author's envelope,
 * and one made elsewhere reaches the comment through whichever copy of the
 * message carries it.
 */
class ChatSyncServiceEditTest extends TestCase {
	/**
	 * @param array<string, mixed> $collaborators
	 */
	private function service(array $collaborators): ChatSyncService {
		$service = (new \ReflectionClass(ChatSyncService::class))->newInstanceWithoutConstructor();
		$collaborators += ['logger' => new NullLogger()];
		foreach ($collaborators as $name => $value) {
			$prop = new \ReflectionProperty(ChatSyncService::class, $name);
			$prop->setValue($service, $value);
		}

		return $service;
	}

	private function translator(): MentionTranslator {
		$translator = $this->createMock(MentionTranslator::class);
		$translator->method('toWire')->willReturnArgument(0);
		$translator->method('toTalk')->willReturnArgument(0);

		return $translator;
	}

	private function linked(string $uid, ?string $w3id): UserProvisioningService {
		$provisioning = $this->createMock(UserProvisioningService::class);
		$provisioning->method('getLinkedW3id')->with($uid)->willReturn($w3id);

		return $provisioning;
	}

	private function mapping(string $globalId, string $owner): IdMapping {
		$mapping = new IdMapping();
		$mapping->setGlobalId($globalId);
		$mapping->setOwnerW3id($owner);

		return $mapping;
	}

	public function testReadersAreTheSenderAndEveryReceipt(): void {
		$this->assertSame(
			['@alice', '@bob', '@carol'],
			ChatSyncService::readersOf([
				'senderEName' => '@alice',
				'senderId' => 'profile-envelope-id',
				'readBy' => ['@bob', 42],
				'readByAt' => ['@carol' => '2026-10-19T06:05:49Z', '@bob' => '2026-10-19T06:00:00Z'],
			]),
		);
	}

	public function testAnEditMadeHereUpdatesTheAuthorsEnvelope(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('findByLocalId')->with('message', '77')->willReturn($this->mapping('env-1', '@alice'));
		$stored = [];
		$mapper->method('storeMapping')->willReturnCallback(function (string $type, string $local) use (&$stored): IdMapping {
			$stored[] = $type . ':' . $local;
			return new IdMapping();
		});

		$evault = $this->createMock(EvaultClient::class);
		$evault->method('fetchMetaEnvelopeById')->with('@alice', 'env-1')->willReturn([
			'parsed' => ['id' => 'inner', 'chatId' => 'chat-1', 'content' => 'fixd typo', 'readBy' => ['@bob']],
		]);
		$evault->expects($this->once())->method('updateMetaEnvelope')
			->with('@alice', 'env-1', ChatSyncService::MESSAGE_SCHEMA_ID, $this->callback(
				fn (array $payload): bool => $payload['content'] === 'fixed typo'
					&& $payload['readBy'] === ['@bob']
					&& $payload['id'] === 'inner'
					&& is_string($payload['updatedAt']),
			), ['@alice', '@bob'])
			->willReturn(true);

		// The audience is read from the live room, which needs Talk.
		$service = $this->getMockBuilder(ChatSyncService::class)
			->disableOriginalConstructor()
			->onlyMethods(['roomAudience'])
			->getMock();
		$service->method('roomAudience')->with('@alice', 'room')->willReturn(['@alice', '@bob']);
		foreach ([
			'idMappingMapper' => $mapper,
			'evaultClient' => $evault,
			'userProvisioning' => $this->linked('alice', '@alice'),
			'mentionTranslator' => $this->translator(),
			'logger' => new NullLogger(),
		] as $name => $value) {
			(new \ReflectionProperty(ChatSyncService::class, $name))->setValue($service, $value);
		}

		$service->pushMessageEdit('alice', '77', 'fixed typo', 'room');

		// Both the new words and the ones they replaced: a replay of the
		// original envelope must not put the typo back.
		$this->assertSame(['message_text:77|' . sha1('fixd typo'), 'message_text:77|' . sha1('fixed typo')], $stored);
	}

	public function testSomeoneElsesMessageIsLeftAlone(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('findByLocalId')->willReturn($this->mapping('env-1', '@bob'));

		$evault = $this->createMock(EvaultClient::class);
		$evault->expects($this->never())->method('fetchMetaEnvelopeById');
		$evault->expects($this->never())->method('updateMetaEnvelope');

		$this->service([
			'idMappingMapper' => $mapper,
			'evaultClient' => $evault,
			'userProvisioning' => $this->linked('alice', '@alice'),
			'mentionTranslator' => $this->translator(),
		])->pushMessageEdit('alice', '77', 'new text', 'room');
	}

	public function testAMessageNeverSyncedIsLeftAlone(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('findByLocalId')->willThrowException(new DoesNotExistException(''));

		$evault = $this->createMock(EvaultClient::class);
		$evault->expects($this->never())->method('updateMetaEnvelope');

		$this->service([
			'idMappingMapper' => $mapper,
			'evaultClient' => $evault,
			'userProvisioning' => $this->linked('alice', '@alice'),
		])->pushMessageEdit('alice', '77', 'new text', 'room');
	}

	public function testAnUnreadableEnvelopeIsNotOverwritten(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('findByLocalId')->willReturn($this->mapping('env-1', '@alice'));

		$evault = $this->createMock(EvaultClient::class);
		$evault->method('fetchMetaEnvelopeById')->willReturn(null);
		$evault->expects($this->never())->method('updateMetaEnvelope');

		$this->service([
			'idMappingMapper' => $mapper,
			'evaultClient' => $evault,
			'userProvisioning' => $this->linked('alice', '@alice'),
			'mentionTranslator' => $this->translator(),
		])->pushMessageEdit('alice', '77', 'new text', 'room');
	}

	public function testAnEnvelopeMappedToItsCommentRecordsItForItsCopies(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('getLocalId')->willReturn(null);
		$mapper->expects($this->once())->method('storeMapping')
			->with('message_comment', '77', 'chat-1|inner', '@bob', 'inbound');

		$this->assertSame('77', $this->commentFor($mapper, '77'));
	}

	public function testACopyFindsTheCommentThroughItsInnerId(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('getLocalId')->with('message_comment', 'chat-1|inner')->willReturn('77');
		$mapper->expects($this->never())->method('storeMapping');

		$this->assertSame('77', $this->commentFor($mapper, 'copy:env-2'));
	}

	public function testAFileShareHasNoCommentToEdit(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->expects($this->never())->method('storeMapping');

		$this->assertNull($this->commentFor($mapper, 'share:12'));
	}

	private function commentFor(IdMappingMapper $mapper, string $localId): ?string {
		$method = new \ReflectionMethod(ChatSyncService::class, 'commentForEnvelope');

		return $method->invoke($this->service(['idMappingMapper' => $mapper]), $localId, 'chat-1', ['id' => 'inner'], '@bob');
	}

	/**
	 * A copy still carrying words the comment has already shown is stale,
	 * not an edit back: nothing in Talk is touched.
	 */
	public function testTextAlreadyShownIsNotAnEdit(): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->method('getGlobalId')->with('message_text', '77|' . sha1('old words'))->willReturn('77|' . sha1('old words'));
		$mapper->expects($this->never())->method('storeMapping');

		$method = new \ReflectionMethod(ChatSyncService::class, 'applyInboundEdit');
		$method->invoke($this->service(['idMappingMapper' => $mapper]), 'room', '77', ['type' => 'text', 'content' => 'old words'], '@bob');
	}

	/**
	 * @dataProvider notEditableHere
	 * @param array<string, mixed> $data
	 */
	public function testOnlyTextMessagesAreEdited(array $data): void {
		$mapper = $this->createMock(IdMappingMapper::class);
		$mapper->expects($this->never())->method('getGlobalId');
		$mapper->expects($this->never())->method('storeMapping');

		$method = new \ReflectionMethod(ChatSyncService::class, 'applyInboundEdit');
		$method->invoke($this->service(['idMappingMapper' => $mapper]), 'room', '77', $data, '@bob');
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function notEditableHere(): array {
		return [
			'forward' => [['type' => 'text', 'content' => 'x', 'forwardedFrom' => ['messageId' => 'm']]],
			'system' => [['type' => 'system', 'content' => 'x']],
			'emptied' => [['type' => 'text', 'content' => '  ']],
		];
	}
}
