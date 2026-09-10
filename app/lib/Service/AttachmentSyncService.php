<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Moves chat attachments between Talk file shares and W3DS file URIs.
 *
 * Talk represents a shared file as a comment whose message is the literal
 * `{file}` placeholder, with the real payload in the comment's message
 * parameters. The W3DS `Message` schema carries an attachment as a single
 * `mediaUrl` alongside a `type` of `image` or `file`, where the value is a
 * `w3ds://file?id=@ename/envelopeId` URI rather than the bytes themselves.
 *
 * Bytes are copied rather than referenced. A `w3ds://file` URI resolves to
 * object storage owned by the *sender's* eVault, so referencing it would
 * leave the attachment dependent on a remote blob we don't control, and Talk
 * has no concept of a remote attachment anyway.
 */
class AttachmentSyncService {
	/**
	 * Talk's verb for a file share. The comment's message is a JSON blob
	 * (`{"message":"file_shared","parameters":{"share":"6",...}}`) rather
	 * than user text, and it references a *share id*, not a file id.
	 */
	public const TALK_SHARE_VERB = 'object_shared';

	/**
	 * Cap on bytes we will move in either direction. Well under the
	 * protocol's 250 MB upload limit: this runs inline on a chat request,
	 * and the receiving instance still has to honour the user's quota.
	 */
	private const MAX_ATTACHMENT_BYTES = 25 * 1024 * 1024;

	/** Folder created in the recipient's home for inbound attachments. */
	private const INBOX_FOLDER = 'W3DS Attachments';

	/**
	 * Stand-in route for shares we create outside a web request. Talk only
	 * compares this against its own recording route, so the value is
	 * irrelevant as long as it is a string.
	 */
	private const SYNTHETIC_SHARE_ROUTE = 'w3ds_login.sync.share';

