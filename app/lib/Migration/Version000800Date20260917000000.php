<?php

declare(strict_types=1);

namespace OCA\W3dsLogin\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Removes the bookkeeping that per-vault polling required.
 *
 * Inbound sync used to list every participant's eVault. A message lives as an
 * envelope in its author's vault and as a reference in everyone else's, so the
 * same message came back once per participant, each time under a different
 * envelope ID. Two kinds of row existed to repair that: a content signature to
 * recognise the copies, and an occurrence counter so that sending "ok" twice
 * was not mistaken for one message seen twice.
 *
 * Reading one ordered packet stream from Awareness as a Service removes the
 * cause. Each message is announced once, carrying an event ID the protocol
 * defines as the deduplication key, so neither row has anything left to do.
 *
 * The cursor table goes for the same reason: it stored a position per user per
 * ontology because there was a separate read per user per ontology. One stream
 * needs one position, which lives in appconfig.
 *
 * Deliberately kept: the `chat` and `message` rows in w3ds_id_mappings. Those
 * record which Talk room is which eVault chat, and which Talk comment is which
 * envelope. Nothing else holds that correspondence -- not Talk, which knows
 * nothing of eVaults, and not the packet, which knows nothing of Talk -- so
 * losing them would make every arriving message look new and post a second
 * copy of every conversation.
 */
class Version000800Date20260917000000 extends SimpleMigrationStep {
	/**
	 * Row types that only ever existed to reconcile per-vault replicas.
	 *
	 * `inbound_post` is here too: it was the durable half of a lock meant to
	 * stop an inbound message being pushed straight back out, which is now
	 * done by scoping a counter to the Talk call itself.
	 */
	private const RETIRED_ENTITY_TYPES = ['message_sig', 'message_occ', 'inbound_post'];

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('w3ds_sync_cursors')) {
			/** @psalm-suppress UndefinedDocblockClass */
			$schema->dropTable('w3ds_sync_cursors');
		}

		return $schema;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$deleted = 0;

		foreach (self::RETIRED_ENTITY_TYPES as $entityType) {
			$query = $this->db->getQueryBuilder();
			$query->delete('w3ds_id_mappings')
				->where($query->expr()->eq('entity_type', $query->createNamedParameter($entityType)));

			$deleted += $query->executeStatement();
		}

		if ($deleted > 0) {
			$output->info(sprintf('Removed %d obsolete replica-dedup rows.', $deleted));
		}
	}
}
