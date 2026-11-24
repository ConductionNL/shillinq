<?php

/**
 * Shillinq RenameCollidingSchemaSlugs repair step.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Moves this app's share of the cross-app slug collisions onto namespaced slugs IN
 * PLACE, before the register import.
 *
 * A schema slug is global per organisation and `SchemaMapper::find()` matches
 * `LOWER(slug)`, so when two apps declare one slug the lookup answers with whichever
 * row it reached first.
 *
 * `Employee` is this app's payroll and detachering facet of a person: contract type,
 * tax classification, GL salary-expense account, placement agency. humaniq's is the
 * person. They share `bsn` and `employeeNumber`, which is exactly the evidence that
 * they are one person and not two, so this side is renamed to say what it is and
 * gains an `employee` uuid pointing at humaniq's record.
 *
 * Why a repair step and why before the import. OpenRegister matches an existing schema
 * by (application, slug): `ImportHandler` calls `findByApplicationAndSlug()` and
 * creates a NEW schema when that misses. A slug rename in the shipped fragment
 * therefore does not rename anything, it CREATES a second schema and silently orphans
 * the first together with every object already written against it. The old schema keeps
 * its shard table and its rows; the app resolves the new id and reads an empty
 * collection. Nothing errors.
 *
 * Separate from {@see RenameCommitmentSchemas}, which is the Dutch-to-English
 * vocabulary pass. Folding these into that map would say they are the same migration,
 * and the next person reading it would have no way to tell why `Employee` is in a list
 * of verplichtingen schemas.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://shillinq.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Renames this app's colliding schema slugs in place, ahead of the register import.
 *
 * @spec exclude No canonical spec covers the cross-app slug namespacing pass. Pointing
 *  this at an existing spec would report conformance to a requirement that says nothing
 *  about it.
 */
class RenameCollidingSchemaSlugs implements IRepairStep {
	/**
	 * Old slug => new slug, with the app it collided with.
	 *
	 * @var array<string, array{to: string, with: string}>
	 */
	private const RENAMES = [
		'Employee' => ['to' => 'payrollEmployee', 'with' => 'humaniq'],
	];

	/**
	 * The owning application, as stored on the schema row.
	 *
	 * @var string
	 */
	private const APPLICATION = 'shillinq';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db Database connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string
	 *
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function getName(): string {
		return 'Namespace the shillinq schema slugs that collided with another app';
	}//end getName()

	/**
	 * Rename each slug, unless doing so would be ambiguous.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec exclude No canonical spec covers the cross-app slug namespacing pass.
	 */
	public function run(IOutput $output): void {
		foreach (self::RENAMES as $from => $rename) {
			$this->renameOne(output: $output, from: $from, to: $rename['to'], with: $rename['with']);
		}
	}//end run()

	/**
	 * Rename one slug.
	 *
	 * @param IOutput $output Repair output.
	 * @param string $from The slug being moved away from.
	 * @param string $to The namespaced slug.
	 * @param string $with The app this slug collided with.
	 *
	 * @return void
	 */
	private function renameOne(IOutput $output, string $from, string $to, string $with): void {
		$old = $this->schemaIds(slug: $from);
		$new = $this->schemaIds(slug: $to);

		if ($old === null || $new === null) {
			$output->info('RenameCollidingSchemaSlugs: schema table unreadable; leaving `' . $from . '` alone.');
			return;
		}

		if ($old === []) {
			$output->info('RenameCollidingSchemaSlugs: no shillinq-owned `' . $from . '`; nothing to do.');
			return;
		}

		$warning = $this->renameWarning(from: $from, to: $to, old: $old, new: $new);
		if ($warning !== null) {
			$output->warning($warning);
			return;
		}

		$output->info(
			'RenameCollidingSchemaSlugs: schema ' . $old[0] . ' renamed `' . $from . '` -> `' . $to
			. '` (collided with ' . $with . '); its objects stay attached.'
		);
	}//end renameOne()

