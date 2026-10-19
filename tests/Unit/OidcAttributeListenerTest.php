<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Db\W3dsMapping;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Listener\OidcAttributeListener;
use OCA\W3dsLogin\Service\ProfileSyncService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Stand-in for user_oidc's AttributeMappedEvent, which only exists when
 * user_oidc is installed.
 */
class FakeAttributeMappedEvent extends Event {
	public ?string $value;

	public function __construct(
		private string $attribute,
		private object $claims,
		?string $default = null,
	) {
		parent::__construct();
		$this->value = $default;
	}

	public function getAttribute(): string {
		return $this->attribute;
	}

	public function getClaims(): object {
		return $this->claims;
	}

	public function setValue(?string $value): void {
		$this->value = $value;
	}
}

/**
 * user_oidc writes the IdP's name at every login; with no name in the token
 * that is the username -- the eName -- so a linked user's real name kept
 * turning back into a UUID.
 */
class OidcAttributeListenerTest extends TestCase {
	private const ENAME = '849c0221-6f3f-55f9-95f0-f3b0d2b3092f';
	private const UID = 'fiEYRADCxJON8YN080lHOvzj';

	private function listener(?string $linkedUid = self::UID, ?string $name = 'Merul Dhiman'): OidcAttributeListener {
		$mappings = $this->createMock(W3dsMappingMapper::class);
		if ($linkedUid === null) {
			$mappings->method('findByW3id')->willThrowException(new DoesNotExistException('none'));
		} else {
			$mapping = new W3dsMapping();
			$mapping->setW3id('@' . self::ENAME);
			$mapping->setNcUid($linkedUid);
			$mappings->method('findByW3id')->willReturn($mapping);
		}
		$profile = $this->createMock(ProfileSyncService::class);
		$profile->method('displayNameFor')->willReturn($name);
		$profile->method('emailFor')->willReturn('merul@example.org');

		return new OidcAttributeListener($mappings, $profile, new NullLogger());
	}

	private function event(string $attribute = 'mappingDisplayName'): FakeAttributeMappedEvent {
		return new FakeAttributeMappedEvent($attribute, (object)['sub' => self::UID, 'preferred_username' => self::ENAME], self::ENAME);
	}

	public function testTheLinkedAccountGetsItsEVaultName(): void {
		$event = $this->event();
		$this->listener()->handle($event);

		$this->assertSame('Merul Dhiman', $event->value);
		$this->assertTrue($event->isPropagationStopped());
	}

	public function testAndItsEVaultEmail(): void {
		$event = $this->event('mappingEmail');
		$this->listener()->handle($event);

		$this->assertSame('merul@example.org', $event->value);
	}

	/**
	 * A username that only looks like an eName must not borrow its name.
	 */
	public function testAnotherAccountClaimingTheENameIsLeftAlone(): void {
		$event = $this->event();
		$this->listener(linkedUid: 'someone-else')->handle($event);

		$this->assertSame(self::ENAME, $event->value);
	}

	public function testAnUnlinkedENameIsLeftAlone(): void {
		$event = $this->event();
		$this->listener(linkedUid: null)->handle($event);

		$this->assertSame(self::ENAME, $event->value);
	}

	public function testAProfileWithoutANameKeepsTheIdPValue(): void {
		$event = $this->event();
		$this->listener(name: null)->handle($event);

		$this->assertSame(self::ENAME, $event->value);
	}

	public function testOtherAttributesAreNotTouched(): void {
		$event = $this->event('mappingUid');
		$this->listener()->handle($event);

		$this->assertSame(self::ENAME, $event->value);
	}
}
