<?php

/**
 * Shillinq RenameMandateCommitmentKinds Repair Step
 *
 * Translates the Dutch commitment-kind values stored INSIDE the
 * `Mandate.kind_commitment` JSON ARRAY to their English names.
 *
 * WHY THIS EXISTS SEPARATELY FROM RenameDutchValues.
 *
 * Tranche 2 renamed the `Commitment.kind` enum (`inkooporder` ->
 * `purchase_order` and three siblings). `Mandate.kind_commitment` holds the
 * SAME vocabulary, but as a JSON list — "this mandate covers these kinds of
 * commitment" — rather than a scalar.
 *
 * {@see ValueMigrationPort::rewrite()} is
 *
 *     UPDATE <table> SET <column> = :new WHERE <column> = :old
 *
 * an equality match on the WHOLE cell. Against a stored
 * `["inkooporder","leasing"]` it matches nothing and returns 0. Adding
 * `kind_commitment` to the RenameDutchValues map would therefore have been
 * worse than omitting it: the step would report success having migrated no
 * mandate at all, and the omission would look deliberate and covered.
 *
 * WHAT BREAKS WITHOUT THIS STEP. {@see \OCA\Shillinq\Lifecycle\MandateEnforcer}
 * decides whether a mandate covers a commitment with
 *
 *     in_array($kind, $mandate['kind_commitment'], true)
 *
 * where `$kind` is read from the Commitment and is now ENGLISH. A stored
 * mandate still listing `inkooporder` stops matching. It does not error — an
 * empty list already means "any kind", so a non-empty Dutch list simply fails
 * every comparison and the mandate silently stops applying. The visible effect
 * is a commitment being routed to approval, or refused, when the mandate that
 * should have authorised it is sitting right there in the register.
 *
 * SAFETY. Idempotent, non-destructive, fail-soft:
 *   - a row is rewritten only when the decoded value is a LIST that actually
 *     contains one of the four Dutch values, so a re-run is a no-op and an
 *     already-English mandate is left untouched;
 *   - anything that is not a JSON list is skipped rather than coerced, so a
 *     null, a string or an object is never destroyed;
 *   - unknown members of the list are preserved verbatim — only the four
 *     mapped values move;
 *   - scoped to shillinq's own register shard tables, so no other app's data
 *     is reachable;
 *   - a \Throwable degrades to a warning: a repair step that throws aborts
 *     `occ upgrade`.
 *
 * Registered AFTER RenameDutchValues, which moves the scalar
 * `Commitment.kind` column this list mirrors.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites the Dutch commitment-kind members of the Mandate.kind_commitment
 * JSON array, which a whole-cell equality UPDATE cannot reach.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class RenameMandateCommitmentKinds implements IRepairStep {

	/**
	 * Prefix identifying shillinq's own register slug(s).
	 *
	 * @var string
	 */
	private const REGISTER_SLUG_PREFIX = 'shillinq';

	/**
	 * The column holding the JSON list.
	 *
	 * @var string
	 */
	private const COLUMN = 'kind_commitment';

	/**
	 * Dutch => English, identical to the Commitment.kind entries that
	 * RenameDutchValues applies to the scalar column.
	 *
	 * @var array<string, string>
	 */
	private const KIND_MAP = [
		'inkooporder' => 'purchase_order',
		'arbeidscontract' => 'employment_contract',
		'subsidiebeschikking' => 'grant_decision',
		'huurovereenkomst' => 'lease_agreement',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Database connection.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Shillinq: translate the Dutch kinds inside Mandate.kind_commitment';
	}//end getName()

	/**
	 * Run the rewrite.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$tables = $this->shardTablesWithColumn();
			if ($tables === []) {
				$output->info('RenameMandateCommitmentKinds: no shillinq mandate table found; nothing to do.');
				return;
			}

			$changed = 0;
			foreach ($tables as $table) {
				$changed += $this->rewriteTable(table: $table);
			}

			$output->info('RenameMandateCommitmentKinds: rewrote ' . $changed . ' mandate row(s).');
		} catch (Throwable $e) {
			// Fail-soft: never block the upgrade.
			$this->logger->warning(
				'RenameMandateCommitmentKinds: step failed; leaving the mandates untouched.',
				['exception' => $e->getMessage()]
			);
			$output->warning('RenameMandateCommitmentKinds: skipped (' . $e->getMessage() . ').');
		}//end try
	}//end run()

	/**
	 * Rewrite every row of one table whose list still holds a Dutch kind.
	 *
	 * @param string $table The shard table name.
	 *
	 * @return int Number of rows rewritten.
	 */
	private function rewriteTable(string $table): int {
		try {
			$rows = $this->db->executeQuery(
				'SELECT id, `' . self::COLUMN . '` AS kinds FROM `' . $table . '`'
			)->fetchAll();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameMandateCommitmentKinds: could not read a mandate table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return 0;
		}

		$changed = 0;
		foreach ($rows as $row) {
			$translated = $this->translate(raw: ($row['kinds'] ?? null));
			if ($translated === null) {
				continue;
			}

			try {
				$this->db->executeStatement(
					'UPDATE `' . $table . '` SET `' . self::COLUMN . '` = ? WHERE id = ?',
					[$translated, $row['id']]
				);
				$changed++;
			} catch (Exception $e) {
				$this->logger->warning(
					'RenameMandateCommitmentKinds: could not write a mandate row.',
					['table' => $table, 'id' => ($row['id'] ?? '?'), 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		return $changed;
	}//end rewriteTable()

	/**
	 * Translate one stored cell, or return null when it needs no change.
	 *
	 * Returning null for "nothing to do" is what makes the step idempotent: a
	 * second run finds no Dutch member and writes nothing.
	 *
	 * @param mixed $raw The stored cell.
	 *
	 * @return string|null The new JSON, or null to leave the row alone.
	 */
	private function translate(mixed $raw): ?string {
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		$decoded = json_decode($raw, true);

		// Only a LIST is rewritten. A null, a scalar or an object is left as it
		// is rather than coerced into a shape it never had.
		if (is_array($decoded) === false || $decoded === []) {
			return null;
		}

		if (array_is_list($decoded) === false) {
			return null;
		}

		$touched = false;
		$out = [];
		foreach ($decoded as $member) {
			if (is_string($member) === true && isset(self::KIND_MAP[$member]) === true) {
				$out[] = self::KIND_MAP[$member];
				$touched = true;
				continue;
			}

			// Unknown members survive verbatim.
			$out[] = $member;
		}

		if ($touched === false) {
			return null;
		}

		$encoded = json_encode($out);
		if ($encoded === false) {
			return null;
		}

		return $encoded;
	}//end translate()

	/**
	 * Shillinq shard tables that actually carry the kind_commitment column.
	 *
	 * @return array<int, string>
	 */
	private function shardTablesWithColumn(): array {
		try {
			$ids = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?',
				[self::REGISTER_SLUG_PREFIX . '%']
			)->fetchAll(\PDO::FETCH_COLUMN);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameMandateCommitmentKinds: could not resolve the shillinq registers.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		if ($ids === []) {
			return [];
		}

		$markers = [];
		foreach ($ids as $id) {
			$markers[] = 'openregister_table_' . ((int)$id) . '_';
		}

		try {
			$stmt = $this->db->prepare(
				'SELECT table_name, column_name FROM information_schema.columns WHERE column_name = :col'
			);
			$stmt->bindValue('col', self::COLUMN);
			$stmt->execute();
		} catch (Throwable $e) {
			$this->logger->warning(
				'RenameMandateCommitmentKinds: could not list columns.',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$tables = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['table_name'] ?? '');
			if ($name === '') {
				continue;
			}

			foreach ($markers as $marker) {
				if (strpos($name, $marker) !== false) {
					$tables[] = $name;
				}
			}
		}

		return array_values(array_unique($tables));
	}//end shardTablesWithColumn()
}//end class
