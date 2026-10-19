<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

use OCA\W3dsLogin\Service\LinkHintService;
use OCA\W3dsLogin\Service\ProfileSyncService;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\ISession;
use OCP\User\Events\UserLoggedInEvent;
use Psr\Log\LoggerInterface;

/**
 * Brings a linked user's profile up to date from their eVault at every
 * interactive login, whatever they signed in with.
 *
 * UserLoggedInEvent is the one moment that comes after an identity provider
 * has written its own claims: user_oidc provisions the account, mapping the
 * IdP's name and email onto it, and only then completes the login. Syncing
 * here is what lets the eVault win.
 *
 * For a user who is not linked yet it also picks the eName out of the ID
 * token user_oidc keeps in the session. Released versions of user_oidc do
 * not raise the event OidcTokenListener waits for, so without this no hint
 * is ever stored there and nobody is asked to link.
 *
 * @implements IEventListener<Event>
 */
class LoginProfileSyncListener implements IEventListener {
	/** Where user_oidc keeps the raw ID token of the current login. */
	private const OIDC_ID_TOKEN_SESSION_KEY = 'oidc.id_token';

	/**
	 * user_oidc dispatches its own UserLoggedInEvent right after core's, so
	 * one login arrives twice. This collapses those, not a real throttle.
	 */
	private const DUPLICATE_WINDOW = 60;

	private ICache $cache;

	/** @var array<string, true> */
	private array $handled = [];

	public function __construct(
		private UserProvisioningService $provisioningService,
		private ProfileSyncService $profileSync,
		private LinkHintService $hints,
		private ISession $session,
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed('w3ds_login_login_sync');
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserLoggedInEvent || $event->isTokenLogin()) {
			// App passwords and bearer tokens log in on every API request;
			// those are not the moments someone expects their profile to move.
			return;
		}

		try {
			$user = $event->getUser();
			$uid = $user->getUID();
			if (isset($this->handled[$uid])) {
				return;
			}
			$this->handled[$uid] = true;

			$w3id = $this->provisioningService->getLinkedW3id($uid);
			if ($w3id === null) {
				$this->rememberOidcHint($uid);
				return;
			}

			$key = 'synced_' . md5($uid);
			if ($this->cache->get($key) !== null) {
				return;
			}
			$this->cache->set($key, 1, self::DUPLICATE_WINDOW);

			$this->profileSync->syncProfile($user, $w3id);
		} catch (\Throwable $e) {
			// Never let a profile sync break someone's login.
			$this->logger->info('Could not sync the profile at login', [
				'exception' => $e->getMessage(),
			]);
		}
	}

	private function rememberOidcHint(string $uid): void {
		$idToken = $this->session->get(self::OIDC_ID_TOKEN_SESSION_KEY);
		if (!is_string($idToken) || $idToken === '') {
			return;
		}

		$eName = LinkHintService::eNameFromTokenResponse(['id_token' => $idToken]);
		if ($eName !== null) {
			$this->hints->remember($uid, $eName);
		}
	}
}
