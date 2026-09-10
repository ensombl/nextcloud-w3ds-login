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
	 * Cap on bytes we will move in either direction.
	 *
	 * This matches the protocol's own 250 MB upload limit, which is what a
	 * sending platform enforces, so anything that legitimately exists in an
	 * eVault can be received. A lower cap here does not protect anything: the
	 * bytes were already accepted on the sending side, and the recipient's
	 * own storage quota is enforced independently when the file is written.
	 *
	 * It used to be 25 MB, which images almost never reach but documents,
	 * archives and video routinely do -- so images arrived and files silently
	 * did not. `File.size` is required by the ontology, so the check below
	 * always had a real value to reject on.
	 */
	private const MAX_ATTACHMENT_BYTES = 250 * 1024 * 1024;

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
	 * @return array{mediaUrl: string, publicUrl: ?string, type: string, filename: string, mimeType: string, size: int, caption: ?string}|null
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
			$uploaded = $this->evaultClient->uploadFile(
				$senderW3id,
				$file->getName(),
				$mime,
				$content,
				$acl,
			);
			if ($uploaded === null) {
				return null;
			}

			$this->logger->info('[W3DS Attachment] Uploaded attachment to eVault', [
				'shareId' => $shareId,
				'name' => $file->getName(),
				'bytes' => strlen($content),
			]);

			return [
				'mediaUrl' => $uploaded['uri'],
				// The object-storage URL, when the eVault returned one. A peer
				// that renders `mediaUrl` directly cannot resolve a w3ds://
				// reference, so publishing this alongside it is what makes an
				// attachment we send visible on those platforms.
				'publicUrl' => $uploaded['publicUrl'],
				// The Message schema distinguishes `image` from `file`; a
				// receiving platform uses it to decide how to render.
				'type' => str_starts_with($mime, 'image/') ? 'image' : 'file',
				'filename' => $file->getName(),
				'mimeType' => $mime,
				'size' => $size,
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
			$meta = $this->resolveAttachment($mediaUrl);
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

			$bytes = $this->fetchBytes($meta);
			if ($bytes === null) {
				return null;
			}

			// A bare object-storage URL announces no type, and its path is
			// often an opaque key, so neither the name nor the declared MIME
			// type says what this is. The bytes do. Without this a document
			// fetched from such a URL is stored as an unnamed blob that
			// Nextcloud shows with no icon, no preview and no working
			// "open with" -- the visible difference between a file that
			// "arrives" and one that does not.
			if ($meta['contentType'] === 'application/octet-stream') {
				$sniffed = $this->sniffContentType($bytes);
				if ($sniffed !== null) {
					$meta['contentType'] = $sniffed;
					$meta['filename'] = $this->ensureExtension($meta['filename'], $sniffed);
				}
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
	 * Resolve whatever a Message envelope put in `mediaUrl` into file
	 * metadata plus a way to get the bytes.
	 *
	 * The Message schema types `mediaUrl` only as `format: uri`, so a
	 * conforming sender may hand us any of three things. A `w3ds://file`
	 * reference needs a lookup against the owning eVault to learn the name
	 * and MIME type. A plain https URL is already the blob. A `data:` URI
	 * carries the bytes inline. Only the first was handled before, so
	 * attachments composed on platforms that use either of the other two
	 * forms never materialised.
	 *
	 * @return array{publicUrl: ?string, inlineData: ?string, filename: string, contentType: string, size: int}|null
	 */
	private function resolveAttachment(string $mediaUrl): ?array {
		if (str_starts_with($mediaUrl, 'w3ds://file')) {
			$meta = $this->evaultClient->dereferenceFileUri($mediaUrl);
			if ($meta === null) {
				return null;
			}

			// `File.name` carries the original name and `File.mimeType` is
			// required, so this is where a document whose name lost its
			// suffix in transit gets it back.
			$meta['filename'] = $this->ensureExtension($meta['filename'], $meta['contentType']);

			return $meta;
		}

		if (str_starts_with($mediaUrl, 'data:')) {
			// A data URI names no file, so the extension has to come from the
			// declared media type. Talk keys its preview handling off the
			// stored file's name, so guessing badly means an image that never
			// renders inline.
			$contentType = $this->dataUriContentType($mediaUrl) ?? 'application/octet-stream';

			return [
				'publicUrl' => null,
				'inlineData' => $mediaUrl,
				'filename' => $this->ensureExtension('attachment', $contentType),
				'contentType' => $contentType,
				// Unknown until decoded; decodeInlineFileData() enforces the
				// real limit, so 0 here just skips the pre-check.
				'size' => 0,
			];
		}

		if (str_starts_with($mediaUrl, 'http://') || str_starts_with($mediaUrl, 'https://')) {
			return [
				'publicUrl' => $mediaUrl,
				'inlineData' => null,
				'filename' => $this->filenameFromUrl($mediaUrl),
				'contentType' => 'application/octet-stream',
				'size' => 0,
			];
		}

		$this->logger->info('[W3DS Attachment] Unrecognised media reference, ignoring', [
			'mediaUrl' => substr($mediaUrl, 0, 64),
		]);

		return null;
	}

	/**
	 * Get the bytes for a resolved attachment, from storage or from the
	 * envelope's own inline copy.
	 *
	 * @param array{publicUrl: ?string, inlineData: ?string, filename: string, contentType: string, size: int} $meta
	 */
	private function fetchBytes(array $meta): ?string {
		// Prefer the URL: it streams, whereas inline data is already fully in
		// memory and is only a fallback for blobs never pushed to storage.
		if ($meta['publicUrl'] !== null) {
			$bytes = $this->evaultClient->downloadFile($meta['publicUrl'], self::MAX_ATTACHMENT_BYTES);
			if ($bytes !== null) {
				return $bytes;
			}
		}

		if ($meta['inlineData'] !== null) {
			return $this->evaultClient->decodeInlineFileData($meta['inlineData'], self::MAX_ATTACHMENT_BYTES);
		}

		return null;
	}

	/**
	 * Read the media type out of a `data:` URI, ignoring its parameters.
	 */
	private function dataUriContentType(string $uri): ?string {
		if (!preg_match('#^data:([^;,]+)#i', $uri, $m)) {
			return null;
		}

		$type = strtolower(trim($m[1]));

		return $type !== '' ? $type : null;
	}

	/**
	 * Derive a filename from a blob URL's path, falling back to a generic
	 * name when the URL carries nothing usable.
	 */
	private function filenameFromUrl(string $url): string {
		$path = parse_url($url, PHP_URL_PATH);
		if (!is_string($path) || $path === '') {
			return 'attachment';
		}

		$name = rawurldecode(basename($path));
		$name = $this->sanitiseFilename($name);

		// A path ending in a directory, or one whose last segment is not a
		// filename at all, gives us nothing worth showing.
		return $name !== '' ? $name : 'attachment';
	}

	/**
	 * Derive a file extension from a media type.
	 *
	 * `File.mimeType` is required by the ontology, so it is the one piece of
	 * type information always available, and Nextcloud keys both preview
	 * rendering and the icon shown in Files off the stored *name*. A file
	 * that lands with no extension is treated as opaque binary: an image
	 * still previewed because the whitelist below covered it, while a PDF or
	 * a spreadsheet arrived as a nameless blob. Hence a general mapping
	 * rather than an image-only one.
	 *
	 * Unknown types fall back to `.bin` rather than to no extension at all,
	 * so the stored file is always recognisably a file.
	 */
	private function extensionForMimeType(string $mimeType): string {
		// Normalise `image/png; charset=binary` and similar.
		$mimeType = strtolower(trim(explode(';', $mimeType)[0]));

		return match ($mimeType) {
			'image/jpeg', 'image/jpg' => '.jpg',
			'image/png' => '.png',
			'image/gif' => '.gif',
			'image/webp' => '.webp',
			'image/svg+xml' => '.svg',
			'image/heic' => '.heic',
			'image/bmp' => '.bmp',
			'image/tiff' => '.tiff',
			'application/pdf' => '.pdf',
			'text/plain' => '.txt',
			'text/csv' => '.csv',
			'text/html' => '.html',
			'text/markdown' => '.md',
			'application/json' => '.json',
			'application/xml', 'text/xml' => '.xml',
			'application/zip' => '.zip',
			'application/gzip' => '.gz',
			'application/x-tar' => '.tar',
			'application/x-7z-compressed' => '.7z',
			'application/rtf' => '.rtf',
			'application/msword' => '.doc',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '.docx',
			'application/vnd.ms-excel' => '.xls',
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => '.xlsx',
			'application/vnd.ms-powerpoint' => '.ppt',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => '.pptx',
			'application/vnd.oasis.opendocument.text' => '.odt',
			'application/vnd.oasis.opendocument.spreadsheet' => '.ods',
			'application/vnd.oasis.opendocument.presentation' => '.odp',
			'audio/mpeg' => '.mp3',
			'audio/ogg' => '.ogg',
			'audio/wav', 'audio/x-wav' => '.wav',
			'audio/mp4', 'audio/m4a' => '.m4a',
			'video/mp4' => '.mp4',
			'video/quicktime' => '.mov',
			'video/webm' => '.webm',
			'video/x-matroska' => '.mkv',
			default => '',
		};
	}

	/**
	 * Identify content from its leading bytes.
	 *
	 * Used only when the reference gave us nothing to go on: a plain URL
	 * carries no declared type and often an opaque path. PHP's own
	 * `finfo` does this properly, so prefer it and fall back to a handful of
	 * unambiguous magic numbers when the extension is unavailable.
	 *
	 * Returns null when the content cannot be identified, leaving the caller
	 * to keep its existing generic type rather than guessing.
	 */
	private function sniffContentType(string $bytes): ?string {
		if ($bytes === '') {
			return null;
		}

		if (class_exists(\finfo::class)) {
			try {
				$detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
				if (is_string($detected)
					&& $detected !== ''
					&& $detected !== 'application/octet-stream') {
					return $detected;
				}
			} catch (\Throwable) {
				// fileinfo unavailable or unhappy; fall through to magic bytes.
			}
		}

		// A deliberately small table: only signatures with no realistic
		// false-positive, since a wrong answer renames the user's file.
		$signatures = [
			'%PDF-' => 'application/pdf',
			"\x89PNG\r\n\x1a\n" => 'image/png',
			"\xFF\xD8\xFF" => 'image/jpeg',
			'GIF87a' => 'image/gif',
			'GIF89a' => 'image/gif',
			"7z\xBC\xAF\x27\x1C" => 'application/x-7z-compressed',
			"\x1F\x8B" => 'application/gzip',
		];

		foreach ($signatures as $magic => $mime) {
			if (str_starts_with($bytes, $magic)) {
				return $mime;
			}
		}

		// ZIP magic also covers the OOXML and ODF container formats, which
		// are far more common as chat attachments than a bare archive. ODF
		// names its type in the payload; OOXML is identified by its parts.
		if (str_starts_with($bytes, "PK\x03\x04")) {
			if (str_contains($bytes, 'mimetypeapplication/vnd.oasis.opendocument.text')) {
				return 'application/vnd.oasis.opendocument.text';
			}
			if (str_contains($bytes, 'mimetypeapplication/vnd.oasis.opendocument.spreadsheet')) {
				return 'application/vnd.oasis.opendocument.spreadsheet';
			}
			if (str_contains($bytes, 'mimetypeapplication/vnd.oasis.opendocument.presentation')) {
				return 'application/vnd.oasis.opendocument.presentation';
			}
			if (str_contains($bytes, 'word/')) {
				return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			}
			if (str_contains($bytes, 'xl/')) {
				return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
			}
			if (str_contains($bytes, 'ppt/')) {
				return 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
			}

			return 'application/zip';
		}

		return null;
	}

	/**
	 * Ensure a filename carries an extension, deriving one from the media
	 * type when it does not.
	 *
	 * `File.name` is "the original file name" and `File.mimeType` is
	 * required, but a name arriving from another platform is not guaranteed
	 * to have a usable suffix -- object keys and generated names frequently
	 * have none. Nextcloud infers a file's type from its name, so a document
	 * stored without one is an unrecognised blob with no icon and no preview.
	 */
	private function ensureExtension(string $filename, string $mimeType): string {
		$extension = $this->extensionForMimeType($mimeType);

		// Already suffixed with the right extension: nothing to do.
		if ($extension !== '' && str_ends_with(strtolower($filename), $extension)) {
			return $filename;
		}

		// Any other plausible extension is left alone: the sender's own
		// naming is more informative than our table (.jpeg vs .jpg, .yml,
		// .tar.gz), and renaming it would be a downgrade.
		if (preg_match('/\.[A-Za-z0-9]{1,8}$/', $filename) === 1) {
			return $filename;
		}

		return $filename . ($extension !== '' ? $extension : '.bin');
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

		// Re-read the node rather than returning the one we just wrote
		// through. newFile() creates an *empty* file, so this File object was
		// built from a zero-byte cache entry, and putContent() does not
		// refresh it. Talk decides whether an attachment can be previewed
		// with `$size > 0 && isMimeSupported(...)` on the shared node, so a
		// stale zero size means an image renders as an empty frame -- right
		// dimensions, no picture -- while opening it works, because that
		// path reads the file from disk again.
		try {
			$fresh = $folder->get($target);
			if ($fresh instanceof File) {
				return $fresh;
			}
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Attachment] Could not re-read stored attachment', [
				'target' => $target,
				'exception' => $e->getMessage(),
			]);
		}

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
