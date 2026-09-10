<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\W3dsMapping;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * adoptUnmappedAccount() is the repair path taken when createUser() reports
 * the derived username as taken. Because deriveUsername() is deterministic,
 * a mapping found on that account for the *same* W3ID is not a conflict --
 * it is the winner of a provisioning race this call lost. Refusing it left
 * the identity unprovisioned, which in turn dropped the person from the
 * participant list of every conversation they were in.
 */
class UserProvisioningServiceAdoptionTest extends TestCase {
	private const W3ID = '@d0302d87-a8e0-59dd-b574-1649d08a8888';
	private const UID = 'd0302d87-a8e0-59dd-b574-1649d08a8888_538b682c';

	private function adopt(?string $mappedW3id): ?IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn(self::UID);
		// A real display name, so the adoption path does not try to rehydrate.
		$user->method('getDisplayName')->willReturn('Talha 10');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with(self::UID)->willReturn($user);

		$mapper = $this->createMock(W3dsMappingMapper::class);
		if ($mappedW3id === null) {
			$mapper->method('findByNcUid')->willThrowException(new DoesNotExistException('none'));
		} else {
			$mapping = new W3dsMapping();
			$mapping->setW3id($mappedW3id);
			$mapping->setNcUid(self::UID);
			$mapper->method('findByNcUid')->willReturn($mapping);
		}

		$service = (new \ReflectionClass(UserProvisioningService::class))->newInstanceWithoutConstructor();
		$this->setPrivate($service, 'mapper', $mapper);
		$this->setPrivate($service, 'userManager', $userManager);
		$this->setPrivate($service, 'logger', $this->createMock(LoggerInterface::class));

		$method = new \ReflectionMethod(UserProvisioningService::class, 'adoptUnmappedAccount');
		$method->setAccessible(true);

		return $method->invoke($service, self::W3ID, self::UID, null);
	}

	private function setPrivate(object $target, string $property, mixed $value): void {
		$prop = new \ReflectionProperty(UserProvisioningService::class, $property);
		$prop->setAccessible(true);
		$prop->setValue($target, $value);
	}

	public function testReturnsAccountAlreadyMappedToTheSameW3id(): void {
		$this->assertNotNull(
			$this->adopt(self::W3ID),
			'An account mapped to this very W3ID must resolve, not be refused as a conflict',
		);
	}

	public function testRefusesAccountMappedToADifferentW3id(): void {
		$this->assertNull($this->adopt('@somebody-else'));
	}
}
