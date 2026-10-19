<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\BackgroundJob\AbsorbShadowAccountJob;
use OCA\W3dsLogin\Db\TalkAccountMigrator;
use OCA\W3dsLogin\Db\TentativeUserMapper;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Service\AccountMergeService;
use OCP\BackgroundJob\IJobList;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Only the account this app made for an eName may be folded into the one
 * its owner signs in with, and the link must stand even when moving its
 * conversations does not work out.
 */
class AccountMergeServiceTest extends TestCase {
	private const W3ID = '@e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10';
	private const SHADOW = 'e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10_1a2b3c4d';
	private const TARGET = 'oidc-user';

	private W3dsMappingMapper $mapper;
	private IConfig $config;
	private IGroupManager $groups;
	private TalkAccountMigrator $migrator;
	private IJobList $jobs;
	private TentativeUserMapper $tentative;

	/** @var array<string, string> */
	private array $prefs = [];

	private bool $hasPersonalData = false;

	protected function setUp(): void {
		$this->mapper = $this->createMock(W3dsMappingMapper::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturnCallback(
			fn (string $uid, string $app, string $key, $default = '') => $this->prefs[$uid . '/' . $key] ?? $default,
		);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->migrator = $this->createMock(TalkAccountMigrator::class);
		$this->jobs = $this->createMock(IJobList::class);
		$this->tentative = $this->createMock(TentativeUserMapper::class);
		$this->prefs[self::SHADOW . '/provisioned'] = '1';
	}

	private function service(): AccountMergeService {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(function (string $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn('Alice');
			return $user;
		});

		// IDBConnection cannot be mocked against the OCP stubs; the only
		// query it serves here is answered by the stubbed method instead.
		$service = $this->getMockBuilder(AccountMergeService::class)
			->disableOriginalConstructor()
			->onlyMethods(['hasCalendarOrContactData'])
			->getMock();
		foreach ([
			'mapper' => $this->mapper,
			'userManager' => $users,
			'groupManager' => $this->groups,
			'config' => $this->config,
			'tentativeUserMapper' => $this->tentative,
			'talkMigrator' => $this->migrator,
			'jobList' => $this->jobs,
			'logger' => new NullLogger(),
		] as $property => $value) {
			$prop = new \ReflectionProperty(AccountMergeService::class, $property);
			$prop->setAccessible(true);
			$prop->setValue($service, $value);
		}
		$service->method('hasCalendarOrContactData')->willReturn($this->hasPersonalData);

		return $service;
	}

	private function mappedToShadow(): void {
		$this->mapper->method('reassign')->with(self::W3ID, self::SHADOW, self::TARGET)->willReturn(true);
	}

	public function testMovesTheENameAndQueuesTheOldAccountForAbsorbing(): void {
		$this->mapper->expects($this->once())->method('reassign')->with(self::W3ID, self::SHADOW, self::TARGET)->willReturn(true);
		$this->migrator->expects($this->once())->method('migrate')->with(self::SHADOW, self::TARGET, 'Alice');
		$this->config->expects($this->once())->method('setUserValue')
			->with(self::SHADOW, 'w3ds_login', AccountMergeService::MERGED_INTO_KEY, self::TARGET);
		$this->jobs->expects($this->once())->method('add')->with(AbsorbShadowAccountJob::class, $this->anything());

		$this->service()->takeOver(self::W3ID, self::SHADOW, self::TARGET);
	}

	public function testRefusesAnAccountThisAppDidNotMake(): void {
		unset($this->prefs[self::SHADOW . '/provisioned']);
		$this->mapper->expects($this->never())->method('reassign');

		$this->expectException(\RuntimeException::class);
		$this->service()->takeOver(self::W3ID, self::SHADOW, self::TARGET);
	}

	public function testRefusesAnAdministrator(): void {
		$this->groups->method('isAdmin')->willReturn(true);
		$this->mapper->expects($this->never())->method('reassign');

		$this->expectException(\RuntimeException::class);
		$this->service()->takeOver(self::W3ID, self::SHADOW, self::TARGET);
	}

	public function testRefusesAnAccountWithCalendarsOrContacts(): void {
		$this->hasPersonalData = true;
		$this->mapper->expects($this->never())->method('reassign');

		$this->expectException(\RuntimeException::class);
		$this->service()->takeOver(self::W3ID, self::SHADOW, self::TARGET);
	}

	public function testTheLinkStandsWhenTalkCannotBeMoved(): void {
		$this->mappedToShadow();
		$this->migrator->method('migrate')->willThrowException(new \RuntimeException('schema'));
		$this->jobs->expects($this->never())->method('add');

		$this->service()->takeOver(self::W3ID, self::SHADOW, self::TARGET);
	}
}
