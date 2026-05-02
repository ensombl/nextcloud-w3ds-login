<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Controller;

use OCA\W3dsLogin\AppInfo\Application;
use OCA\W3dsLogin\Db\W3dsMappingMapper;
use OCA\W3dsLogin\Service\EvaultClient;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Endpoints for the in-room "Add W3DS users" button.
 *
 * - search: list user-profile envelopes from the searcher's eVault that
 *   match a query string. Filters out the searcher and existing room
 *   participants. **Does not provision NC accounts.**
 * - addToRoom: provision NC accounts for the picked w3ids, then add them
 *   to the given Talk room via Talk's ParticipantService.
 */
class W3dsContactsController extends Controller {
	public function __construct(
		IRequest $request,
		private IUserSession $userSession,
		private UserProvisioningService $userProvisioning,
		private W3dsMappingMapper $mappingMapper,
		private EvaultClient $evaultClient,
		private LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function listProfiles(string $roomToken = ''): JSONResponse {
		$searcher = $this->userSession->getUser();
		if ($searcher === null) {
			return new JSONResponse(['error' => 'unauthenticated'], 401);
		}

		$searcherW3id = $this->userProvisioning->getLinkedW3id($searcher->getUID());
		if ($searcherW3id === null) {
			return new JSONResponse([
				'results' => [],
				'reason' => 'searcher-not-linked',
			]);
		}

		$excludedW3ids = [$searcherW3id => true];
		$excludedNcUids = [$searcher->getUID() => true];
		if ($roomToken !== '') {
			foreach ($this->getRoomParticipantUids($roomToken) as $uid) {
				$excludedNcUids[$uid] = true;
				$linked = $this->userProvisioning->getLinkedW3id($uid);
				if ($linked !== null) {
					$excludedW3ids[$linked] = true;
				}
			}
		}

		try {
			$envelopes = $this->evaultClient->listMetaEnvelopesByOntology($searcherW3id, EvaultClient::USER_SCHEMA_ID);
		} catch (\Throwable $e) {
			$this->logger->warning('[W3DS Contacts] eVault list failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(['results' => [], 'error' => 'evault-unreachable'], 200);
		}

		$results = [];
		foreach ($envelopes as $env) {
			$w3id = is_string($env['eName'] ?? null) ? $env['eName'] : '';
			if ($w3id === '' || isset($excludedW3ids[$w3id])) {
				continue;
			}
			$parsed = is_array($env['parsed'] ?? null) ? $env['parsed'] : [];
			$displayName = $this->pickDisplayName($parsed, $w3id);
			$avatarUrl = is_string($parsed['avatarUrl'] ?? null) ? $parsed['avatarUrl'] : '';
			$username = is_string($parsed['username'] ?? null) ? $parsed['username'] : '';

			$existingUid = null;
			try {
				$existingUid = $this->mappingMapper->findByW3id($w3id)->getNcUid();
				if (isset($excludedNcUids[$existingUid])) {
					continue;
				}
			} catch (DoesNotExistException) {
				// not linked yet — fine, will be provisioned on add
			}

			$results[] = [
				'w3id' => $w3id,
				'displayName' => $displayName,
				'username' => $username,
				'avatarUrl' => $avatarUrl,
				'existingUid' => $existingUid,
			];
		}

		return new JSONResponse(['results' => $results]);
	}

	#[NoAdminRequired]
	public function addToRoom(): JSONResponse {
		$w3ids = $this->request->getParam('w3ids', []);
		$roomToken = (string)$this->request->getParam('roomToken', '');

		if (!is_array($w3ids) || empty($w3ids) || $roomToken === '') {
			return new JSONResponse(['error' => 'invalid-params'], 400);
		}

		$searcher = $this->userSession->getUser();
		if ($searcher === null) {
			return new JSONResponse(['error' => 'unauthenticated'], 401);
		}

		// Caller must be a participant of the room (Talk would refuse the
		// add anyway, but we want a clean 403 instead of a 500).
		$participantUids = $this->getRoomParticipantUids($roomToken);
		if (!in_array($searcher->getUID(), $participantUids, true)) {
			return new JSONResponse(['error' => 'not-in-room'], 403);
		}

		if (!class_exists(\OCA\Talk\Manager::class)) {
			return new JSONResponse(['error' => 'talk-unavailable'], 500);
		}

		$results = [];
		$attendeesPayload = [];

		foreach ($w3ids as $w3id) {
			if (!is_string($w3id) || $w3id === '') {
				continue;
			}
			try {
				$user = $this->userProvisioning->findOrCreateUser($w3id);
				if ($user === null) {
					$results[] = ['w3id' => $w3id, 'status' => 'provision-failed'];
					continue;
				}
				$uid = $user->getUID();
				if (in_array($uid, $participantUids, true)) {
					$results[] = ['w3id' => $w3id, 'status' => 'already-in-room', 'uid' => $uid];
					continue;
				}
				$attendeesPayload[] = ['actorType' => 'users', 'actorId' => $uid];
				$results[] = ['w3id' => $w3id, 'status' => 'queued', 'uid' => $uid];
			} catch (\Throwable $e) {
				$this->logger->warning('[W3DS Contacts] provision failed', [
					'w3id' => $w3id,
					'exception' => $e->getMessage(),
				]);
				$results[] = ['w3id' => $w3id, 'status' => 'provision-failed'];
			}
		}

		if (empty($attendeesPayload)) {
			return new JSONResponse(['results' => $results]);
		}

		try {
			$manager = \OCP\Server::get(\OCA\Talk\Manager::class);
			$room = $manager->getRoomByToken($roomToken);
			$participantService = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
			$participantService->addUsers($room, $attendeesPayload);

			foreach ($results as &$row) {
				if ($row['status'] === 'queued') {
					$row['status'] = 'added';
				}
			}
			unset($row);
		} catch (\Throwable $e) {
			$this->logger->error('[W3DS Contacts] addUsers failed', [
				'roomToken' => $roomToken,
				'exception' => $e,
			]);
			foreach ($results as &$row) {
				if ($row['status'] === 'queued') {
					$row['status'] = 'add-failed';
				}
			}
			unset($row);
			return new JSONResponse(['results' => $results, 'error' => 'add-failed'], 500);
		}

		return new JSONResponse(['results' => $results]);
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
	 * @return string[]
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
			$this->logger->info('[W3DS Contacts] participant enumeration failed', [
				'roomToken' => $roomToken,
				'exception' => $e->getMessage(),
			]);
		}
		return $uids;
	}
}
