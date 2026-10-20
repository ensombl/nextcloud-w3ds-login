<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Command;

use OCA\W3dsLogin\Db\TalkHistory;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Put synced conversations back in the order their messages were sent.
 *
 * Talk shows a conversation in the order entries were written, so history
 * imported before 0.14.2 -- read eVault by eVault, or found late -- can show
 * a reply above the message it answers. New imports restore the order by
 * themselves; this does it for what is already there.
 */
class ResequenceRoomsCommand extends Command {
	public function __construct(
		private IDBConnection $db,
		private TalkHistory $talkHistory,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('w3ds_login:resequence-rooms')
			->setDescription('Put synced Talk conversations back in the order their messages were sent, and the conversation list in order of their last message.')
			->addOption('room', null, InputOption::VALUE_REQUIRED, 'Only this conversation (its token).');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$only = $input->getOption('room');
		if (is_string($only) && $only !== '') {
			$tokens = [$only];
		} else {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('local_id')
				->from('w3ds_id_mappings')
				->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('chat')));
			$result = $qb->executeQuery();
			$tokens = array_map('strval', $result->fetchAll(\PDO::FETCH_COLUMN));
			$result->closeCursor();
		}

		$rooms = 0;
		$moved = 0;
		foreach ($tokens as $token) {
			try {
				$count = $this->talkHistory->resequence($token);
				// And its place in the conversation list.
				$this->talkHistory->refreshRoomActivity($token);
			} catch (\Throwable $e) {
				$output->writeln(sprintf('  <error>%s: %s</error>', $token, $e->getMessage()));
				continue;
			}
			if ($count > 0) {
				$output->writeln(sprintf('  %s  %d entr%s moved', $token, $count, $count === 1 ? 'y' : 'ies'));
				$rooms++;
				$moved += $count;
			}
		}

		$output->writeln(sprintf('<info>%d conversation(s) reordered, %d entries moved.</info>', $rooms, $moved));

		return 0;
	}
}
