<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\AvatarSyncService;
use OCA\W3dsLogin\Service\EvaultClient;
use OCA\W3dsLogin\Service\ProfileSyncService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The eVault profile is the source of truth and is applied at every login:
 * a non-empty value always wins, an empty one never erases what Nextcloud
 * has, and nothing about it may ever fail a login.
 */
class ProfileSyncServiceTest extends TestCase {
	private const W3ID = '@e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10';

	private EvaultClient $evault;
	private AvatarSyncService $avatar;
	private IAccountManager $accounts;
	private ICache $cache;

	/** @var array<string, string> */
	private array $stored = [];

	protected function setUp(): void {
		$this->evault = $this->createMock(EvaultClient::class);
		$this->avatar = $this->createMock(AvatarSyncService::class);
		$this->accounts = $this->createMock(IAccountManager::class);
		$this->cache = $this->createMock(ICache::class);
	}

	private function service(): ProfileSyncService {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($this->cache);

		return new ProfileSyncService(
			$this->evault,
			$this->avatar,
			$this->accounts,
			$this->createMock(IEventDispatcher::class),
			$factory,
			new NullLogger(),
		);
	}

	private function user(string $displayName = 'old name', string $email = ''): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('getSystemEMailAddress')->willReturn($email === '' ? null : $email);

