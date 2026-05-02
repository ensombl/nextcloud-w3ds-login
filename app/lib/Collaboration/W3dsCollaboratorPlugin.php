<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Collaboration;

use OCA\W3dsLogin\Db\TentativeUserMapper;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Service\EvaultClient;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Collaboration\Collaborators\ISearchPlugin;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * Feeds W3DS user-profile matches into Nextcloud's collaborator picker
 * (used by Talk's "new conversation" / "add participant" dialogs).
 *
 * Lifecycle:
 *  - Searcher must be a W3DS-linked NC user.
 *  - We list every User envelope visible from the searcher's eVault,
 *    fuzzy-match the typed query against displayName / username / w3id.
 *  - Each match is mapped to a NC UID. Pre-existing linked users keep
 *    their UID. New ones are provisioned via UserProvisioningService and
 *    flagged tentative (cleared by AttendeesAddedTentativeFlipListener
 *    if added to a room, otherwise GC'd by TentativeUserCleanupJob).
 *  - Self and current room participants are filtered out.
 */
class W3dsCollaboratorPlugin implements ISearchPlugin {
	private const TENTATIVE_TTL_SECONDS = 1800; // 30 min

	public function __construct(
		private IUserSession $userSession,
		private IRequest $request,
		private UserProvisioningService $userProvisioning,
		private W3dsMappingMapper $mappingMapper,
		private EvaultClient $evaultClient,
		private TentativeUserMapper $tentativeUserMapper,
		private LoggerInterface $logger,
	) {
	}

	public function search($search, $limit, $offset, ISearchResult $searchResult): bool {
		$search = is_string($search) ? trim($search) : '';
		$this->logger->info('[W3DS Search] plugin invoked', [
			'search' => $search,
			'limit' => $limit,
			'offset' => $offset,
		]);
		if ($search === '') {
			return false;
		}

		$searcher = $this->userSession->getUser();
		if ($searcher === null) {
			return false;
		}
		$searcherUid = $searcher->getUID();

		$searcherW3id = $this->userProvisioning->getLinkedW3id($searcherUid);
		if ($searcherW3id === null) {
			$this->logger->info('[W3DS Search] searcher not linked; bailing', ['uid' => $searcherUid]);
			return false;
		}

		$excludedUids = [$searcherUid => true];
		$itemType = (string)$this->request->getParam('itemType', '');
		$itemId = (string)$this->request->getParam('itemId', '');
		if ($itemType === 'call' && $itemId !== '') {
			foreach ($this->getRoomParticipantUids($itemId) as $uid) {
				$excludedUids[$uid] = true;
			}
		}

		try {
			// Cache the per-eVault user list for 60s so per-keystroke
			// autocomplete doesn't hammer the eVault rate limiter.
			$envelopes = $this->evaultClient->listMetaEnvelopesByOntology(
				$searcherW3id,
				EvaultClient::USER_SCHEMA_ID,
				60,
			);
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Search] eVault list failed', ['exception' => $e->getMessage()]);
			return false;
		}

		$needle = mb_strtolower($search);
		$matches = [];

		foreach ($envelopes as $env) {
			try {
				$w3id = is_string($env['eName'] ?? null) ? $env['eName'] : '';
				if ($w3id === '' || $w3id === $searcherW3id) {
					continue;
				}

				$parsed = is_array($env['parsed'] ?? null) ? $env['parsed'] : [];
				$displayName = $this->pickDisplayName($parsed, $w3id);
				$username = is_string($parsed['username'] ?? null) ? $parsed['username'] : '';

				if (!$this->fuzzyMatch($needle, $displayName, $username, $w3id)) {
					continue;
				}

				$uid = $this->resolveOrProvisionUid($w3id, $parsed);
				if ($uid === null) {
					continue;
				}

				if (isset($excludedUids[$uid])) {
					continue;
				}

				// Key by w3id, not uid. If two NC accounts ended up mapped
				// to the same identity (legacy provisioning race), the
				// resolveOrProvisionUid path already picks the canonical
				// row, but other code paths can have created a parallel
				// account before the unique index was added. Last write
				// wins here — we just need one entry per identity in the
				// dropdown.
				$matches[$w3id] = [
					'label' => $displayName,
					'value' => [
						'shareType' => IShare::TYPE_USER,
						'shareWith' => $uid,
					],
				];
			} catch (\Throwable $e) {
				$this->logger->warning('[W3DS Search] failed to process envelope', [
					'envelopeId' => $env['id'] ?? null,
					'exception' => $e->getMessage(),
				]);
			}
		}

		$results = array_values($matches);
		$totalBeforePaging = count($results);

		if ($offset > 0) {
			$results = array_slice($results, $offset);
		}
		$truncated = count($results) > $limit;
		if ($truncated) {
			$results = array_slice($results, 0, $limit);
		}

		if (!empty($results)) {
			$searchResult->addResultSet(new SearchResultType('users'), $results, []);
		}

		return $truncated || ($offset + count($results)) < $totalBeforePaging;
	}

	private function fuzzyMatch(string $needle, string ...$haystacks): bool {
		foreach ($haystacks as $h) {
			if ($h !== '' && str_contains(mb_strtolower($h), $needle)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string, mixed> $prefetchedProfile
	 */
	private function resolveOrProvisionUid(string $w3id, array $prefetchedProfile): ?string {
		try {
			$mapping = $this->mappingMapper->findByW3id($w3id);
			return $mapping->getNcUid();
		} catch (DoesNotExistException) {
			// not linked yet -- fall through to lazy provision
		}

		$user = $this->userProvisioning->findOrCreateUser($w3id, true, $prefetchedProfile);
		if ($user === null) {
			return null;
		}

		try {
			$this->tentativeUserMapper->markTentative($user->getUID(), time() + self::TENTATIVE_TTL_SECONDS);
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Search] failed to mark user tentative', [
				'uid' => $user->getUID(),
				'exception' => $e->getMessage(),
			]);
		}

		return $user->getUID();
	}

	/**
	 * @param array<string, mixed> $parsed
	 */
	private function pickDisplayName(array $parsed, string $w3id): string {
		$candidate = $parsed['displayName'] ?? null;
		if (is_string($candidate) && trim($candidate) !== '') {
			return trim($candidate);
		}
		$given = is_string($parsed['givenName'] ?? null) ? trim($parsed['givenName']) : '';
		$family = is_string($parsed['familyName'] ?? null) ? trim($parsed['familyName']) : '';
		$joined = trim($given . ' ' . $family);
		if ($joined !== '') {
			return $joined;
		}
		return $w3id;
	}

	/**
	 * @return list<string>
	 */
	private function getRoomParticipantUids(string $roomToken): array {
		if (!class_exists(\OCA\Talk\Manager::class)) {
			return [];
		}

		$uids = [];
		try {
			$manager = \OCP\Server::get(\OCA\Talk\Manager::class);
			$room = $manager->getRoomByToken($roomToken);
			$participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
			foreach ($participantService->getParticipantsForRoom($room) as $p) {
				$attendee = $p->getAttendee();
				if ($attendee->getActorType() !== 'users') {
					continue;
				}
				$uids[] = $attendee->getActorId();
			}
		} catch (\Throwable $e) {
			$this->logger->info('[W3DS Search] could not enumerate room participants', [
				'roomToken' => $roomToken,
				'exception' => $e->getMessage(),
			]);
		}
		return $uids;
	}
}
