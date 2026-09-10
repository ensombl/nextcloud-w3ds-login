<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class EvaultClient {
	public const USER_SCHEMA_ID = '550e8400-e29b-41d4-a716-446655440000';

	private const REGISTRY_BASE_URL = 'https://registry.w3ds.metastate.foundation';
	private const PLATFORM_NAME = 'nextcloud';
	private const RESOLVE_CACHE_TTL = 300; // 5 minutes
	private const RESOLVE_CACHE_PREFIX = 'w3ds_evault_url_';
	private const PROFILE_ID_CACHE_PREFIX = 'w3ds_profile_id_';
	private const PROFILE_ID_W3ID_CACHE_PREFIX = 'w3ds_profile_w3id_';
	private const PROFILE_ID_CACHE_TTL = 3600; // 1 hour
	private const ONTOLOGY_LIST_CACHE_PREFIX = 'w3ds_ontology_list_';
	private const PLATFORM_TOKEN_CACHE_KEY = 'w3ds_platform_token';
	private const PLATFORM_TOKEN_CACHE_TTL = 86400; // 24 hours (tokens last ~1 year, but refresh daily)
	private const HTTP_TIMEOUT = 15;
	/**
	 * The by-ontology listing returns every envelope of an ontology on the
	 * eVault in one unpaginated response, which routinely runs to tens of
	 * megabytes. At the ordinary request timeout the transfer is cut off
	 * mid-body, the decode fails, and the caller sees an empty list that is
	 * indistinguishable from "this user has no chats" -- so chats and
	 * messages silently never appear. Give the bulk endpoint room to finish.
	 */
	private const LIST_HTTP_TIMEOUT = 120;
	/**
	 * Longest server-requested back-off we will sit through inside a request.
	 *
	 * The eVault's own rate limiter asks for up to ~32s on the shared
	 * by-ontology endpoint. A ceiling below what the server actually asks
	 * for means every 429 is abandoned instead of retried, and the caller
	 * reads the resulting empty list as "no chats". Sit through the wait.
	 */
	private const RATE_LIMIT_MAX_WAIT = 60;
	private const RATE_LIMIT_MAX_RETRIES = 2;

	/** Protocol cap on a single upload's decoded size (eVault rejects above this). */
	private const MAX_UPLOAD_BYTES = 250 * 1024 * 1024;

	private ICache $cache;

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		ICacheFactory $cacheFactory,
		private LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed(Application::APP_ID);
	}

	/**
	 * Get a platform certification token from the Registry.
	 * Cached to avoid repeated requests.
	 */
	public function getPlatformToken(): ?string {
		$cached = $this->cache->get(self::PLATFORM_TOKEN_CACHE_KEY);
		if (is_string($cached) && !empty($cached)) {
			return $cached;
		}

		$url = self::REGISTRY_BASE_URL . '/platforms/certification';

		try {
			$client = $this->clientService->newClient();
			$response = $client->post($url, [
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => ['Content-Type' => 'application/json'],
				'body' => json_encode(['platform' => self::PLATFORM_NAME]),
			]);

			$body = json_decode($response->getBody(), true);
			$token = $body['token'] ?? null;

			if ($token !== null) {
				$this->cache->set(self::PLATFORM_TOKEN_CACHE_KEY, $token, self::PLATFORM_TOKEN_CACHE_TTL);
				$this->logger->info('Obtained platform certification token');
			}

			return $token;
		} catch (\Throwable $e) {
			$this->logger->error('Failed to get platform certification token', ['exception' => $e]);
			return null;
		}
	}

	/**
	 * Resolve a W3ID to its eVault URL via the Registry.
	 */
	public function resolveEvaultUrl(string $w3id): ?string {
		$cacheKey = self::RESOLVE_CACHE_PREFIX . $w3id;
		$cached = $this->cache->get($cacheKey);
		if (is_string($cached)) {
			return $cached;
		}

		$registryBaseUrl = $this->getRegistryBaseUrl();
		$url = $registryBaseUrl . '/resolve?' . http_build_query(['w3id' => $w3id]);

		try {
			$client = $this->clientService->newClient();
			$response = $client->get($url, ['timeout' => self::HTTP_TIMEOUT]);
			$body = json_decode($response->getBody(), true);

			$evaultUrl = $body['uri'] ?? $body['evaultUrl'] ?? null;
			if ($evaultUrl !== null) {
				$this->cache->set($cacheKey, $evaultUrl, self::RESOLVE_CACHE_TTL);
			}

			return $evaultUrl;
		} catch (\Throwable $e) {
			$this->logger->error('Failed to resolve eVault URL', [
				'w3id' => $w3id,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Execute a GraphQL query/mutation against a user's eVault.
	 *
	 * @return array Decoded JSON response body
	 * @throws \RuntimeException on HTTP or GraphQL errors
	 */
	public function graphql(string $w3id, string $query, array $variables = []): array {
		$evaultUrl = $this->resolveEvaultUrl($w3id);
		if ($evaultUrl === null) {
			throw new \RuntimeException("Cannot resolve eVault for W3ID: $w3id");
		}

		$url = rtrim($evaultUrl, '/') . '/graphql';
		$payload = ['query' => $query];
		if (!empty($variables)) {
			$payload['variables'] = $variables;
		}

		$headers = [
			'Content-Type' => 'application/json',
			'X-ENAME' => $w3id,
		];

		$platformToken = $this->getPlatformToken();
		if ($platformToken !== null) {
			$headers['Authorization'] = 'Bearer ' . $platformToken;
		}

		$client = $this->clientService->newClient();
		$response = $this->requestWithRateLimitRetry(
			fn (): \OCP\Http\Client\IResponse => $client->post($url, [
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => $headers,
				'body' => json_encode($payload),
			]),
			$w3id,
		);

		$body = json_decode($response->getBody(), true);
		if (!is_array($body)) {
			throw new \RuntimeException('Invalid GraphQL response from eVault');
		}

		if (!empty($body['errors'])) {
			$msg = $body['errors'][0]['message'] ?? 'Unknown GraphQL error';
			throw new \RuntimeException("eVault GraphQL error: $msg");
		}

		return $body['data'] ?? [];
	}

	/**
	 * Run an eVault request, honouring the server's own back-off when it
	 * answers 429.
	 *
	 * The eVault rate-limits per platform, and a Nextcloud instance resolving
	 * a room full of identities trips that limit routinely. The response says
	 * how long to wait (`retryAfterSeconds`), which is short -- roughly 20s --
	 * so waiting is the difference between showing real names and showing raw
	 * eNames until something else happens to refresh them.
	 *
	 * Only genuinely short waits are honoured; anything longer is treated as
	 * a failure rather than blocking a web request behind it.
	 *
	 * @param callable(): \OCP\Http\Client\IResponse $send
	 * @throws \Throwable the final failure when retries are exhausted
	 */
	private function requestWithRateLimitRetry(callable $send, string $w3id): \OCP\Http\Client\IResponse {
		$attempt = 0;
		while (true) {
			try {
				return $send();
			} catch (\Throwable $e) {
				$wait = $this->rateLimitRetryDelay($e);
				if ($wait === null || $attempt >= self::RATE_LIMIT_MAX_RETRIES) {
					throw $e;
				}

				$attempt++;
				$this->logger->info('eVault rate-limited; waiting before retry', [
					'w3id' => $w3id,
					'waitSeconds' => $wait,
					'attempt' => $attempt,
				]);
				sleep($wait);
			}
		}
	}

	/**
	 * Seconds to wait for a rate-limited request, or null when the failure is
	 * not a 429 we should wait out.
	 */
	private function rateLimitRetryDelay(\Throwable $e): ?int {
		// The HTTP client is Guzzle underneath, but that is an implementation
		// detail of the server rather than a dependency this app declares, so
		// the exception is inspected structurally instead of by class.
		if (!method_exists($e, 'getResponse')) {
			return null;
		}

		$response = $e->getResponse();
		if ($response === null || $response->getStatusCode() !== 429) {
			return null;
		}

		$body = json_decode((string)$response->getBody(), true);
		$retryAfter = is_array($body) ? ($body['retryAfterSeconds'] ?? null) : null;
		if (!is_numeric($retryAfter)) {
			return null;
		}

		$seconds = (int)ceil((float)$retryAfter);
		if ($seconds < 1 || $seconds > self::RATE_LIMIT_MAX_WAIT) {
			return null;
		}

		// The window is measured server side; land just after it closes.
		return $seconds + 1;
	}

	/**
	 * Place a pointer to someone else's MetaEnvelope in a participant's own
	 * eVault.
	 *
	 * The protocol expects every participant of a shared entity to hold a
	 * copy in their own vault. Granting ACL access to the owner's envelope is
	 * not enough on its own: platforms discover entities by listing the
	 * ontology on *their own user's* vault, so an envelope that lives only in
	 * the owner's vault is invisible to everyone else. A `reference` envelope
	 * is the lightweight stand-in the reference implementation writes for the
	 * other participants -- it names the owning vault and envelope rather
	 * than duplicating the content.
	 *
	 * `$reference` is "<ownerEName>/<globalId>", matching web3-adapter.
	 *
	 * Best effort by design: one unreachable participant vault must not fail
	 * the push that already succeeded for the owner.
	 */
	public function storeReference(string $reference, string $targetW3id): bool {
		$query = <<<'GRAPHQL'
        mutation StoreMetaEnvelope($input: MetaEnvelopeInput!) {
            storeMetaEnvelope(input: $input) {
                metaEnvelope {
                    id
                }
            }
        }
        GRAPHQL;

		try {
			$this->graphql($targetW3id, $query, [
				'input' => [
					'ontology' => 'reference',
					'payload' => ['_by_reference' => $reference],
					'acl' => ['*'],
				],
			]);

			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to store reference on participant eVault', [
				'reference' => $reference,
				'targetW3id' => $targetW3id,
				'exception' => $e->getMessage(),
			]);

			return false;
		}
	}

	/**
	 * Create a MetaEnvelope in a user's eVault.
	 *
	 * @return string|null The created MetaEnvelope ID, or null on failure
	 */
	public function createMetaEnvelope(string $w3id, string $ontology, array $payload, array $acl = ['*']): ?string {
		$query = <<<'GRAPHQL'
        mutation CreateMetaEnvelope($input: MetaEnvelopeInput!) {
            createMetaEnvelope(input: $input) {
                metaEnvelope {
                    id
                }
                errors {
                    message
                    code
                }
            }
        }
        GRAPHQL;

		try {
			$data = $this->graphql($w3id, $query, [
				'input' => [
					'ontology' => $ontology,
					'payload' => $payload,
					'acl' => $acl,
				],
			]);

			$result = $data['createMetaEnvelope'] ?? [];
			if (!empty($result['errors'])) {
				$this->logger->error('eVault createMetaEnvelope errors', [
					'w3id' => $w3id,
					'errors' => $result['errors'],
				]);
				return null;
			}

			return $result['metaEnvelope']['id'] ?? null;
		} catch (\Throwable $e) {
			$this->logger->error('Failed to create MetaEnvelope', [
				'w3id' => $w3id,
				'ontology' => $ontology,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Update an existing MetaEnvelope in a user's eVault.
	 */
	public function updateMetaEnvelope(string $w3id, string $globalId, string $ontology, array $payload, array $acl = ['*']): bool {
		$query = <<<'GRAPHQL'
        mutation UpdateMetaEnvelope($id: ID!, $input: MetaEnvelopeInput!) {
            updateMetaEnvelope(id: $id, input: $input) {
                metaEnvelope {
                    id
                }
                errors {
                    message
                    code
                }
            }
        }
        GRAPHQL;

		try {
			$data = $this->graphql($w3id, $query, [
				'id' => $globalId,
				'input' => [
					'ontology' => $ontology,
					'payload' => $payload,
					'acl' => $acl,
				],
			]);

			$result = $data['updateMetaEnvelope'] ?? [];
			if (!empty($result['errors'])) {
				$this->logger->error('eVault updateMetaEnvelope errors', [
					'w3id' => $w3id,
					'globalId' => $globalId,
					'errors' => $result['errors'],
				]);
				return false;
			}

			return true;
		} catch (\Throwable $e) {
			$this->logger->error('Failed to update MetaEnvelope', [
				'w3id' => $w3id,
				'globalId' => $globalId,
				'exception' => $e,
			]);
			return false;
		}
	}

	/**
	 * Fetch MetaEnvelopes from a user's eVault with cursor pagination.
	 *
	 * @return array{edges: array, pageInfo: array}
	 */
	public function fetchMetaEnvelopes(string $w3id, string $ontologyId, int $first = 50, ?string $after = null, ?array $search = null): array {
		$query = <<<'GRAPHQL'
        query FetchMetaEnvelopes($filter: MetaEnvelopeFilterInput, $first: Int, $after: String) {
            metaEnvelopes(filter: $filter, first: $first, after: $after) {
                edges {
                    cursor
                    node {
                        id
                        ontology
                        parsed
                    }
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
        }
        GRAPHQL;

		$filter = ['ontologyId' => $ontologyId];
		if ($search !== null) {
			$filter['search'] = $search;
		}

		$variables = [
			'filter' => $filter,
			'first' => $first,
		];
		if ($after !== null) {
			$variables['after'] = $after;
		}

		try {
			$data = $this->graphql($w3id, $query, $variables);
			return $data['metaEnvelopes'] ?? ['edges' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
		} catch (\Throwable $e) {
			$this->logger->error('Failed to fetch MetaEnvelopes', [
				'w3id' => $w3id,
				'ontologyId' => $ontologyId,
				'exception' => $e,
			]);
			return ['edges' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
		}
	}

	/**
	 * List every MetaEnvelope of the given ontology hosted on the user's
	 * eVault via the REST endpoint `GET /metaenvelopes/by-ontology/:ontology`.
	 * Unlike fetchMetaEnvelopes (GraphQL with pagination + filters), this
	 * returns the full set in one shot. Caller is responsible for filtering.
	 *
	 * Each row is shaped roughly like:
	 *   { id, ontology, acl, eName, envelopes, parsed }
	 *
	 * Optional $cacheTtl > 0 memoises the response in distributed cache
	 * keyed by (w3id, ontology). Use it for hot paths like collaborator
	 * search where the same list is queried per keystroke; leave as 0 for
	 * chat polling where staleness matters.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listMetaEnvelopesByOntology(string $w3id, string $ontology, int $cacheTtl = 0): array {
		$cacheKey = $cacheTtl > 0
			? self::ONTOLOGY_LIST_CACHE_PREFIX . md5($w3id . '|' . $ontology)
			: null;
		if ($cacheKey !== null) {
			$cached = $this->cache->get($cacheKey);
			if (is_array($cached)) {
				return $cached;
			}
		}
		$evaultUrl = $this->resolveEvaultUrl($w3id);
		if ($evaultUrl === null) {
			$this->logger->warning('listMetaEnvelopesByOntology: cannot resolve eVault', ['w3id' => $w3id]);
			return [];
		}

		$url = rtrim($evaultUrl, '/') . '/metaenvelopes/by-ontology/' . rawurlencode($ontology);

		$headers = [
			'Accept' => 'application/json',
		];
		$platformToken = $this->getPlatformToken();
		if ($platformToken !== null) {
			$headers['Authorization'] = 'Bearer ' . $platformToken;
		}

		try {
			$client = $this->clientService->newClient();
			$response = $this->requestWithRateLimitRetry(
				fn (): \OCP\Http\Client\IResponse => $client->get($url, [
					'timeout' => self::LIST_HTTP_TIMEOUT,
					'headers' => $headers,
				]),
				$w3id,
			);
			$body = json_decode($response->getBody(), true);
			$envelopes = $body['metaEnvelopes'] ?? [];
			$envelopes = is_array($envelopes) ? $envelopes : [];
			if ($cacheKey !== null) {
				$this->cache->set($cacheKey, $envelopes, $cacheTtl);
			}
			return $envelopes;
		} catch (\Throwable $e) {
			$this->logger->warning('listMetaEnvelopesByOntology failed', [
				'w3id' => $w3id,
				'ontology' => $ontology,
				'exception' => $e->getMessage(),
			]);
			return [];
		}
	}

	/**
	 * Upload a file to the user's eVault and return its `w3ds://file` URI.
	 *
	 * Blobs are addressed by URI rather than embedded in the message
	 * envelope. The eVault streams the bytes to object storage and records a
	 * File MetaEnvelope (ontology `w3ds-file-v1`), which is what the returned
	 * URI addresses.
	 *
	 * @param string $content Raw file bytes (base64-encoded here)
	 * @return array{uri: string, publicUrl: ?string}|null The `w3ds://file` URI and object-storage URL, or null on failure
	 */
	public function uploadFile(
		string $w3id,
		string $filename,
		string $contentType,
		string $content,
		array $acl = ['*'],
	): ?array {
		if (strlen($content) > self::MAX_UPLOAD_BYTES) {
			$this->logger->warning('Refusing to upload file above the protocol size limit', [
				'w3id' => $w3id,
				'filename' => $filename,
				'bytes' => strlen($content),
			]);
			return null;
		}

		$query = <<<'GRAPHQL'
        mutation UploadFile($input: UploadFileInput!) {
            uploadFile(input: $input) {
                uri
                metaEnvelopeId
                publicUrl
                errors { field message code }
            }
        }
        GRAPHQL;

		try {
			$data = $this->graphql($w3id, $query, [
				'input' => [
					'filename' => $filename,
					'contentType' => $contentType,
					'content' => base64_encode($content),
					'acl' => $acl,
				],
			]);

			$result = $data['uploadFile'] ?? null;
			if (!is_array($result)) {
				return null;
			}

			if (!empty($result['errors'])) {
				$this->logger->warning('eVault rejected file upload', [
					'w3id' => $w3id,
					'filename' => $filename,
					'errors' => $result['errors'],
				]);
				return null;
			}

			$uri = $result['uri'] ?? null;
			if (!is_string($uri) || $uri === '') {
				return null;
			}

			$publicUrl = $result['publicUrl'] ?? null;

			return [
				'uri' => $uri,
				// Handed back so callers can publish a directly renderable
				// reference alongside the w3ds:// one. Peers that render an
				// attachment with an <img> cannot resolve a w3ds:// URI.
				'publicUrl' => is_string($publicUrl) && $publicUrl !== '' ? $publicUrl : null,
			];
		} catch (\Throwable $e) {
			$this->logger->warning('File upload to eVault failed', [
				'w3id' => $w3id,
				'filename' => $filename,
				'exception' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Parse a `w3ds://file?id=@ename/envelopeId` URI.
	 *
	 * @return array{ename: string, metaEnvelopeId: string}|null
	 */
	public function parseFileUri(string $uri): ?array {
		if (!str_starts_with($uri, 'w3ds://file')) {
			return null;
		}

		$query = parse_url($uri, PHP_URL_QUERY);
		if (!is_string($query)) {
			return null;
		}

		parse_str($query, $params);
		$id = $params['id'] ?? null;
		if (!is_string($id) || !str_starts_with($id, '@')) {
			return null;
		}

		// `@ename/envelopeId` -- split on the first slash, matching the
		// reference parser. Both segments must be non-empty, and an eName is
		// more than a bare '@'.
		$slash = strpos($id, '/');
		if ($slash === false) {
			return null;
		}

		$ename = substr($id, 0, $slash);
		$metaEnvelopeId = substr($id, $slash + 1);

		if (strlen($ename) <= 1 || $metaEnvelopeId === '') {
			return null;
		}

		return [
			'ename' => $ename,
			'metaEnvelopeId' => $metaEnvelopeId,
		];
	}

	/**
	 * Resolve a `w3ds://file` URI to the file's metadata and public URL.
	 *
	 * Reads the File MetaEnvelope directly rather than following the eVault's
	 * `/files/:id` redirect, so we get the name and MIME type in the same
	 * round trip instead of inferring them from the blob.
	 *
	 * Two different field vocabularies appear here, and both are legitimate.
	 * A blob uploaded through the eVault's own `uploadFile` mutation is stored
	 * under its internal `w3ds-file-v1` payload (`filename`, `contentType`,
	 * `publicUrl`). A blob written by a platform as an ordinary File entity
	 * uses the published File schema (`a1b2c3d4-...`), whose fields are `name`,
	 * `mimeType`, `url`, and an optional base64 `data`. Reading only the first
	 * vocabulary made every attachment of the second kind dereference to null,
	 * which is why attachments composed on other platforms never appeared.
	 *
	 * @return array{publicUrl: ?string, inlineData: ?string, filename: string, contentType: string, size: int}|null
	 */
	public function dereferenceFileUri(string $uri): ?array {
		$parsed = $this->parseFileUri($uri);
		if ($parsed === null) {
			$this->logger->info('Not a parseable w3ds file URI, ignoring', ['uri' => $uri]);
			return null;
		}

		try {
			$envelope = $this->fetchMetaEnvelopeById($parsed['ename'], $parsed['metaEnvelopeId']);
			$payload = is_array($envelope) ? ($envelope['parsed'] ?? null) : null;
			if (!is_array($payload)) {
				return null;
			}

			// `publicUrl` is the eVault's own upload payload; `url` is the
			// File schema's equivalent and may be explicitly null when the
			// bytes are carried inline instead.
			$publicUrl = $this->firstStringField($payload, ['publicUrl', 'url']);
			if ($publicUrl !== null
				&& !str_starts_with($publicUrl, 'http://')
				&& !str_starts_with($publicUrl, 'https://')) {
				// The redirect target is validated on the eVault side, but we
				// fetch it ourselves, so re-check the scheme here.
				$this->logger->warning('File envelope carries an unsafe URL scheme, ignoring', [
					'uri' => $uri,
				]);
				$publicUrl = null;
			}

			// The File schema keeps a legacy base64 `data` field for blobs
			// never pushed to object storage. It is the only copy of the
			// bytes when `url` is null, so an envelope with one and no URL is
			// still a perfectly resolvable attachment.
			$inlineData = $this->firstStringField($payload, ['data']);

			if ($publicUrl === null && $inlineData === null) {
				$this->logger->info('File envelope carries neither a URL nor inline data', [
					'uri' => $uri,
				]);
				return null;
			}

			return [
				'publicUrl' => $publicUrl,
				'inlineData' => $inlineData,
				'filename' => $this->firstStringField($payload, ['filename', 'name', 'displayName']) ?? 'attachment',
				'contentType' => $this->firstStringField($payload, ['contentType', 'mimeType']) ?? 'application/octet-stream',
				'size' => is_numeric($payload['size'] ?? null) ? (int)$payload['size'] : 0,
			];
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to dereference file URI', [
				'uri' => $uri,
				'exception' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * First of $keys present in $payload as a non-empty string.
	 *
	 * @param array<string, mixed> $payload
	 * @param list<string> $keys
	 */
	private function firstStringField(array $payload, array $keys): ?string {
		foreach ($keys as $key) {
			$value = $payload[$key] ?? null;
			if (is_string($value) && $value !== '') {
				return $value;
			}
		}

		return null;
	}

	/**
	 * Decode the File schema's base64 `data` field.
	 *
	 * Accepts a bare base64 string or a `data:` URI, matching what the
	 * eVault's own upload mutation accepts on the way in. Strict decoding:
	 * PHP silently drops invalid characters otherwise, which would write a
	 * corrupt file rather than failing.
	 */
	public function decodeInlineFileData(string $data, int $maxBytes): ?string {
		$base64 = $data;
		if (str_starts_with($base64, 'data:')) {
			$comma = strpos($base64, ',');
			if ($comma === false) {
				return null;
			}
			$base64 = substr($base64, $comma + 1);
		}

		// Whitespace is legal in transported base64 but not in the decoder.
		$base64 = preg_replace('/\s+/', '', $base64) ?? '';
		if ($base64 === '') {
			return null;
		}

		// Reject before decoding: 4 bytes of base64 are 3 bytes of output, so
		// this bounds the allocation rather than discovering the size after.
		if (intdiv(strlen($base64), 4) * 3 > $maxBytes) {
			$this->logger->warning('Inline file data exceeds the configured limit, skipping', [
				'maxBytes' => $maxBytes,
			]);
			return null;
		}

		$decoded = base64_decode($base64, true);
		if ($decoded === false || $decoded === '') {
			$this->logger->warning('File envelope carries unusable inline data');
			return null;
		}

		if (strlen($decoded) > $maxBytes) {
			return null;
		}

		return $decoded;
	}

	/**
	 * Download the bytes behind a resolved file URL.
	 *
	 * Goes through Nextcloud's HTTP client so the admin's proxy and SSRF
	 * settings apply -- object storage may be on a host the admin has
	 * opinions about.
	 */
	public function downloadFile(string $publicUrl, int $maxBytes): ?string {
		try {
			$response = $this->clientService->newClient()->get($publicUrl, [
				'timeout' => self::HTTP_TIMEOUT,
			]);

			$body = (string)$response->getBody();
			if ($body === '') {
				return null;
			}

			if (strlen($body) > $maxBytes) {
				$this->logger->warning('Remote attachment exceeds the configured limit, skipping', [
					'bytes' => strlen($body),
					'maxBytes' => $maxBytes,
				]);
				return null;
			}

			return $body;
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to download attachment', [
				'exception' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Delete a MetaEnvelope from a user's eVault.
	 *
	 * Only used to retract envelopes this instance wrote in error, such as
	 * the duplicate messages produced by the inbound-attachment loopback.
	 * Deletion is permanent, so callers must be certain of the target.
	 */
	public function deleteMetaEnvelope(string $w3id, string $globalId): bool {
		$query = <<<'GRAPHQL'
        mutation DeleteMetaEnvelope($id: String!) {
            deleteMetaEnvelope(id: $id)
        }
        GRAPHQL;

		try {
			$data = $this->graphql($w3id, $query, ['id' => $globalId]);

			return ($data['deleteMetaEnvelope'] ?? false) === true;
		} catch (\Throwable $e) {
			$this->logger->warning('Failed to delete MetaEnvelope', [
				'w3id' => $w3id,
				'globalId' => $globalId,
				'exception' => $e->getMessage(),
			]);

			return false;
		}
	}

	/**
	 * Fetch a single MetaEnvelope by ID. Used for read-back verification.
	 *
	 * @return array<string, mixed>|null Decoded envelope (id, ontology, parsed) or null
	 */
	public function fetchMetaEnvelopeById(string $w3id, string $globalId): ?array {
		$query = <<<'GRAPHQL'
        query FetchMetaEnvelopeById($id: ID!) {
            metaEnvelope(id: $id) {
                id
                ontology
                parsed
            }
        }
        GRAPHQL;

		try {
			$data = $this->graphql($w3id, $query, ['id' => $globalId]);
			$env = $data['metaEnvelope'] ?? null;
			return is_array($env) ? $env : null;
		} catch (\Throwable $e) {
			$this->logger->warning('fetchMetaEnvelopeById failed', [
				'w3id' => $w3id,
				'globalId' => $globalId,
				'exception' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * Resolve a W3ID to the MetaEnvelope ID of their User profile envelope.
	 * This is the ID other platforms expect in participantIds / senderId fields.
	 *
	 * eVaults can hold multiple User envelopes for the same eName (legacy
	 * replicas + a canonical primary). We must pick the canonical one,
	 * otherwise other platforms won't recognise the participant. The
	 * canonical envelope is the one whose `parsed.ename` matches the eName;
	 * legacy duplicates don't carry that field. We list all envelopes via
	 * the by-ontology REST endpoint and filter client side, since GraphQL
	 * `metaEnvelopes(first: 1)` just returns whatever the eVault returns
	 * first which is non-deterministic across replicas.
	 *
	 * Side effect: primes the bidirectional cache for every visible User
	 * envelope on the resolved eVault.
	 */
	public function getProfileEnvelopeId(string $w3id): ?string {
		$cacheKey = self::PROFILE_ID_CACHE_PREFIX . $w3id;
		$cached = $this->cache->get($cacheKey);
		if (is_string($cached) && $cached !== '') {
			return $cached;
		}

		try {
			$envelopes = $this->listMetaEnvelopesByOntology($w3id, self::USER_SCHEMA_ID);
			if (empty($envelopes)) {
				$this->logger->warning('No User profile envelopes returned from eVault', ['w3id' => $w3id]);

				// The by-ontology listing is a single shared, rate-limited
				// endpoint: it returns every User envelope on the eVault, so
				// it 429s exactly when several identities resolve at once.
				// An empty list there does not mean the profile is missing,
				// and treating it that way leaves the caller with no name to
				// show. Ask GraphQL for this one identity instead.
				$own = $this->findOwnProfileEnvelopeId($w3id);
				if ($own !== null) {
					// Both directions, always. Caching only eName -> envelope
					// leaves resolveW3idFromProfileEnvelopeId() unable to name
					// this person, and a chat's participantIds carry envelope
					// IDs, so the peer silently drops out of the room.
					$this->primeProfileCacheEntry($w3id, $own);
				}

				return $own;
			}

			$canonical = null;
			$fallback = null;
			foreach ($envelopes as $env) {
				$eName = is_string($env['eName'] ?? null) ? $env['eName'] : '';
				$envId = is_string($env['id'] ?? null) ? $env['id'] : '';
				if ($eName === '' || $envId === '') {
					continue;
				}

				$this->primeProfileCacheEntry($eName, $envId);

				if ($eName !== $w3id) {
					continue;
				}

				$parsed = is_array($env['parsed'] ?? null) ? $env['parsed'] : [];
				$parsedEname = is_string($parsed['ename'] ?? null) ? $parsed['ename'] : '';

				if ($parsedEname === $w3id) {
					$canonical = $envId;
					break; // primary copy, stop searching
				}
				if ($fallback === null) {
					$fallback = $envId;
				}
			}

			$found = $canonical ?? $fallback;
			if ($found === null) {
				$this->logger->warning('No User envelope matching W3ID on resolved eVault', ['w3id' => $w3id]);
			} elseif ($canonical === null) {
				$this->logger->info('Falling back to non-canonical User envelope (no parsed.ename match)', [
					'w3id' => $w3id,
					'envelopeId' => $found,
				]);
			}

			// Re-prime the canonical mapping under the cache key we'll read on
			// next call, in both directions: the reverse entry is what lets a
			// chat's participantIds (which are envelope IDs) name a person.
			if ($found !== null) {
				$this->primeProfileCacheEntry($w3id, $found);
			}
			return $found;
		} catch (\Throwable $e) {
			$this->logger->error('Failed to fetch User profile envelope', [
				'w3id' => $w3id,
				'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * Resolve a W3ID's own User profile envelope without the shared
	 * by-ontology listing.
	 *
	 * `graphql()` addresses the identity's own eVault and the filtered
	 * `metaEnvelopes` query returns only their envelopes, so the eName
	 * carried in `parsed.ename` identifies the canonical copy the same way
	 * getProfileEnvelopeId() picks it out of the full listing. This costs a
	 * request per identity, which is why it is the fallback rather than the
	 * primary path.
	 */
	private function findOwnProfileEnvelopeId(string $w3id): ?string {
		try {
			$page = $this->fetchMetaEnvelopes($w3id, self::USER_SCHEMA_ID, 50);
			$edges = is_array($page['edges'] ?? null) ? $page['edges'] : [];

			$fallback = null;
			foreach ($edges as $edge) {
				$node = is_array($edge['node'] ?? null) ? $edge['node'] : [];
				$envId = is_string($node['id'] ?? null) ? $node['id'] : '';
				if ($envId === '') {
					continue;
				}

				$parsed = is_array($node['parsed'] ?? null) ? $node['parsed'] : [];
				if (($parsed['ename'] ?? null) === $w3id) {
					$this->primeProfileCacheEntry($w3id, $envId);

					return $envId;
				}

				$fallback ??= $envId;
			}

			if ($fallback !== null) {
				$this->logger->info('Resolved profile envelope without a parsed.ename match', [
					'w3id' => $w3id,
					'envelopeId' => $fallback,
				]);
				$this->primeProfileCacheEntry($w3id, $fallback);
			}

			return $fallback;
		} catch (\Throwable $e) {
			$this->logger->warning('Per-identity profile envelope lookup failed', [
				'w3id' => $w3id,
				'exception' => $e->getMessage(),
			]);

			return null;
		}
	}

	/**
	 * Re-prime the bidirectional profile envelope cache for every peer
	 * visible to the given viewer. Used by ChatSyncService when an inbound
	 * chat references a participant whose User envelope hasn't been listed
	 * on this eVault yet — refreshing the by-ontology listing once gets us
	 * out of a cold-cache hole without paying for a per-w3id round-trip.
	 */
	public function primeProfileCache(string $viewerW3id): void {
		try {
			$envelopes = $this->listMetaEnvelopesByOntology($viewerW3id, self::USER_SCHEMA_ID);
			foreach ($envelopes as $env) {
				$eName = is_string($env['eName'] ?? null) ? $env['eName'] : '';
				$envId = is_string($env['id'] ?? null) ? $env['id'] : '';
				if ($eName === '' || $envId === '') {
					continue;
				}
				$this->primeProfileCacheEntry($eName, $envId);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('primeProfileCache failed', [
				'viewerW3id' => $viewerW3id,
				'exception' => $e->getMessage(),
			]);
		}
	}

	private function primeProfileCacheEntry(string $eName, string $envId): void {
		$this->cache->set(self::PROFILE_ID_CACHE_PREFIX . $eName, $envId, self::PROFILE_ID_CACHE_TTL);
		$this->cache->set(self::PROFILE_ID_W3ID_CACHE_PREFIX . $envId, $eName, self::PROFILE_ID_CACHE_TTL);
	}

	/**
	 * Reverse lookup: given a User profile envelope ID, return the W3ID it
	 * belongs to. Only succeeds if {@see getProfileEnvelopeId()} has been
	 * called for that W3ID within the cache TTL (which primes both sides).
	 */
	public function resolveW3idFromProfileEnvelopeId(string $envelopeId): ?string {
		if ($envelopeId === '') {
			return null;
		}
		$cached = $this->cache->get(self::PROFILE_ID_W3ID_CACHE_PREFIX . $envelopeId);
		return is_string($cached) && $cached !== '' ? $cached : null;
	}

	private function getRegistryBaseUrl(): string {
		return self::REGISTRY_BASE_URL;
	}
}