		return $user;
	}

	/**
	 * An account whose properties live in $this->stored, so a test can see
	 * exactly what was written.
	 */
	private function account(): IAccount {
		$account = $this->createMock(IAccount::class);
		$account->method('getProperty')->willReturnCallback(function (string $name) {
			$property = $this->createMock(IAccountProperty::class);
			$property->method('getValue')->willReturnCallback(fn () => $this->stored[$name] ?? '');
			$property->method('setValue')->willReturnCallback(function (string $value) use ($name, $property) {
				$this->stored[$name] = $value;
				return $property;
			});
			return $property;
		});

		return $account;
	}

	// -------- field mapping --------

	public function testMapsEveryProfileFieldNextcloudHasAPlaceFor(): void {
		$values = ProfileSyncService::accountValues([
			'telephone' => '+31 20 123 4567',
			'address' => ['streetAddress' => 'Main St 1', 'postalCode' => '1011', 'addressLocality' => 'Amsterdam', 'addressCountry' => 'NL'],
			'website' => 'example.org',
			'bio' => 'Hello there',
		], [
			'headline' => 'Builder',
			'workExperience' => [
				['company' => 'Old Co', 'role' => 'Intern', 'startDate' => '2019-01-01', 'endDate' => '2020-01-01', 'sortOrder' => 0],
				['company' => 'Now Co', 'role' => 'Engineer', 'startDate' => '2021-01-01', 'sortOrder' => 1],
			],
		]);

		$this->assertSame([
			IAccountManager::PROPERTY_PHONE => '+31 20 123 4567',
			IAccountManager::PROPERTY_ADDRESS => 'Main St 1, 1011 Amsterdam, NL',
			IAccountManager::PROPERTY_WEBSITE => 'https://example.org',
			IAccountManager::PROPERTY_BIOGRAPHY => 'Hello there',
			IAccountManager::PROPERTY_HEADLINE => 'Builder',
			IAccountManager::PROPERTY_ORGANISATION => 'Now Co',
			IAccountManager::PROPERTY_ROLE => 'Engineer',
		], $values);
	}

	public function testFallsBackToTheProfessionalProfileAndToLocation(): void {
		$values = ProfileSyncService::accountValues(
			['location' => 'Berlin'],
			['phone' => '+49 30 1234', 'website' => 'https://pro.example', 'bio' => 'Pro bio'],
		);

		$this->assertSame('+49 30 1234', $values[IAccountManager::PROPERTY_PHONE]);
		$this->assertSame('Berlin', $values[IAccountManager::PROPERTY_ADDRESS]);
		$this->assertSame('https://pro.example', $values[IAccountManager::PROPERTY_WEBSITE]);
		$this->assertSame('Pro bio', $values[IAccountManager::PROPERTY_BIOGRAPHY]);
	}

	public function testLeavesOutWhatTheProfileDoesNotSay(): void {
		$this->assertSame([], ProfileSyncService::accountValues(['bio' => '   ', 'website' => null], []));
	}

	public function testRefusesWebsitesThatAreNotHttp(): void {
		$this->assertNull(ProfileSyncService::website('javascript:alert(1)'));
		$this->assertNull(ProfileSyncService::website('ftp://example.org'));
		$this->assertSame('http://example.org/x', ProfileSyncService::website('http://example.org/x'));
	}

	public function testPicksTheCurrentJobInTheProfilesOwnOrder(): void {
		$job = ProfileSyncService::currentJob([
			['company' => 'B', 'role' => 'b', 'startDate' => '2022-01-01', 'sortOrder' => 2],
			['company' => 'A', 'role' => 'a', 'startDate' => '2020-01-01', 'sortOrder' => 1],
			['company' => 'Past', 'role' => 'p', 'startDate' => '2018-01-01', 'endDate' => '2019-01-01', 'sortOrder' => 0],
		]);

		$this->assertSame('A', $job['company']);
		$this->assertNull(ProfileSyncService::currentJob([['company' => 'Past', 'endDate' => '2019-01-01']]));
		$this->assertNull(ProfileSyncService::currentJob('not a list'));
	}

	public function testDisplayNameFallsThroughTheShapesProfilesUse(): void {
		$this->assertSame('Alice', ProfileSyncService::pickDisplayName(['displayName' => ' Alice '], self::W3ID));
		$this->assertSame('Alice A', ProfileSyncService::pickDisplayName(['displayName' => self::W3ID, 'givenName' => 'Alice', 'familyName' => 'A'], self::W3ID));
		$this->assertSame('Bob B', ProfileSyncService::pickDisplayName(['firstName' => 'Bob', 'lastName' => 'B'], self::W3ID));
		$this->assertSame('Carol', ProfileSyncService::pickDisplayName(['name' => 'Carol'], self::W3ID));
		$this->assertSame(self::W3ID, ProfileSyncService::pickDisplayName([], self::W3ID));
	}

	// -------- applying --------

	public function testTheProfileOverwritesWhatNextcloudHas(): void {
		$user = $this->user('Rauthy Name', 'old@example.org');
		$user->expects($this->once())->method('setDisplayName')->with('Alice')->willReturn(true);
		$user->expects($this->once())->method('setSystemEMailAddress')->with('alice@example.org');
		$this->avatar->expects($this->once())->method('syncIfChanged');
		$this->stored = [IAccountManager::PROPERTY_BIOGRAPHY => 'old bio', IAccountManager::PROPERTY_PHONE => '+1 555'];
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->accounts->expects($this->once())->method('updateAccount');

		$this->service()->applyProfile($user, self::W3ID, [
			'displayName' => 'Alice',
			'email' => 'alice@example.org',
			'bio' => 'new bio',
		]);

		$this->assertSame('new bio', $this->stored[IAccountManager::PROPERTY_BIOGRAPHY]);
		$this->assertSame('+1 555', $this->stored[IAccountManager::PROPERTY_PHONE], 'absent in the profile, so kept');
	}

	public function testNothingIsWrittenWhenNothingChanged(): void {
		$user = $this->user('Alice', 'alice@example.org');
		$user->expects($this->never())->method('setDisplayName');
		$user->expects($this->never())->method('setSystemEMailAddress');
		$this->stored = [IAccountManager::PROPERTY_BIOGRAPHY => 'bio'];
		$this->accounts->method('getAccount')->willReturn($this->account());
		$this->accounts->expects($this->never())->method('updateAccount');

		$this->service()->applyProfile($user, self::W3ID, [
			'displayName' => 'Alice',
			'email' => 'alice@example.org',
			'bio' => 'bio',
		]);
	}

	public function testAnInvalidEmailIsNotApplied(): void {
		$user = $this->user();
		$user->expects($this->never())->method('setSystemEMailAddress');

		$this->service()->applyProfile($user, self::W3ID, ['email' => 'not-an-email']);
	}

	public function testAValueNextcloudRejectsIsSkippedAndTheRestKept(): void {
		$this->accounts->method('getAccount')->willReturnCallback(fn () => $this->account());
		$calls = 0;
		$this->accounts->method('updateAccount')->willReturnCallback(function () use (&$calls) {
			$calls++;
			if ($calls === 1) {
				// A rejected save stores nothing.
				$this->stored = [];
				throw new \InvalidArgumentException(IAccountManager::PROPERTY_PHONE);
			}
		});

		$this->service()->applyProfile($this->user(), self::W3ID, ['telephone' => 'not a phone', 'bio' => 'kept']);

		$this->assertSame(2, $calls, 'saved again without the rejected property');
		$this->assertSame('kept', $this->stored[IAccountManager::PROPERTY_BIOGRAPHY]);
		$this->assertArrayNotHasKey(IAccountManager::PROPERTY_PHONE, $this->stored);
	}

	// -------- reading --------

	public function testReadsTheEVaultOncePerRequest(): void {
		$this->evault->expects($this->once())->method('getProfileEnvelopeId')->willReturn('env-1');
		$this->evault->expects($this->once())->method('fetchMetaEnvelopeById')->willReturn(['parsed' => ['displayName' => 'Alice']]);
		$this->evault->expects($this->once())->method('fetchProfessionalProfile')->willReturn(null);

		$service = $this->service();
		$service->syncProfile($this->user(), self::W3ID);
		$service->syncProfile($this->user(), self::W3ID);
	}

	public function testBacksOffAnEVaultItCouldNotRead(): void {
		$this->evault->method('getProfileEnvelopeId')->willReturn(null);
		$this->cache->expects($this->once())->method('set');

		$this->service()->syncProfile($this->user(), self::W3ID);
	}

	public function testDoesNotAskAnEVaultThatIsBackedOff(): void {
		$this->cache->method('get')->willReturn(1);
		$this->evault->expects($this->never())->method('getProfileEnvelopeId');

		$this->service()->syncProfile($this->user(), self::W3ID);
	}

	public function testNeverThrows(): void {
		$this->evault->method('getProfileEnvelopeId')->willThrowException(new \RuntimeException('down'));

		$this->service()->syncProfile($this->user(), self::W3ID);

		$this->addToAssertionCount(1);
	}
}
