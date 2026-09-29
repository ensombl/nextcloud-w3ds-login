<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

use OCA\W3dsLogin\Service\LinkHintService;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Picks the eName out of a login through the OpenID Connect user backend
 * (user_oidc), so an unlinked user can be asked to confirm it with their
 * wallet. Registered by class name: the event only exists when user_oidc is
 * installed, and otherwise simply never fires.
 *
 * @implements IEventListener<Event>
 */
class OidcTokenListener implements IEventListener {
	public function __construct(
		private LinkHintService $hints,
		private UserProvisioningService $provisioningService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getUserId') || !method_exists($event, 'getNewToken')) {
			return;
		}

		try {
			$uid = $event->getUserId();
			if (!is_string($uid) || $uid === '' || $this->provisioningService->getLinkedW3id($uid) !== null) {
				return;
			}

			$token = $event->getNewToken();
			$eName = is_array($token) ? LinkHintService::eNameFromTokenResponse($token) : null;
			if ($eName !== null) {
				$this->hints->remember($uid, $eName);
			}
		} catch (\Throwable $e) {
			// Never let a hint break someone's login.
			$this->logger->info('Could not read an eName hint from the OIDC login', [
				'exception' => $e->getMessage(),
			]);
		}
	}
}
