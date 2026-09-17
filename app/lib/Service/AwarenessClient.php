<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Service;

use OCA\W3dsLogin\AppInfo\Application;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads awareness packets from Awareness as a Service.
 *
 * AaaS is the single fanout point for eVault mutations. Every write to any
 * eVault commits an immutable event beside the data, and AaaS owns delivery of
 * those events to the platforms that care. That replaces the arrangement this
 * app grew up with, where the only way to learn about a message was to list
 * every participant's eVault and reconcile the replicas by hand.
 *
 * Two ways in, both landing at the same handler:
 *
 *  - AaaS POSTs to our webhook as events happen (sub-second, needs a publicly
 *    reachable URL),
 *  - we poll {@see fetchPackets()} from a stored cursor (a minute behind, works
 *    anywhere, and catches up after downtime).
 *
 * There is no write path here. Outbound sync still writes to the sender's own
 * eVault over GraphQL; AaaS observes that write and tells everyone else.
 */
class AwarenessClient {
	/** Ontologies this app can act on. Everything else is acked and dropped. */
	public const FILE_ONTOLOGY = 'w3ds-file-v1';

	private const HTTP_TIMEOUT = 30;

	/**
	 * Page size for a packet poll.
	 *
	 * The service accepts up to 500. The instance carries packets for every
	 * platform in the ecosystem -- hundreds of thousands of them -- so the
	 * ontology filter, not the page size, is what makes this tractable; a
	 * large page mainly saves round trips while catching up after downtime.
	 */
	private const PAGE_SIZE = 200;

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether an API key and base URL have been configured.
	 *
	 * Without them the app falls back to webhook-only delivery, which is how
	 * it behaved before AaaS existed.
	 */
	public function isConfigured(): bool {
		return $this->baseUrl() !== '' && $this->apiKey() !== '';
	}

	public function baseUrl(): string {
		return rtrim($this->config->getAppValue(Application::APP_ID, 'awareness_base_url', ''), '/');
	}

	private function apiKey(): string {
		return $this->config->getAppValue(Application::APP_ID, 'awareness_api_key', '');
	}

