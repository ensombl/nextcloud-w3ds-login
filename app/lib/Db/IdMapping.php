<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getEntityType()
 * @method void setEntityType(string $entityType)
 * @method string getLocalId()
 * @method void setLocalId(string $localId)
 * @method string getGlobalId()
 * @method void setGlobalId(string $globalId)
 * @method string getOwnerW3id()
 * @method void setOwnerW3id(string $ownerW3id)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class IdMapping extends Entity {
	protected string $entityType = '';
	protected string $localId = '';
	protected string $globalId = '';
	protected string $ownerW3id = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;

	public function __construct() {
		$this->addType('entityType', 'string');
		$this->addType('localId', 'string');
		$this->addType('globalId', 'string');
		$this->addType('ownerW3id', 'string');
		$this->addType('createdAt', 'integer');
		$this->addType('updatedAt', 'integer');
	}
}
