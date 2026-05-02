<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUid()
 * @method void setUid(string $uid)
 * @method int getExpiresAt()
 * @method void setExpiresAt(int $expiresAt)
 */
class TentativeUser extends Entity {
	protected string $uid = '';
	protected int $expiresAt = 0;

	public function __construct() {
		$this->addType('uid', 'string');
		$this->addType('expiresAt', 'integer');
	}
}
