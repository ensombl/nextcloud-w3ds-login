<?php

declare(strict_types=1);

/**
 * Standalone mention translation check.
 *
 * Mentions are awkward to verify by hand: it needs two linked accounts, a
 * shared room, and a poll cycle before anything is observable. This script
 * exercises the same MentionTranslator the sync path uses, against a fixed
 * mapping table, and prints what a message looks like on the wire and back in
 * Talk. No Nextcloud, no database, no eVault.
 *
 *   php tests/mention-roundtrip.php
 *
 * Exits non-zero if any case does not match its expectation.
 */

require __DIR__ . '/../app/lib/Service/IdentityResolverInterface.php';
require __DIR__ . '/../app/lib/Service/MentionTranslator.php';

use OCA\W3dsLogin\Service\IdentityResolverInterface;
use OCA\W3dsLogin\Service\MentionTranslator;

/** Stands in for the w3ds_login_mappings table. */
final class FixtureResolver implements IdentityResolverInterface {
	/** @param array<string, string> $byUid uid => eName */
	public function __construct(
		private array $byUid,
	) {
	}

	public function w3idForUid(string $ncUid): ?string {
		return $this->byUid[$ncUid] ?? null;
	}

	public function uidForW3id(string $w3id): ?string {
		return array_flip($this->byUid)[$w3id] ?? null;
	}
}

$translator = new MentionTranslator(new FixtureResolver([
	'alice_nc' => '@alice.w3id',
	'bob_nc' => '@bob.w3id',
]));

/**
 * Each case is [description, what Talk/the wire holds, expected outbound,
 * expected inbound]. A null expectation means "must be left untouched".
 */
$cases = [
	['Talk mention, quoted token', 'hey @"alice_nc" look', 'hey @alice.w3id look', null],
	['Talk mention, bare token', 'ping @bob_nc', 'ping @bob.w3id', null],
	['two mentions in one message', '@"alice_nc" and @"bob_nc"', '@alice.w3id and @bob.w3id', null],
	['inbound eName becomes a Talk token', 'hey @alice.w3id look', null, 'hey @"alice_nc" look'],
	['inbound eName, quoted on the wire', 'hey @"@bob.w3id"', null, 'hey @"bob_nc"'],
	['email address is not a mention', 'mail me at foo@bar.com', null, null],
	['unknown local user is left alone', 'hey @carol_nc', null, null],
	['unknown eName reads as text', 'hey @carol.w3id', null, null],
	['@all is left to Talk', '@all standup now', null, null],
	['no mention at all', 'just a message', null, null],
];

$failures = 0;
$w = 34;

printf("%-{$w}s  %-26s  %-26s  %s\n", 'case', 'in Talk / on the wire', 'outbound (to wire)', 'inbound (to Talk)');
echo str_repeat('-', 118), "\n";

foreach ($cases as [$desc, $input, $expectedOut, $expectedIn]) {
	$out = $translator->toWire($input);
	$in = $translator->toTalk($input);

	$okOut = $out === ($expectedOut ?? $input);
	$okIn = $in === ($expectedIn ?? $input);
	if (!$okOut || !$okIn) {
		$failures++;
	}

	printf(
		"%-{$w}s  %-26s  %-26s  %s%s\n",
		$desc,
		$input,
		$out . ($okOut ? '' : ' <-- expected ' . ($expectedOut ?? $input)),
		$in . ($okIn ? '' : ' <-- expected ' . ($expectedIn ?? $input)),
		($okOut && $okIn) ? '' : '   FAIL',
	);
}

echo "\n";
if ($failures > 0) {
	echo "FAILED: {$failures} of " . count($cases) . " cases\n";
	exit(1);
}

echo 'OK: all ' . count($cases) . " cases translate as expected\n";
echo "A mention only notifies when the mentioned user is also a participant of the room.\n";
