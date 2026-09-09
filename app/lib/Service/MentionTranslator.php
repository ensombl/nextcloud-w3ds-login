<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

/**
 * Translates @ mentions between Talk's on-the-wire format and the W3DS
 * cross-platform format.
 *
 * Talk stores a mention inside the message text as a token, `@"alice"` (or
 * bare `@alice` when the id has no special characters), and derives both the
 * rendered highlight and the *notification* from it. A raw eName pushed
 * through untranslated is therefore inert on the receiving side: no
 * highlight, and more importantly no notification for the mentioned user.
 *
 * Outbound we rewrite local UIDs to eNames so other platforms can resolve
 * them; inbound we rewrite eNames back to local UIDs so Talk notifies.
 * Anything we cannot resolve is left exactly as-is rather than mangled --
 * an unresolvable mention should still read as text.
 */
class MentionTranslator {
	/**
	 * Matches Talk's mention tokens:
	 *   @"id with spaces"   -- quoted form, used whenever the id isn't simple
	 *   @id                 -- bare form
	 *
	 * The bare alternative deliberately allows the characters that appear in
	 * eNames (letters, digits, underscore, hyphen, dot, @) so an inbound
	 * `@@alice` style eName mention round-trips.
	 */
	private const MENTION_PATTERN = '/@"([^"]+)"|@([a-zA-Z0-9_.\-@]+)/';

	public function __construct(
		private IdentityResolverInterface $identityResolver,
	) {
	}

	/**
	 * Rewrite Talk mention tokens into eNames for the wire.
	 *
	 * `hey @"alice_w3id_1a2b" ping` becomes `hey @alice.w3id ping` when that
	 * local user is linked. Unlinked users are left untouched: there is no
	 * cross-platform identity to translate them to.
	 */
	public function toWire(string $message): string {
		return $this->rewrite($message, function (string $id): ?string {
			// Already an eName (starts with @ after the token marker) --
			// nothing to do.
			if (str_starts_with($id, '@')) {
				return null;
			}

			return $this->identityResolver->w3idForUid($id);
		});
	}

	/**
	 * Rewrite eName mentions into Talk tokens for a local room.
	 *
	 * The result uses the quoted form `@"uid"`, which Talk accepts for every
	 * id shape, so we don't have to reason about which UIDs need quoting.
	 * Only users known locally are rewritten; an eName belonging to someone
	 * who has never logged into this instance stays as plain text.
	 */
	public function toTalk(string $message): string {
		return $this->rewrite($message, function (string $id): ?string {
			// Talk tokens carry a local UID; eNames are what we translate.
			$ename = str_starts_with($id, '@') ? $id : '@' . $id;

			$uid = $this->identityResolver->uidForW3id($ename);
			if ($uid === null) {
				return null;
			}

			return '"' . $uid . '"';
		});
	}

	/**
	 * Apply a resolver to every mention token in the message, leaving the
	 * token untouched when the resolver returns null.
	 *
	 * @param callable(string): ?string $resolve
	 */
	private function rewrite(string $message, callable $resolve): string {
		if (!str_contains($message, '@')) {
			return $message;
		}

		$result = preg_replace_callback(
			self::MENTION_PATTERN,
			static function (array $m) use ($resolve): string {
				$id = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
				if ($id === '') {
					return $m[0];
				}

				$replacement = $resolve($id);
				if ($replacement === null) {
					return $m[0];
				}

				// eNames already carry their own leading '@'; don't double it.
				return str_starts_with($replacement, '@') ? $replacement : '@' . $replacement;
			},
			$message,
		);

		// preg_replace_callback returns null on failure (e.g. backtrack limit
		// on a pathological message). Never lose the message over a mention.
		return $result ?? $message;
	}
}
