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
		$response = $client->post($url, [
			'timeout' => self::HTTP_TIMEOUT,
			'headers' => $headers,
			'body' => json_encode($payload),
		]);

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
			$response = $client->get($url, [
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => $headers,
			]);
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
				return null;
			}

			$canonical = null;
			$fallback = null;
			foreach ($envelopes as $env) {
				$eName = is_string($env['eName'] ?? null) ? $env['eName'] : '';
				$envId = is_string($env['id'] ?? null) ? $env['id'] : '';
				if ($eName === '' || $envId === '') {
					continue;
				}

				// Prime caches for everyone we can see on this eVault.
				$this->cache->set(self::PROFILE_ID_CACHE_PREFIX . $eName, $envId, self::PROFILE_ID_CACHE_TTL);
				$this->cache->set(self::PROFILE_ID_W3ID_CACHE_PREFIX . $envId, $eName, self::PROFILE_ID_CACHE_TTL);

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

			// Re-prime the canonical mapping under the cache key we'll read on next call.
			if ($found !== null) {
				$this->cache->set($cacheKey, $found, self::PROFILE_ID_CACHE_TTL);
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
