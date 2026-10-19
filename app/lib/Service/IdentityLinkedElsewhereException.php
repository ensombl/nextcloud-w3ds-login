<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

/**
 * The eName being linked already belongs to another Nextcloud account.
 *
 * A RuntimeException so callers that only report the failure keep working;
 * the existing account is carried so a caller can decide whether it may be
 * taken over instead.
 */
class IdentityLinkedElsewhereException extends \RuntimeException {
	public function __construct(
		private string $existingUid,
	) {
		parent::__construct('This W3DS identity is already linked to another account');
	}

	public function getExistingUid(): string {
		return $this->existingUid;
	}
}
