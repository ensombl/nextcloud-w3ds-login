<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Controller\WebhookController;
use OCA\W3dsLogin\Service\AwarenessPacketProcessor;
use OCP\IConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Authenticating pushed awareness packets.
 *
 * The webhook is a public endpoint: it has to be, because the sender is an
 * external service with no Nextcloud session. Anything accepted here is
 * written into people's conversations as though a contact had sent it, so when
 * a subscription secret is configured the signature is the only thing standing
 * between that and an open relay for forged messages.
 */
class WebhookControllerSignatureTest extends TestCase {
	private const SECRET = 'shared-subscription-secret';
	private const BODY = '{"eventId":"e1","id":"env1","ontology":"o","data":{"content":"hi"}}';

	private function controller(string $secret, string $signatureHeader): WebhookController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => strtolower($name) === 'x-aaas-signature' ? $signatureHeader : '',
		);
		$request->method('getParams')->willReturn([]);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($secret): string {
				return $key === 'awareness_webhook_secret' ? $secret : $default;
			},
		);

		return new WebhookController(
			$request,
			$this->createMock(AwarenessPacketProcessor::class),
			$config,
			new NullLogger(),
		);
	}

	/**
	 * @return bool Whether the body would be accepted.
	 */
	private function accepts(WebhookController $controller, string $body): bool {
		$method = new \ReflectionMethod(WebhookController::class, 'signatureIsValid');
		$method->setAccessible(true);

		return (bool)$method->invoke($controller, $body);
	}

	public function testAGenuineSignatureIsAccepted(): void {
		$signature = hash_hmac('sha256', self::BODY, self::SECRET);

		$this->assertTrue($this->accepts($this->controller(self::SECRET, $signature), self::BODY));
	}

	/**
	 * Without this the endpoint accepts anything that reaches it, and a forged
	 * message is indistinguishable from a real one.
	 */
	public function testAForgedSignatureIsRejected(): void {
		$forged = hash_hmac('sha256', self::BODY, 'not-the-secret');

		$this->assertFalse($this->accepts($this->controller(self::SECRET, $forged), self::BODY));
	}

	/**
	 * The signature covers the body, so tampering with the payload after
	 * signing must invalidate it -- otherwise a captured delivery could be
	 * replayed with different contents.
	 */
	public function testATamperedBodyIsRejected(): void {
		$signature = hash_hmac('sha256', self::BODY, self::SECRET);
		$tampered = str_replace('hi', 'transfer the money', self::BODY);

		$this->assertFalse($this->accepts($this->controller(self::SECRET, $signature), $tampered));
	}

	/**
	 * A missing header is not a pass. Presenting nothing must not be easier
	 * than presenting something wrong.
	 */
	public function testAMissingSignatureIsRejectedWhenASecretIsSet(): void {
		$this->assertFalse($this->accepts($this->controller(self::SECRET, ''), self::BODY));
	}

	/**
	 * With no secret configured the endpoint stays open, which is how it
	 * behaved before and how the automatically reconciled catch-all
	 * subscription still delivers. Verifying against a secret nobody set would
	 * silently drop every inbound message instead.
	 */
	public function testDeliveriesAreAcceptedWhenNoSecretIsConfigured(): void {
		$this->assertTrue($this->accepts($this->controller('', ''), self::BODY));
	}
}
