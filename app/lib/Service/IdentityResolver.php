<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Database-backed {@see IdentityResolverInterface}, reading the same
 * `w3ds_login_mappings` table the rest of the sync path resolves identities
 * through.
 */
class IdentityResolver implements IdentityResolverInterface {
	public function __construct(
		private W3dsMappingMapper $w3dsMappingMapper,
	) {
	}

	public function w3idForUid(string $ncUid): ?string {
		try {
			return $this->w3dsMappingMapper->findByNcUid($ncUid)->getW3id();
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function uidForW3id(string $w3id): ?string {
		try {
			return $this->w3dsMappingMapper->findByW3id($w3id)->getNcUid();
		} catch (DoesNotExistException) {
			return null;
		}
	}
}
