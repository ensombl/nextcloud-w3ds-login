<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Tests\Unit;

use OCA\W3dsLogin\Service\IdentityResolverInterface;
use OCA\W3dsLogin\Service\MentionTranslator;
use PHPUnit\Framework\TestCase;

/**
 * Mentions must survive the round trip in both directions. Talk derives the
 * mention notification from the `@"uid"` token in the message text, so a
 * mistranslated mention silently loses the notification.
 */
class MentionTranslatorTest extends TestCase {
	private MentionTranslator $translator;

	protected function setUp(): void {
		$byUid = ['alice_nc' => '@alice.w3id', 'bob_nc' => '@bob.w3id'];
		$byEname = array_flip($byUid);

		$resolver = new class($byUid, $byEname) implements IdentityResolverInterface {
			public function __construct(
				private array $byUid,
				private array $byEname,
			) {
			}

			public function w3idForUid(string $ncUid): ?string {
				return $this->byUid[$ncUid] ?? null;
			}

			public function uidForW3id(string $w3id): ?string {
				return $this->byEname[$w3id] ?? null;
			}
		};

		$this->translator = new MentionTranslator($resolver);
	}

	// -------- outbound: Talk token -> eName --------

	public function testOutboundRewritesQuotedTalkToken(): void {
		$this->assertSame(
			'hey @alice.w3id look',
			$this->translator->toWire('hey @"alice_nc" look'),
		);
	}

	public function testOutboundRewritesBareTalkToken(): void {
		$this->assertSame('hey @bob.w3id', $this->translator->toWire('hey @bob_nc'));
	}

	public function testOutboundRewritesMultipleMentions(): void {
		$this->assertSame(
			'@alice.w3id and @bob.w3id both',
			$this->translator->toWire('@"alice_nc" and @"bob_nc" both'),
		);
	}

	public function testOutboundLeavesUnlinkedUserUntouched(): void {
		// No cross-platform identity exists, so mangling it would be worse
		// than leaving it readable.
		$this->assertSame('hey @"carol_nc"', $this->translator->toWire('hey @"carol_nc"'));
	}

	// -------- inbound: eName -> Talk token --------

	public function testInboundRewritesENameToQuotedTalkToken(): void {
		$this->assertSame(
			'hey @"alice_nc" look',
			$this->translator->toTalk('hey @alice.w3id look'),
		);
	}

	public function testInboundLeavesUnknownENameAsPlainText(): void {
		$this->assertSame('hey @nobody.w3id', $this->translator->toTalk('hey @nobody.w3id'));
	}

	public function testInboundHandlesDoubleAtENameForm(): void {
		// Some platforms write the eName including its leading @, producing
		// `@@alice.w3id` once the mention marker is prepended.
		$this->assertSame('ping @"alice_nc"', $this->translator->toTalk('ping @@alice.w3id'));
	}

	// -------- round trip --------

	public function testRoundTripPreservesTheMention(): void {
		$original = 'hey @"alice_nc" and @"bob_nc"';
		$wire = $this->translator->toWire($original);

		$this->assertSame($original, $this->translator->toTalk($wire));
	}

	// -------- messages that must not be damaged --------

	public function testLeavesMessagesWithoutMentionsUntouched(): void {
		$this->assertSame('no mentions here', $this->translator->toWire('no mentions here'));
		$this->assertSame('no mentions here', $this->translator->toTalk('no mentions here'));
	}

	public function testLeavesEmailAddressesAlone(): void {
		// An email's local part is not a known uid/eName, so it must survive.
		$msg = 'mail me at someone@example.org';

		$this->assertSame($msg, $this->translator->toWire($msg));
		$this->assertSame($msg, $this->translator->toTalk($msg));
	}

	public function testHandlesEmptyString(): void {
		$this->assertSame('', $this->translator->toWire(''));
		$this->assertSame('', $this->translator->toTalk(''));
	}

	public function testHandlesLoneAtSign(): void {
		$this->assertSame('a @ b', $this->translator->toWire('a @ b'));
		$this->assertSame('a @ b', $this->translator->toTalk('a @ b'));
	}

	public function testPreservesSurroundingPunctuation(): void {
		$this->assertSame(
			'ping @alice.w3id, please!',
			$this->translator->toWire('ping @"alice_nc", please!'),
		);
	}
}