	/**
	 * Read one page of awareness packets.
	 *
	 * Paging is by opaque cursor over the service's receive order. A cursor
	 * supersedes `from`, so the caller passes `from` only for the very first
	 * poll -- which is what stops a fresh install replaying the entire history
	 * of the ecosystem into someone's Talk.
	 *
	 * @param string[] $ontologies Schema IDs to receive; empty means everything.
	 * @return array{packets: list<array<string, mixed>>, nextCursor: ?string, hasMore: bool}|null
	 *                                                                                             Null on failure, which the caller must treat as "try again later"
	 *                                                                                             rather than "no packets" -- advancing the cursor past an error
	 *                                                                                             would skip messages permanently.
	 */
	public function fetchPackets(?string $cursor, array $ontologies = [], ?string $from = null): ?array {
		if (!$this->isConfigured()) {
			return null;
		}

		$query = ['limit' => self::PAGE_SIZE];
		if ($ontologies !== []) {
			$query['ontology'] = implode(',', $ontologies);
		}
		if ($cursor !== null && $cursor !== '') {
			$query['cursor'] = $cursor;
		} elseif ($from !== null && $from !== '') {
			$query['from'] = $from;
		}

		try {
			$response = $this->clientService->newClient()->get($this->baseUrl() . '/api/packets', [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->apiKey(),
					'Accept' => 'application/json',
				],
				'query' => $query,
				'timeout' => self::HTTP_TIMEOUT,
			]);

			$body = json_decode((string)$response->getBody(), true);
			if (!is_array($body) || !is_array($body['packets'] ?? null)) {
				$this->logger->warning('[W3DS Awareness] Unexpected packet response shape');

				return null;
			}

			$next = $body['nextCursor'] ?? null;

			return [
				'packets' => array_values(array_filter($body['packets'], 'is_array')),
				'nextCursor' => is_string($next) && $next !== '' ? $next : null,
				'hasMore' => (bool)($body['hasMore'] ?? false),
			];
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Awareness] Failed to fetch packets', [
				'exception' => $e->getMessage(),
			]);

			return null;
		}
	}

	/**
	 * Register (or refresh) our webhook subscription.
	 *
	 * AaaS reconciles a catch-all subscription for every platform in the
	 * registry, so deliveries arrive without this. Registering explicitly buys
	 * two things: an ontology filter, so we are not woken for every packet in
	 * the ecosystem, and a shared secret, so a delivery can be authenticated
	 * rather than trusted because it arrived.
	 *
	 * @param string[] $ontologies
	 */
	public function ensureSubscription(string $targetUrl, array $ontologies, string $secret): bool {
		if (!$this->isConfigured()) {
			return false;
		}

		$existing = $this->listSubscriptions();
		if ($existing === null) {
			return false;
		}

		foreach ($existing as $subscription) {
			if (($subscription['targetUrl'] ?? null) === $targetUrl) {
				return true;
			}
		}

		try {
			$this->clientService->newClient()->post($this->baseUrl() . '/api/subscriptions', [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->apiKey(),
					'Content-Type' => 'application/json',
				],
				'body' => json_encode([
					'targetUrl' => $targetUrl,
					'ontologyFilter' => $ontologies,
					'evaultFilter' => [],
					'secret' => $secret,
				]),
				'timeout' => self::HTTP_TIMEOUT,
			]);

			$this->logger->info('[W3DS Awareness] Registered webhook subscription', [
				'targetUrl' => $targetUrl,
			]);

			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Awareness] Failed to register subscription', [
				'exception' => $e->getMessage(),
			]);

			return false;
		}
	}

	/**
	 * @return list<array<string, mixed>>|null Null when the call failed, which
	 *                                         is not the same as "no subscriptions".
	 */
	public function listSubscriptions(): ?array {
		if (!$this->isConfigured()) {
			return null;
		}

		try {
			$response = $this->clientService->newClient()->get($this->baseUrl() . '/api/subscriptions', [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->apiKey(),
					'Accept' => 'application/json',
				],
				'timeout' => self::HTTP_TIMEOUT,
			]);

			$body = json_decode((string)$response->getBody(), true);
			$subscriptions = is_array($body) ? ($body['subscriptions'] ?? null) : null;

			return is_array($subscriptions) ? array_values(array_filter($subscriptions, 'is_array')) : null;
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Awareness] Failed to list subscriptions', [
				'exception' => $e->getMessage(),
			]);

			return null;
		}
	}

	/**
	 * Consumer identity and approval status, for the admin settings page.
	 *
	 * @return array<string, mixed>|null
	 */
	public function me(): ?array {
		if (!$this->isConfigured()) {
			return null;
		}

		try {
			$response = $this->clientService->newClient()->get($this->baseUrl() . '/api/me', [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->apiKey(),
					'Accept' => 'application/json',
				],
				'timeout' => self::HTTP_TIMEOUT,
			]);

			$body = json_decode((string)$response->getBody(), true);

			return is_array($body) ? $body : null;
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Awareness] Failed to read consumer identity', [
				'exception' => $e->getMessage(),
			]);

			return null;
		}
	}

	/**
	 * The ontology of a packet, however the service spelled it.
	 *
	 * The polling API returns `ontology`; webhook deliveries and the protocol
	 * documentation say `schemaId`. Both name the same thing.
	 *
	 * @param array<string, mixed> $packet
	 */
	public static function ontologyOf(array $packet): string {
		foreach (['ontology', 'schemaId'] as $key) {
			$value = $packet[$key] ?? null;
			if (is_string($value) && $value !== '') {
				return $value;
			}
		}

		return '';
	}

	/**
	 * The idempotency key for one delivery.
	 *
	 * Delivery is at-least-once, so the same event can arrive more than once
	 * and receivers are required to deduplicate on this. It is deliberately
	 * not the MetaEnvelope id: a create and its later updates share that id,
	 * so deduplicating on it would silently discard every edit.
	 *
	 * Packets backfilled from before AaaS carry a synthetic
	 * `legacy-packet:<uuid>` value, so this is an opaque string rather than a
	 * UUID. Falls back to the envelope id when absent, which is weaker but
	 * better than treating every delivery as new.
	 *
	 * @param array<string, mixed> $packet
	 */
	public static function eventIdOf(array $packet): string {
		foreach (['eventId', 'id'] as $key) {
			$value = $packet[$key] ?? null;
			if (is_string($value) && $value !== '') {
				return $value;
			}
		}

		return '';
	}
}
