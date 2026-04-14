<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getW3id()
 * @method void setW3id(string $w3id)
 * @method string getSchemaId()
 * @method void setSchemaId(string $schemaId)
 * @method string|null getCursor()
 * @method void setCursor(?string $cursor)
 * @method int getLastSyncAt()
 * @method void setLastSyncAt(int $lastSyncAt)
 */
class SyncCursor extends Entity {
	protected string $w3id = '';
	protected string $schemaId = '';
	protected ?string $cursor = null;
	protected int $lastSyncAt = 0;

	public function __construct() {
		$this->addType('w3id', 'string');
		$this->addType('schemaId', 'string');
		$this->addType('cursor', 'string');
		$this->addType('lastSyncAt', 'integer');
	}
}
