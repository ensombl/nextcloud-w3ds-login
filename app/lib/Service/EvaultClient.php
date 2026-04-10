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
     * Resolve a W3ID to the MetaEnvelope ID of their User profile envelope.
     * This is the ID other platforms expect in participantIds / senderId fields.
     */
    public function getProfileEnvelopeId(string $w3id): ?string {
        $cacheKey = self::PROFILE_ID_CACHE_PREFIX . $w3id;
        $cached = $this->cache->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $result = $this->fetchMetaEnvelopes($w3id, self::USER_SCHEMA_ID, 1, null);
            $edges = $result['edges'] ?? [];
            if (empty($edges)) {
                $this->logger->warning('No User profile envelope found in eVault', ['w3id' => $w3id]);
                return null;
            }

            $profileId = $edges[0]['node']['id'] ?? null;
            if ($profileId !== null) {
                $this->cache->set($cacheKey, $profileId, self::PROFILE_ID_CACHE_TTL);
                $this->cache->set(
                    self::PROFILE_ID_W3ID_CACHE_PREFIX . $profileId,
                    $w3id,
                    self::PROFILE_ID_CACHE_TTL,
                );
            }
            return $profileId;
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
