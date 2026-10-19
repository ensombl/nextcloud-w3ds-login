<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Listener;

use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Service\LinkHintService;
use OCA\W3dsLogin\Service\ProfileSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Gives user_oidc the eVault's display name and email to write, instead of
 * whatever the identity provider sent.
 *
 * user_oidc writes the mapped name and email on every login. An IdP that
 * carries no name of its own -- Rauthy in front of the W3DS connector, for
 * one -- falls back to the username, which is the eName, so every login
 * turned a correct name back into a UUID. Correcting it after the login loses
 * that race; supplying the value user_oidc is about to write does not.
 *
 * Only for the account actually linked to the eName the token names: the
 * claim alone is the IdP's word, and a username that merely looks like an
 * eName must not borrow someone else's name.
 *
 * Registered by class name: the event only exists when user_oidc is
 * installed, and otherwise simply never fires.
 *
 * @implements IEventListener<Event>
 */
class OidcAttributeListener implements IEventListener {
	private const DISPLAY_NAME = 'mappingDisplayName';
	private const EMAIL = 'mappingEmail';

	public function __construct(
		private W3dsMappingMapper $mappings,
		private ProfileSyncService $profileSync,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!method_exists($event, 'getAttribute') || !method_exists($event, 'getClaims') || !method_exists($event, 'setValue')) {
			return;
		}

		try {
			$attribute = $event->getAttribute();
			if ($attribute !== self::DISPLAY_NAME && $attribute !== self::EMAIL) {
				return;
			}

			$w3id = $this->linkedEName($event->getClaims());
			if ($w3id === null) {
				return;
			}

			$value = $attribute === self::DISPLAY_NAME
				? $this->profileSync->displayNameFor($w3id)
				: $this->profileSync->emailFor($w3id);
			if ($value === null) {
				// Nothing better to offer: keep what the IdP sent.
				return;
			}

			$event->setValue($value);
			// user_oidc documents this: a later listener could otherwise
			// overwrite the value.
			if (method_exists($event, 'stopPropagation')) {
				$event->stopPropagation();
			}
		} catch (\Throwable $e) {
			// Never let this break someone's login.
			$this->logger->info('Could not supply the eVault profile to the OIDC login', [
				'exception' => $e->getMessage(),
			]);
		}
	}

	/**
	 * The eName the token names, if the account it signs in to is the one
	 * linked to that eName.
	 */
	private function linkedEName(object $claims): ?string {
		$eName = LinkHintService::eNameFromClaims($claims);
		$sub = is_string($claims->sub ?? null) ? $claims->sub : null;
		if ($eName === null || $sub === null) {
			return null;
		}

		try {
			$mapping = $this->mappings->findByW3id($eName);
		} catch (DoesNotExistException) {
			return null;
		}

		// With unique ids off, as the installation guide configures it,
		// user_oidc names the account after the uid claim, which is `sub` or
		// `preferred_username` depending on the mapping.
		$uid = $mapping->getNcUid();
		$username = is_string($claims->preferred_username ?? null) ? $claims->preferred_username : null;

		return $uid === $sub || ($username !== null && $uid === $username) ? $eName : null;
	}
}
