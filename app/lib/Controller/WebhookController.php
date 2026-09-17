<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Service\AwarenessPacketProcessor;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Receives awareness packets pushed by AaaS.
 *
 * The fast path. AaaS delivers within a second of the originating eVault
 * write, where the polling job is a minute behind, so this is what makes an
 * incoming message feel immediate. Both routes hand the packet to the same
 * processor, and the same event arriving by both is expected and harmless --
 * delivery is at-least-once and the processor claims each event once.
 */
class WebhookController extends Controller {
	public function __construct(
		IRequest $request,
		private AwarenessPacketProcessor $processor,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	/**
	 * Accept one awareness packet.
	 *
	 * Always answers 200, including for packets we do not consume. AaaS has no
	 * 4xx short-circuit: any non-2xx is retried with backoff for 24 hours and
	 * then dead-lettered, so reporting an error for a packet that was simply
	 * not ours would bury a real failure in noise.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function receive(): JSONResponse {
		$raw = file_get_contents('php://input');
		$raw = is_string($raw) ? $raw : '';

		$body = json_decode($raw, true);
		if (!is_array($body)) {
			// Fall back to the framework's parsed parameters, which is how
			// eVault's own fanout used to post before AaaS took over delivery.
			$body = $this->request->getParams();
		}

		if (!$this->signatureIsValid($raw)) {
			$this->logger->warning('[W3DS Awareness] Rejected webhook with an invalid signature');

			return new JSONResponse(['status' => 'rejected'], 401);
		}

		if ($body === []) {
			return new JSONResponse(['status' => 'ignored']);
		}

		try {
			$this->processor->process($body);
		} catch (\Throwable $e) {
			// Swallowed on purpose: the packet is already durable at AaaS and
			// the polling job will pick it up again. Reporting failure here
			// would start a 24-hour retry storm for one bad message.
			$this->logger->error('[W3DS Awareness] Webhook processing error', [
				'exception' => $e,
			]);
		}

		return new JSONResponse(['status' => 'ok']);
	}

	/**
	 * Verify the delivery against the subscription secret.
	 *
	 * Only enforced when a secret is configured. Without one the endpoint is
	 * open, which is how it behaved before AaaS and is still the case for the
	 * catch-all subscription AaaS reconciles automatically -- so an
	 * unconfigured instance keeps working rather than silently dropping
	 * everything.
	 */
	private function signatureIsValid(string $raw): bool {
		$secret = $this->config->getAppValue(Application::APP_ID, 'awareness_webhook_secret', '');
		if ($secret === '') {
			return true;
		}

		$provided = $this->request->getHeader('x-aaas-signature');
		if ($provided === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $raw, $secret);

		// Constant-time: a byte-by-byte comparison leaks how much of a forged
		// signature was correct, which is enough to construct one.
		return hash_equals($expected, $provided);
	}
}
