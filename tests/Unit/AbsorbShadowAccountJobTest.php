<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\BackgroundJob\AbsorbShadowAccountJob;
use OCA\W3dsLogin\Db\TalkAccountMigrator;
use OCA\W3dsLogin\Db\W3dsMapping;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A folded account is only deleted once nothing in it would be lost.
 */
class AbsorbShadowAccountJobTest extends TestCase {
	private const SHADOW = 'shadow_1a2b3c4d';
	private const TARGET = 'oidc-user';

	private IUser $shadow;
	private W3dsMappingMapper $mapper;
	private IConfig $config;
	private bool $empty = true;

	protected function setUp(): void {
		$this->shadow = $this->createMock(IUser::class);
		$this->shadow->method('getUID')->willReturn(self::SHADOW);
		$this->mapper = $this->createMock(W3dsMappingMapper::class);
		$this->mapper->method('findByNcUid')->willThrowException(new DoesNotExistException('none'));
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturn(self::TARGET);
	}

	private function runJob(): void {
		$target = $this->createMock(IUser::class);
		$target->method('getUID')->willReturn(self::TARGET);
		$target->method('getDisplayName')->willReturn('Alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnMap([[self::SHADOW, $this->shadow], [self::TARGET, $target]]);
		// IRootFolder cannot be mocked against the OCP stubs, so the one
		// question asked of it is answered here instead.
		$job = $this->getMockBuilder(AbsorbShadowAccountJob::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasNoFiles'])
			->getMock();
		$job->method('hasNoFiles')->willReturn($this->empty);
		foreach ([
			'userManager' => $users,
			'config' => $this->config,
			'mapper' => $this->mapper,
			'talkMigrator' => $this->createMock(TalkAccountMigrator::class),
			'logger' => new NullLogger(),
		] as $property => $value) {
			$prop = new \ReflectionProperty(AbsorbShadowAccountJob::class, $property);
			$prop->setAccessible(true);
			$prop->setValue($job, $value);
		}
		$method = new \ReflectionMethod($job, 'run');
		$method->setAccessible(true);
		$method->invoke($job, ['shadow' => self::SHADOW, 'target' => self::TARGET]);
	}

	public function testDeletesAnAccountWithNothingInIt(): void {
		$this->shadow->expects($this->once())->method('delete');

		$this->runJob();
	}

	public function testKeepsAnAccountWhoseFilesCouldNotBeMoved(): void {
		// No Files app ownership transfer under unit tests, so files stay put.
		$this->empty = false;
		$this->shadow->expects($this->never())->method('delete');

		$this->runJob();
	}

	public function testLeavesAnAccountThatWasLinkedAgain(): void {
		$this->mapper = $this->createMock(W3dsMappingMapper::class);
		$this->mapper->method('findByNcUid')->willReturn(new W3dsMapping());
		$this->shadow->expects($this->never())->method('delete');

		$this->runJob();
	}
}