	/**
	 * Refuse a conflicting rename, else perform it; return the output warning
	 * when the slug was left alone, or null when it was renamed.
	 *
	 * @param string $from The slug being moved away from.
	 * @param string $to The namespaced slug.
	 * @param array<int,mixed> $old Ids carrying the old slug (non-empty).
	 * @param array<int,mixed> $new Ids carrying the new slug.
	 *
	 * @return string|null The warning for the repair output, or null on success.
	 */
	private function renameWarning(string $from, string $to, array $old, array $new): string|null {
		$warning = $this->conflictWarning(from: $from, to: $to, old: $old, new: $new);
		if ($warning === null) {
			$warning = $this->renameFailure(from: $from, to: $to, id: $old[0]);
		}

		return $warning;
	}//end renameWarning()

	/**
	 * Log and describe why the rename must not happen, or null when it may.
	 *
	 * @param string $from The slug being moved away from.
	 * @param string $to The namespaced slug.
	 * @param array<int,mixed> $old Ids carrying the old slug (non-empty).
	 * @param array<int,mixed> $new Ids carrying the new slug.
	 *
	 * @return string|null The warning for the repair output, or null.
	 */
	private function conflictWarning(string $from, string $to, array $old, array $new): string|null {
		if ($new !== []) {
			// Both slugs present: each may own objects, and renaming would collide
			// with the new row. Abandoning either set is not a call a repair step
			// gets to make without being asked.
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: both slugs exist; refusing to merge them.',
				['from' => $from, 'to' => $to, 'old' => $old, 'new' => $new]
			);
			return 'RenameCollidingSchemaSlugs: both `' . $from . '` and `' . $to
				. '` exist; refusing to merge them. Resolve by hand.';
		}

		if (count($old) > 1) {
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: duplicate slugs; refusing to guess.',
				['from' => $from, 'ids' => $old]
			);
			return 'RenameCollidingSchemaSlugs: duplicate `' . $from . '` schemas; refusing to guess.';
		}

		return null;
	}//end conflictWarning()

	/**
	 * Rename the schema row; log and describe a failure, or return null on success.
	 *
	 * @param string $from The slug being moved away from.
	 * @param string $to The namespaced slug.
	 * @param mixed $id The schema id.
	 *
	 * @return string|null The warning for the repair output, or null.
	 */
	private function renameFailure(string $from, string $to, mixed $id): string|null {
		try {
			$this->db->executeStatement(
				'UPDATE `*PREFIX*openregister_schemas` SET slug = ? WHERE id = ?',
				[$to, $id]
			);
		} catch (Exception $e) {
			// Safe to fail: the import then creates a new schema rather than
			// updating this one, which is the pre-existing behaviour. Loud,
			// because the objects on the old schema stop being reachable.
			$this->logger->error(
				'RenameCollidingSchemaSlugs: slug rename failed; the import will create a second schema.',
				['from' => $from, 'id' => $id, 'exception' => $e->getMessage()]
			);
			return 'RenameCollidingSchemaSlugs: renaming `' . $from . '` failed; see the log.';
		}

		return null;
	}//end renameFailure()

	/**
	 * Ids of this application's schemas carrying the given slug.
	 *
	 * Scoped to THIS app's rows. Without the application filter the lookup would
	 * find humaniq's `Employee` too, and renaming that is precisely the damage
	 * this step exists to avoid.
	 *
	 * @param string $slug The schema slug to look for.
	 *
	 * @return array<int, mixed>|null The ids, or null when the table cannot be read.
	 */
	private function schemaIds(string $slug): ?array {
		try {
			$rows = $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_schemas` WHERE slug = ? AND application = ?',
				[$slug, self::APPLICATION]
			)->fetchAll(\PDO::FETCH_COLUMN);

			return array_values((array)$rows);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameCollidingSchemaSlugs: could not read the schema table; skipping.',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return null;
		}
	}//end schemaIds()
}//end class
