<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

/**
 * Minimal two-way lookup between Nextcloud UIDs and W3IDs (eNames).
 *
 * Exists so mention translation can be unit tested without dragging in the
 * database mapper, and so the translation logic states exactly the narrow
 * capability it needs rather than taking a whole QBMapper.
 */
interface IdentityResolverInterface {
	/** Returns the eName for a local UID, or null when the user isn't linked. */
	public function w3idForUid(string $ncUid): ?string;

	/** Returns the local UID for an eName, or null when unknown locally. */
	public function uidForW3id(string $w3id): ?string;
}
