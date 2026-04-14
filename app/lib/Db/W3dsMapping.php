<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getW3id()
 * @method void setW3id(string $w3id)
 * @method string getNcUid()
 * @method void setNcUid(string $ncUid)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class W3dsMapping extends Entity {
	protected string $w3id = '';
	protected string $ncUid = '';
	protected int $createdAt = 0;

	public function __construct() {
		$this->addType('w3id', 'string');
		$this->addType('ncUid', 'string');
		$this->addType('createdAt', 'integer');
	}
}
