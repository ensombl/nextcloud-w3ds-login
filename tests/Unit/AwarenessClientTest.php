<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\AwarenessClient;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Reading packets from the awareness service.
 *
 * Shapes here are taken from live responses rather than from the prose
 * documentation, which differs in several places: the polling API names the
 * schema field `ontology` where the docs say `schemaId`, packets carry a
 * `receivedAt` the docs do not mention, and history backfilled from before the
 * service existed has a synthetic event id and a null stream version.
 */
class AwarenessClientTest extends TestCase {
	/**
	 * @param array<string, mixed>|null $body Null simulates a transport failure.
	 * @param array<string, mixed>|null $capturedOptions Receives the request options.
	 */
	private function client(?array $body, ?array &$capturedOptions = null): AwarenessClient {
		$http = $this->createMock(IClient::class);

		if ($body === null) {
			$http->method('get')->willThrowException(new \RuntimeException('connection refused'));
		} else {
			$response = $this->createMock(IResponse::class);
			$response->method('getBody')->willReturn((string)json_encode($body));

			$http->method('get')->willReturnCallback(
				static function (string $url, array $options) use ($response, &$capturedOptions): IResponse {
					$capturedOptions = $options;

					return $response;
				},
			);
		}

		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				return match ($key) {
					'awareness_base_url' => 'https://aaas.example.org',
					'awareness_api_key' => 'aaas_test_key',
					default => $default,
				};
			},
		);

		return new AwarenessClient($service, $config, new NullLogger());
	}

	public function testPacketsAndCursorAreReadFromTheResponse(): void {
		$result = $this->client([
			'packets' => [['eventId' => 'e1', 'id' => 'env1']],
			'hasMore' => true,
			'nextCursor' => 'opaque-cursor',
		])->fetchPackets(null);

		$this->assertNotNull($result);
		$this->assertCount(1, $result['packets']);
		$this->assertSame('opaque-cursor', $result['nextCursor']);
		$this->assertTrue($result['hasMore']);
	}

	/**
	 * A transport failure must be distinguishable from an empty page. Reading
	 * "no packets" from a failed request would let the caller advance its
	 * cursor past messages it never saw.
	 */
	public function testAFailedRequestIsNotReportedAsAnEmptyPage(): void {
		$this->assertNull($this->client(null)->fetchPackets(null));
	}

	public function testAMalformedResponseIsTreatedAsAFailure(): void {
		$this->assertNull($this->client(['unexpected' => true])->fetchPackets(null));
	}

	/**
	 * The instance carries packets for every platform in the ecosystem --
	 * hundreds of thousands of them. Without an ontology filter a poll walks
	 * the whole ecosystem's history to find the handful of messages meant for
	 * this server.
	 */
	public function testTheOntologyFilterIsSentAsACommaSeparatedList(): void {
		$options = null;
		$this->client(['packets' => []], $options)->fetchPackets(null, ['chat-schema', 'message-schema']);

		$this->assertSame('chat-schema,message-schema', $options['query']['ontology']);
	}

	/**
	 * A cursor is a position in the stream and a timestamp is a starting
	 * point; sending both invites the service to reconcile two different
	 * answers to "where was I?".
	 */
	public function testACursorSupersedesTheStartingTimestamp(): void {
		$options = null;
		$this->client(['packets' => []], $options)->fetchPackets('saved-cursor', [], '2026-01-01T00:00:00Z');

		$this->assertSame('saved-cursor', $options['query']['cursor']);
		$this->assertArrayNotHasKey('from', $options['query']);
	}

	public function testTheStartingTimestampIsUsedOnTheFirstPoll(): void {
		$options = null;
		$this->client(['packets' => []], $options)->fetchPackets(null, [], '2026-01-01T00:00:00Z');

		$this->assertSame('2026-01-01T00:00:00Z', $options['query']['from']);
	}

	public function testTheApiKeyIsSentAsABearerToken(): void {
		$options = null;
		$this->client(['packets' => []], $options)->fetchPackets(null);

		$this->assertSame('Bearer aaas_test_key', $options['headers']['Authorization']);
	}

	/**
	 * The polling API returns `ontology`; webhook deliveries and the protocol
	 * documentation call the same field `schemaId`.
	 */
	public function testEitherSpellingOfTheOntologyFieldIsRead(): void {
		$this->assertSame('from-polling', AwarenessClient::ontologyOf(['ontology' => 'from-polling']));
		$this->assertSame('from-webhook', AwarenessClient::ontologyOf(['schemaId' => 'from-webhook']));
		$this->assertSame('', AwarenessClient::ontologyOf([]));
	}

	/**
	 * Deduplication keys on the event, never on the envelope: a create and its
	 * later edits share one envelope id, so keying on that would discard every
	 * edit as a duplicate.
	 */
	public function testTheEventIdIsPreferredOverTheEnvelopeId(): void {
		$this->assertSame('event-1', AwarenessClient::eventIdOf([
			'eventId' => 'event-1',
			'id' => 'envelope-1',
		]));
	}

	/**
	 * Falling back to the envelope id is weaker -- it collapses edits -- but a
	 * packet with no key at all would be reprocessed on every delivery.
	 */
	public function testTheEnvelopeIdIsUsedWhenNoEventIdIsPresent(): void {
		$this->assertSame('envelope-1', AwarenessClient::eventIdOf(['id' => 'envelope-1']));
	}

	/**
	 * History predating the service carries `legacy-packet:<uuid>` rather than
	 * a real event id. It is opaque, and treating it as malformed would
	 * reprocess the backfill.
	 */
	public function testASyntheticLegacyEventIdIsAcceptedAsAKey(): void {
		$this->assertSame(
			'legacy-packet:00000476-d494-5a41-9e62-b0147161c72d',
			AwarenessClient::eventIdOf([
				'eventId' => 'legacy-packet:00000476-d494-5a41-9e62-b0147161c72d',
				'id' => '00000476-d494-5a41-9e62-b0147161c72d',
				'streamVersion' => null,
			]),
		);
	}

	/**
	 * Without credentials the app runs webhook-only rather than issuing
	 * unauthenticated requests in a loop.
	 */
	public function testNoRequestIsMadeWithoutCredentials(): void {
		$service = $this->createMock(IClientService::class);
		$service->expects($this->never())->method('newClient');

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnArgument(2);

		$client = new AwarenessClient($service, $config, new NullLogger());

		$this->assertFalse($client->isConfigured());
		$this->assertNull($client->fetchPackets(null));
	}
}