	public function __construct(
		private EvaultClient $evaultClient,
		private IRootFolder $rootFolder,
		private IShareManager $shareManager,
		private IRequest $request,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Extract the share id from a Talk file-share comment.
	 *
	 * The comment message is JSON of the form
	 * `{"message":"file_shared","parameters":{"share":"6",...}}`.
	 */
	public function extractShareId(string $commentMessage): ?string {
		$decoded = json_decode($commentMessage, true);
		if (!is_array($decoded)) {
			return null;
		}

		$share = $decoded['parameters']['share'] ?? null;

		return is_scalar($share) && (string)$share !== '' ? (string)$share : null;
	}

	/**
	 * Extract the caption a user typed alongside a file share.
	 *
	 * Talk keeps the caption inside the share reference itself, at
	 * `parameters.metaData.caption`, rather than as the comment text (which
	 * stays the `{file}` placeholder). Its own renderer substitutes the
	 * caption for the filename when one is present, so it is the message the
	 * sender actually wrote and the only text worth replicating.
	 */
	public function extractCaption(string $commentMessage): ?string {
		$decoded = json_decode($commentMessage, true);
		if (!is_array($decoded)) {
			return null;
		}

		$caption = $decoded['parameters']['metaData']['caption'] ?? null;
		if (!is_string($caption)) {
			return null;
		}

		$caption = trim($caption);

		return $caption !== '' ? $caption : null;
	}

	/**
	 * Upload a Talk file share to the sender's eVault.
	 *
	 * @param string $commentMessage The raw comment message (JSON share ref)
	 * @param string[] $acl eNames allowed to read the blob
	 * @return array{mediaUrl: string, type: string, filename: string, caption: ?string}|null
	 */
	public function pushAttachment(
		string $senderUid,
		string $senderW3id,
		string $commentMessage,
		array $acl,
	): ?array {
		$shareId = $this->extractShareId($commentMessage);
		if ($shareId === null) {
			return null;
		}

		try {
			$file = $this->resolveSharedFile($shareId);
			if ($file === null) {
				return null;
			}

			$size = $file->getSize();
			if ($size <= 0 || $size > self::MAX_ATTACHMENT_BYTES) {
				$this->logger->info('[W3DS Attachment] Skipping outbound attachment outside size limits', [
					'shareId' => $shareId,
					'size' => $size,
				]);
				return null;
			}

			$content = $file->getContent();
			if ($content === '') {
				return null;
			}

			$mime = $file->getMimeType();
			$uri = $this->evaultClient->uploadFile(
				$senderW3id,
				$file->getName(),
				$mime,
				$content,
				$acl,
			);
			if ($uri === null) {
				return null;
			}

			$this->logger->info('[W3DS Attachment] Uploaded attachment to eVault', [
				'shareId' => $shareId,
				'name' => $file->getName(),
				'bytes' => strlen($content),
			]);

			return [
				'mediaUrl' => $uri,
				// The Message schema distinguishes `image` from `file`; a
				// receiving platform uses it to decide how to render.
				'type' => str_starts_with($mime, 'image/') ? 'image' : 'file',
				'filename' => $file->getName(),
				'caption' => $this->extractCaption($commentMessage),
			];
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Attachment] Failed to push attachment', [
				'shareId' => $shareId,
				'exception' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Materialise an inbound attachment into the recipient's Files and share
	 * it into the room, so Talk renders a real attachment rather than text.
	 *
	 * @return IShare|null The created room share, or null when unavailable
	 */
	public function pullAttachment(string $mediaUrl, string $recipientUid, string $roomToken, ?string $caption = null): ?IShare {
		try {
			$meta = $this->evaultClient->dereferenceFileUri($mediaUrl);
			if ($meta === null) {
				return null;
			}

			if ($meta['size'] > self::MAX_ATTACHMENT_BYTES) {
				$this->logger->info('[W3DS Attachment] Inbound attachment exceeds size limit, skipping', [
					'size' => $meta['size'],
					'filename' => $meta['filename'],
				]);
				return null;
			}

			$bytes = $this->evaultClient->downloadFile($meta['publicUrl'], self::MAX_ATTACHMENT_BYTES);
			if ($bytes === null) {
				return null;
			}

			$file = $this->storeInUserFiles($recipientUid, $meta['filename'], $bytes);
			if ($file === null) {
				return null;
			}

			// A caption that merely repeats the filename says nothing: Talk
			// renders the caption in place of the filename, so passing it
			// through would just show the same string twice. Senders that
			// wrote no caption send the filename as content, which is exactly
			// this case.
			if ($caption !== null && trim($caption) === $meta['filename']) {
				$caption = null;
			}

			return $this->shareIntoRoom($file, $recipientUid, $roomToken, $caption);
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Attachment] Failed to materialise inbound attachment', [
				'mediaUrl' => $mediaUrl,
				'exception' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Resolve the file behind a Talk room share.
	 */
	private function resolveSharedFile(string $shareId): ?File {
		try {
			$share = $this->shareManager->getShareById('ocRoomShare:' . $shareId);
		} catch (\Throwable) {
			try {
				$share = $this->shareManager->getShareById($shareId);
			} catch (\Throwable $e) {
				$this->logger->info('[W3DS Attachment] Could not resolve share', [
					'shareId' => $shareId,
					'exception' => $e->getMessage(),
				]);
				return null;
			}
		}

		$node = $share->getNode();

		return $node instanceof File ? $node : null;
	}

	/**
	 * Write inbound bytes into the recipient's home under a dedicated
	 * folder, avoiding collisions rather than overwriting an existing file.
	 */
	private function storeInUserFiles(string $uid, string $filename, string $bytes): ?File {
		try {
			$userFolder = $this->rootFolder->getUserFolder($uid);
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Attachment] Cannot access recipient files', [
				'uid' => $uid,
				'exception' => $e->getMessage(),
			]);
			return null;
		}

		try {
			$folder = $userFolder->nodeExists(self::INBOX_FOLDER)
				? $userFolder->get(self::INBOX_FOLDER)
				: $userFolder->newFolder(self::INBOX_FOLDER);
		} catch (NotFoundException) {
			return null;
		}

		if (!$folder instanceof \OCP\Files\Folder) {
			return null;
		}

		$target = $this->uniqueName($folder, $this->sanitiseFilename($filename));

		// Create then write: Folder::newFile() with content in one call is
		// rejected on some storages.
		$file = $folder->newFile($target);
		$file->putContent($bytes);

		return $file;
	}

