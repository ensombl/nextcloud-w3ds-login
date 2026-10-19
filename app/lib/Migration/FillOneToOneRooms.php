<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Migration;

use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Add the missing person to one-to-one rooms created before 0.14.1.
 *
 * Talk adds only the first person to a new one-to-one and adds the other
 * when a message is sent through its chat API; imported messages never were,
 * so the other person -- often the one the conversation was imported for --
 * was never in the room and could not see it. Talk's own repair call fills
 * the room from the two names it was created with.
 */
class FillOneToOneRooms implements IRepairStep {
	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	public function getName(): string {
		return 'Add the missing participant to imported one-to-one W3DS conversations';
	}

	public function run(IOutput $output): void {
		if (!class_exists(\OCA\Talk\Manager::class) || !$this->db->tableExists('talk_rooms')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('r.token')
			->from('talk_rooms', 'r')
			->innerJoin('r', 'w3ds_id_mappings', 'm', $qb->expr()->andX(
				$qb->expr()->eq('m.local_id', 'r.token'),
				$qb->expr()->eq('m.entity_type', $qb->createNamedParameter('chat')),
			))
			->where($qb->expr()->eq('r.type', $qb->createNamedParameter(1)));
		$result = $qb->executeQuery();
		$tokens = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		$manager = \OCP\Server::get(\OCA\Talk\Manager::class);
		$participants = \OCP\Server::get(\OCA\Talk\Service\ParticipantService::class);
		foreach ($tokens as $token) {
			try {
				$participants->ensureOneToOneRoomIsFilled($manager->getRoomByToken((string)$token));
			} catch (\Throwable $e) {
				$this->logger->info('Could not fill a one-to-one room', ['token' => $token, 'exception' => $e->getMessage()]);
			}
		}
		$output->info(sprintf('Checked %d one-to-one W3DS conversation(s)', count($tokens)));
	}
}
