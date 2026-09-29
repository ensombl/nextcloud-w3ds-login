<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\LinkHintService;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * An identity provider in front of the W3DS OIDC connector replaces `sub`,
 * so the eName reaches Nextcloud as `preferred_username`. It is a hint only:
 * these tests pin what counts as one, and when it prompts the user.
 */
class LinkHintServiceTest extends TestCase {
	private const UUID = 'e4d1c2b0-5a6f-4c1e-9b1d-3f2a7c8e9d10';

	private static function jwt(array $claims): string {
		$encode = static fn (array $part): string => rtrim(strtr(base64_encode(json_encode($part)), '+/', '-_'), '=');

		return $encode(['alg' => 'ES256']) . '.' . $encode($claims) . '.sig';
	}

	public function testReadsTheENameFromPreferredUsername(): void {
		$token = ['id_token' => self::jwt(['sub' => 'rauthy-user-id', 'preferred_username' => self::UUID])];

		$this->assertSame('@' . self::UUID, LinkHintService::eNameFromTokenResponse($token));
	}

	public function testAcceptsAnAtPrefixAndUppercase(): void {
		$token = ['id_token' => self::jwt(['preferred_username' => '@' . strtoupper(self::UUID)])];

		$this->assertSame('@' . self::UUID, LinkHintService::eNameFromTokenResponse($token));
	}

	/**
	 * @dataProvider notAHint
	 */
	public function testIgnoresAnythingElse(array $token): void {
		$this->assertNull(LinkHintService::eNameFromTokenResponse($token));
	}

	public static function notAHint(): array {
		return [
			'no id token' => [['access_token' => 'x']],
			'not a jwt' => [['id_token' => 'garbage']],
			'bad payload' => [['id_token' => 'a.!!!.c']],
			'ordinary username' => [['id_token' => self::jwt(['preferred_username' => 'alice'])]],
			'email username' => [['id_token' => self::jwt(['preferred_username' => 'alice@example.org'])]],
			'no username' => [['id_token' => self::jwt(['sub' => self::UUID])]],
		];
	}

	public function testComparesENamesLoosely(): void {
		$this->assertTrue(LinkHintService::sameEName('@' . self::UUID, strtoupper(self::UUID)));
		$this->assertFalse(LinkHintService::sameEName('@' . self::UUID, '@00000000-0000-4000-8000-000000000000'));
	}

	/**
	 * @param array<string, string> $userValues
	 */
	private function service(array $userValues, string $promptAll = 'no'): LinkHintService {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid, string $app, string $key, $default = '') => $userValues[$key] ?? $default,
		);
		$config->method('getAppValue')->willReturn($promptAll);

		return new LinkHintService($config);
	}

	public function testPromptsHintedUsersUntilTheyDismiss(): void {
		$this->assertTrue($this->service(['oidc_ename_hint' => '@' . self::UUID])->shouldPrompt('u'));
		$this->assertFalse($this->service([
			'oidc_ename_hint' => '@' . self::UUID,
			'link_prompt_dismissed' => '1',
		])->shouldPrompt('u'));
	}

	public function testPromptsUnhintedUsersOnlyWhenTheAdminAsks(): void {
		$this->assertFalse($this->service([])->shouldPrompt('u'));
		$this->assertTrue($this->service([], 'yes')->shouldPrompt('u'));
	}
}
