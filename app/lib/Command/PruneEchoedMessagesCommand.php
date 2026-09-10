<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Command;

use OCA\W3dsLogin\Service\AttachmentSyncService;
use OCA\W3dsLogin\Service\EvaultClient;
use OCA\W3dsLogin\Service\UserProvisioningService;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retract duplicate Message envelopes this instance wrote to an eVault.
 *
 * An inbound attachment materialises as a Talk room share, and Talk generates
 * its own comment for that share. That comment used to look like a locally
 * composed message, so it was pushed back out as a *new* Message envelope --
 * a copy of something the user never sent, now replicated to every
 * participant. The loopback itself is fixed, but envelopes already written
 * remain in the vaults and keep syncing back.
 *
 * A duplicate is identified conservatively, by all of:
 *
 *  - it is a Message envelope this instance created (we hold the mapping),
 *  - the local comment it maps to is a file share (verb `object_shared`),
 *  - the *same* share is also mapped to a different envelope that arrived
 *    inbound, which is the original this one duplicates.
 *
 * Anything failing those tests is left alone. Deletion is permanent and the
 * envelope may be the only copy of a message, so the command reports by
 * default and only deletes when given --delete.
 */
class PruneEchoedMessagesCommand extends Command {
	public function __construct(
		private IDBConnection $db,
		private EvaultClient $evaultClient,
		private UserProvisioningService $userProvisioning,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this
			->setName('w3ds_login:prune-echoed-messages')
			->setDescription('Retract duplicate Message envelopes created by the inbound-attachment loopback.')
			->addOption('delete', null, InputOption::VALUE_NONE, 'Actually delete. Without this the command only reports.')
			->addOption('user', null, InputOption::VALUE_REQUIRED, 'Limit to a single Nextcloud user ID.');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$delete = (bool)$input->getOption('delete');
		$onlyUser = $input->getOption('user');

		$echoes = $this->findEchoedEnvelopes(is_string($onlyUser) ? $onlyUser : null);

		if ($echoes === []) {
			$output->writeln('<info>No echoed message envelopes found.</info>');
			return 0;
		}

		$output->writeln(sprintf(
			'Found %d echoed message envelope(s)%s.',
			count($echoes),
			$delete ? '' : ' (reporting only; pass --delete to retract them)',
		));

		$removed = 0;
		$failed = 0;

		foreach ($echoes as $echo) {
			$output->writeln(sprintf(
				'  %s  comment=%s  share=%s  owner=%s',
				$echo['global_id'],
				$echo['local_id'],
				$echo['share_local_id'],
				$echo['owner_w3id'],
			));

			if (!$delete) {
				continue;
			}

			if ($this->evaultClient->deleteMetaEnvelope($echo['owner_w3id'], $echo['global_id'])) {
				$this->forgetMapping($echo['local_id']);
				$removed++;
			} else {
				$failed++;
				$output->writeln(sprintf('    <error>failed to delete %s</error>', $echo['global_id']));
			}
		}

		if ($delete) {
			$output->writeln(sprintf('<info>Retracted %d envelope(s), %d failure(s).</info>', $removed, $failed));
		}

		return $failed > 0 ? 1 : 0;
	}

	/**
	 * Find Message mappings whose local comment is a file share that another
	 * mapping already claims as inbound.
	 *
	 * @return list<array{global_id: string, local_id: string, share_local_id: string, owner_w3id: string}>
	 */
	private function findEchoedEnvelopes(?string $onlyUser): array {
		// Every share this instance ingested from a peer. Their envelopes are
		// the originals and must never be deleted.
		$inboundShares = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('local_id', 'global_id')
			->from('w3ds_id_mappings')
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('message')))
			->andWhere($qb->expr()->like('local_id', $qb->createNamedParameter('share:%')));
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$shareId = substr((string)$row['local_id'], strlen('share:'));
			$inboundShares[$shareId] = (string)$row['global_id'];
		}
		$result->closeCursor();

		if ($inboundShares === []) {
			return [];
		}

		// Comment-keyed Message mappings; each one's comment is checked for
		// being a share of an already-ingested attachment.
		$echoes = [];
		$qb = $this->db->getQueryBuilder();
		$qb->select('local_id', 'global_id', 'owner_w3id')
			->from('w3ds_id_mappings')
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('message')))
			->andWhere($qb->expr()->notLike('local_id', $qb->createNamedParameter('share:%')));
		$result = $qb->executeQuery();

		while ($row = $result->fetch()) {
			$localId = (string)$row['local_id'];
			$globalId = (string)$row['global_id'];
			$ownerW3id = (string)$row['owner_w3id'];

			if ($onlyUser !== null && $this->userProvisioning->getLinkedW3id($onlyUser) !== $ownerW3id) {
				continue;
			}

			$comment = $this->readShareComment($localId);
			if ($comment === null) {
				continue;
			}

			$shareId = $this->extractShareId($comment);
			if ($shareId === null || !isset($inboundShares[$shareId])) {
				continue;
			}

			// The original inbound envelope for this share must be a
			// different one, otherwise this row *is* the original.
			if ($inboundShares[$shareId] === $globalId) {
				continue;
			}

			$echoes[] = [
				'global_id' => $globalId,
				'local_id' => $localId,
				'share_local_id' => 'share:' . $shareId,
				'owner_w3id' => $ownerW3id,
			];
		}
		$result->closeCursor();

		return $echoes;
	}

	/**
	 * Read a comment's message text, but only when it is a file share.
	 */
	private function readShareComment(string $commentId): ?string {
		if (!ctype_digit($commentId)) {
			return null;
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('message', 'verb')
				->from('comments')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($commentId)));
			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
		} catch (\Throwable) {
			return null;
		}

		if ($row === false || ($row['verb'] ?? '') !== AttachmentSyncService::TALK_SHARE_VERB) {
			return null;
		}

		return (string)($row['message'] ?? '');
	}

	private function extractShareId(string $commentMessage): ?string {
		$decoded = json_decode($commentMessage, true);
		if (!is_array($decoded)) {
			return null;
		}

		$share = $decoded['parameters']['share'] ?? null;

		return is_scalar($share) && (string)$share !== '' ? (string)$share : null;
	}

	/**
	 * Drop the mapping for a retracted envelope so it is not treated as
	 * already-synced if the same comment is ever reconsidered.
	 */
	private function forgetMapping(string $localId): void {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->delete('w3ds_id_mappings')
				->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('message')))
				->andWhere($qb->expr()->eq('local_id', $qb->createNamedParameter($localId)));
			$qb->executeStatement();
		} catch (\Throwable) {
			// The envelope is already gone; a stale mapping row is harmless.
		}
	}
}