	/**
	 * Strip path separators and control characters from a remote-supplied
	 * filename so it cannot escape the target folder.
	 */
	private function sanitiseFilename(string $filename): string {
		$name = basename(str_replace('\\', '/', $filename));
		$name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
		$name = trim($name);

		if ($name === '' || $name === '.' || $name === '..') {
			return 'attachment';
		}

		return $name;
	}

	/**
	 * Append ` (n)` before the extension until the name is free.
	 */
	private function uniqueName(\OCP\Files\Folder $folder, string $name): string {
		if (!$folder->nodeExists($name)) {
			return $name;
		}

		$dot = strrpos($name, '.');
		$stem = $dot === false ? $name : substr($name, 0, $dot);
		$ext = $dot === false ? '' : substr($name, $dot);

		for ($i = 1; $i < 100; $i++) {
			$candidate = $stem . ' (' . $i . ')' . $ext;
			if (!$folder->nodeExists($candidate)) {
				return $candidate;
			}
		}

		return $stem . '-' . bin2hex(random_bytes(4)) . $ext;
	}

	/**
	 * Run $fn with the request parameters Talk's share listener expects.
	 *
	 * That listener runs on every room share and reads two things straight
	 * off the request: `_route`, which it passes to strtolower() unguarded,
	 * and `talkMetaData`, which carries the caption. Neither exists when the
	 * share originates from a background job, a webhook or a poll, so the
	 * listener fatals on the null route and takes the whole share down with
	 * it -- inbound attachments never materialised outside a web request.
	 *
	 * Supplying both, then restoring what was there, keeps the listener on
	 * its normal path and gets the caption onto the generated message. The
	 * concrete Request merges URL parameters into the same bag getParam()
	 * reads; if this implementation does not support that, we fall back to
	 * sharing without a caption rather than failing.
	 *
	 * @template T
	 * @param array<string, mixed> $metaData
	 * @param callable(): T $fn
	 * @return T
	 */
	private function withTalkMetaData(array $metaData, callable $fn): mixed {
		if (!method_exists($this->request, 'setUrlParameters')) {
			return $fn();
		}

		$previousMeta = $this->request->getParam('talkMetaData');
		$previousRoute = $this->request->getParam('_route');
		try {
			$this->request->setUrlParameters([
				'talkMetaData' => json_encode($metaData),
				// Any non-null route works: Talk only compares it against its
				// own recording route to decide whether to skip.
				'_route' => is_string($previousRoute) ? $previousRoute : self::SYNTHETIC_SHARE_ROUTE,
			]);

			return $fn();
		} finally {
			// Restoring to '' rather than dropping keys: Talk treats an
			// unparseable value as "no metadata", which is what we want, and
			// the parameter bag has no removal API.
			$this->request->setUrlParameters([
				'talkMetaData' => $previousMeta ?? '',
				'_route' => is_string($previousRoute) ? $previousRoute : '',
			]);
		}
	}

	/**
	 * Share a file into a Talk room. Talk turns the share into a chat
	 * message with the file parameters attached, which is what makes it
	 * render as an attachment.
	 *
	 * A caption cannot be set on the share object: Talk's own share listener
	 * reads it from the `talkMetaData` request parameter and folds it into
	 * the system message it generates. We are not in a share request here, so
	 * we put it on the request ourselves for the duration of the call. Doing
	 * it any other way means posting the caption as a separate message, which
	 * would show up as a stray line of text next to the file.
	 */
	private function shareIntoRoom(File $file, string $uid, string $roomToken, ?string $caption = null): ?IShare {
		try {
			$share = $this->shareManager->newShare();
			$share->setNode($file)
				->setSharedBy($uid)
				->setShareType(IShare::TYPE_ROOM)
				->setSharedWith($roomToken)
				->setPermissions(\OCP\Constants::PERMISSION_READ);

			// Always go through withTalkMetaData(), caption or not: it also
			// supplies the `_route` parameter Talk's share listener
			// dereferences unguarded, without which no inbound share survives
			// outside a web request.
			return $this->withTalkMetaData(
				$caption !== null ? ['caption' => $caption] : [],
				fn (): IShare => $this->shareManager->createShare($share),
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Attachment] Failed to share attachment into room', [
				'roomToken' => $roomToken,
				'exception' => $e->getMessage(),
			]);
			return null;
		}
	}
}
