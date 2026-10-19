<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Command;

use OCA\W3dsLogin\Service\EvaultClient;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Remove Talk rooms that were made from a reference instead of a chat.
 *
 * Some platforms leave a reference in each participant's eVault, pointing
 * at the real conversation in another eVault. Before references were
 * resolved, each one became a room of its own, with no title, nobody in it
 * but its reader, and nothing ever posted to it. This finds those rooms by
 * re-reading the envelope each was made from, and deletes a room only when
 * that envelope is a reference and the room holds no messages.
 *
 * Reports by default; deletes with --delete.
 */
class CleanupReferenceRoomsCommand extends Command {
	public function __construct(
		private IDBConnection $db,
		private EvaultClient $evaultClient,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('w3ds_login:cleanup-reference-rooms')
			->setDescription('Remove empty Talk rooms that were created from chat references instead of chats.')
			->addOption('delete', null, InputOption::VALUE_NONE, 'Actually delete. Without this the command only reports.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!class_exists(\OCA\Talk\Manager::class)) {
			$output->writeln('<error>Talk is not installed.</error>');
			return 1;
		}
		$delete = (bool)$input->getOption('delete');

		$qb = $this->db->getQueryBuilder();
		$qb->select('m.local_id', 'm.global_id', 'm.owner_w3id', 'r.id', 'r.name')
			->from('w3ds_id_mappings', 'm')
			->innerJoin('m', 'talk_rooms', 'r', $qb->expr()->eq('r.token', 'm.local_id'))
			->where($qb->expr()->eq('m.entity_type', $qb->createNamedParameter('chat')))
			->andWhere($qb->expr()->eq('m.origin', $qb->createNamedParameter('inbound')));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$manager = \OCP\Server::get(\OCA\Talk\Manager::class);
		$roomService = \OCP\Server::get(\OCA\Talk\Service\RoomService::class);
		$found = 0;
		$deleted = 0;

		foreach ($rows as $row) {
			if ($this->messageCount((int)$row['id']) > 0) {
				continue;
			}
			$envelope = $this->evaultClient->fetchMetaEnvelopeById((string)$row['owner_w3id'], (string)$row['global_id']);
			$parsed = is_array($envelope['parsed'] ?? null) ? $envelope['parsed'] : null;
			if ($parsed === null || ($parsed['isReference'] ?? false) !== true) {
				continue;
			}

			$found++;
			$output->writeln(sprintf('  %s  "%s"  reference to %s/%s', $row['local_id'], $row['name'], $parsed['canonicalOwnerEName'] ?? '?', $parsed['canonicalChatId'] ?? '?'));
			if (!$delete) {
				continue;
			}

			try {
				$roomService->deleteRoom($manager->getRoomByToken((string)$row['local_id']));
				$this->forget((string)$row['local_id']);
				$deleted++;
			} catch (\Throwable $e) {
				$output->writeln(sprintf('  <error>could not delete %s: %s</error>', $row['local_id'], $e->getMessage()));
			}
		}

		$output->writeln(sprintf(
			'<info>%d room(s) made from references%s.</info>',
			$found,
			$delete ? sprintf(', %d deleted', $deleted) : ' (reporting only; pass --delete to remove them)',
		));

		return 0;
	}

	private function messageCount(int $roomId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('comments')
			->where($qb->expr()->eq('object_type', $qb->createNamedParameter('chat')))
			->andWhere($qb->expr()->eq('object_id', $qb->createNamedParameter((string)$roomId)))
			->andWhere($qb->expr()->in('verb', $qb->createNamedParameter(['comment', 'object_shared'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}

	/** Drop the room's chat mapping and remembered eVaults. */
	private function forget(string $roomToken): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('w3ds_id_mappings')
			->where($qb->expr()->orX(
				$qb->expr()->andX(
					$qb->expr()->eq('entity_type', $qb->createNamedParameter('chat')),
					$qb->expr()->eq('local_id', $qb->createNamedParameter($roomToken)),
				),
				$qb->expr()->andX(
					$qb->expr()->eq('entity_type', $qb->createNamedParameter('chat_vault')),
					$qb->expr()->like('local_id', $qb->createNamedParameter($this->db->escapeLikeParameter($roomToken . '|') . '%')),
				),
			))
			->executeStatement();
	}
}
