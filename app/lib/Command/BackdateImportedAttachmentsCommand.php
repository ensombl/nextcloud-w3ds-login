<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Command;

use OCA\W3dsLogin\Db\TalkHistory;
use OCA\W3dsLogin\Service\EvaultClient;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Give attachments imported before 0.14 the date they were sent.
 *
 * Talk dates a file share at the moment it is created, so every picture
 * imported from someone's history showed as sent on the day of the import.
 * Imports now keep the original date; this corrects the ones made earlier,
 * reading each one's send time from the message envelope it came from.
 */
class BackdateImportedAttachmentsCommand extends Command {
	public function __construct(
		private IDBConnection $db,
		private EvaultClient $evaultClient,
		private TalkHistory $talkHistory,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('w3ds_login:backdate-imported-attachments')
			->setDescription('Give attachments imported from W3DS the date they were originally sent.')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report without changing anything.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = (bool)$input->getOption('dry-run');

		$qb = $this->db->getQueryBuilder();
		$qb->select('m.local_id', 'm.global_id', 'm.owner_w3id', 's.share_with', 's.stime')
			->from('w3ds_id_mappings', 'm')
			->innerJoin('m', 'share', 's', $qb->expr()->eq('m.local_id', $qb->func()->concat($qb->expr()->literal('share:'), $qb->expr()->castColumn('s.id', \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR))))
			->where($qb->expr()->eq('m.entity_type', $qb->createNamedParameter('message')))
			->andWhere($qb->expr()->eq('m.origin', $qb->createNamedParameter('inbound')))
			->andWhere($qb->expr()->like('m.local_id', $qb->createNamedParameter('share:%')));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		$changed = 0;
		foreach ($rows as $row) {
			$envelope = $this->evaultClient->fetchMetaEnvelopeById((string)$row['owner_w3id'], (string)$row['global_id']);
			$createdAt = $envelope['parsed']['createdAt'] ?? null;
			if (!is_string($createdAt) || $createdAt === '') {
				continue;
			}
			try {
				$at = new \DateTimeImmutable($createdAt);
			} catch (\Throwable) {
				continue;
			}
			if (abs($at->getTimestamp() - (int)$row['stime']) < 60) {
				continue;
			}

			$output->writeln(sprintf('  %s  %s -> %s', $row['local_id'], date('Y-m-d H:i', (int)$row['stime']), $at->format('Y-m-d H:i')));
			if (!$dryRun) {
				$this->talkHistory->backdateShare((int)substr((string)$row['local_id'], 6), (string)$row['share_with'], $at);
			}
			$changed++;
		}

		$output->writeln(sprintf('<info>%d attachment(s) %s.</info>', $changed, $dryRun ? 'would be re-dated' : 're-dated'));

		return 0;
	}
}
