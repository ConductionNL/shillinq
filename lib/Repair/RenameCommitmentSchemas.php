<?php

/**
 * Shillinq RenameCommitmentSchemas Repair Step
 *
 * Renames the seven Dutch commitment-cluster schema slugs to their English
 * names IN PLACE, before the register import runs.
 *
 * WHY THIS IS NEEDED — AND WHY IT MUST RUN FIRST.
 *
 * OpenRegister's configuration importer matches an incoming schema definition
 * against the existing ones BY SLUG (see
 * `OCA\OpenRegister\Service\Configuration\ImportHandler`, which indexes
 * schemas by slug and creates one when the slug is absent). It has no notion
 * that a schema was renamed.
 *
 * So if InitializeSettings imports the register.d fragment while the database
 * still holds `Verplichting`, the importer finds no schema slugged
 * `Commitment`, and CREATES A SECOND SCHEMA — a new row, a new id, and a new,
 * empty per-schema shard table `oc_openregister_table_{registerId}_{schemaId}`
 * (both segments are numeric ids, so the table name follows the id, not the
 * slug). Every existing object stays bound to the OLD schema id and its old
 * shard table. Nothing errors. Nothing is deleted. The register simply grows a
 * duplicate schema, and every read through the new name returns an empty list
 * — the failure presents as "there is no commitment data", not as a fault.
 *
 * Renaming the row first means the importer MATCHES the existing schema by its
 * new slug and updates it, so the id, the shard table and every object survive.
 * That ordering is the entire point of this step: it is registered as the first
 * post-migration step, ahead of InitializeSettings.
 *
 * OBJECT ROWS. `openregister_objects.schema` may carry either the schema id or
 * the schema slug (the sibling RetireSubsidieSchema step matches on both for
 * exactly this reason). Id-bearing rows need nothing — the id does not change.
 * Slug-bearing rows are rewritten to the new slug here.
 *
 * SAFETY. Idempotent, non-destructive and fail-soft:
 *   - a rename happens only when the OLD slug exists and the NEW one does not,
 *     so a second run is a no-op and a half-applied instance completes cleanly;
 *   - if BOTH slugs exist the pair is REFUSED and logged, never merged: that
 *     means an import already created the duplicate, and choosing which of two
 *     populated schemas wins is not a decision a repair step may take silently;
 *   - the lookup is register-scoped, so a same-slug schema owned by another app
 *     is never touched (this instance is known to carry two distinct schemas
 *     both slugged `automation`, and decidesk already owns the slug `order`);
 *   - nothing is dropped or deleted, and a \Throwable never blocks the upgrade.
 *
 * Upgrade-only by placement: Nextcloud runs post-migration steps only when
 * `$previousVersion !== ''`, and a fresh install has no legacy slugs to move.
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
 * Renames the commitment-cluster schema slugs from Dutch to English in place,
 * ahead of the register import that would otherwise duplicate them.
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 */
class RenameCommitmentSchemas implements IRepairStep {

	/**
	 * Prefix identifying shillinq's own register slug(s).
	 *
	 * @var string
	 */
	private const REGISTER_SLUG_PREFIX = 'shillinq';

	/**
	 * Old Dutch slug => new English slug, for the commitment cluster.
	 *
	 * Keyed by the slug as stored, which is what OpenRegister matches on. The
	 * human-readable `title` column is moved alongside it.
	 *
	 * @var array<string, string>
	 */
	private const SLUG_MAP = [
		'Verplichting' => 'Commitment',
		'Verplichtingsregel' => 'CommitmentLine',
		'Verplichtingsmutatie' => 'CommitmentMovement',
		'Goedkeuringsstap' => 'ApprovalStep',
		'Mandaat' => 'Mandate',
		'TenderNedAanbesteding' => 'TenderNedProcurement',
		'OpdrachtUitvoering' => 'OrderFulfilment',
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
		return 'Shillinq: rename the Dutch commitment schema slugs to English';
	}//end getName()

	/**
	 * Run the rename.
	 *
	 * @param IOutput $output Repair output channel.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			if ($this->db->tableExists('openregister_schemas') === false) {
				$output->info('RenameCommitmentSchemas: OpenRegister is absent; nothing to do.');
				return;
			}

			$schemaIds = $this->ownSchemaIds();
			if ($schemaIds === []) {
				$output->info('RenameCommitmentSchemas: no shillinq register found; nothing to do.');
				return;
			}

			$renamed = 0;
			foreach (self::SLUG_MAP as $old => $new) {
				if ($this->renameOne(old: $old, new: $new, schemaIds: $schemaIds, output: $output) === true) {
					$renamed++;
				}
			}

			$output->info('RenameCommitmentSchemas: renamed ' . $renamed . ' of ' . count(self::SLUG_MAP) . ' schema slug(s).');
		} catch (Throwable $e) {
			// Fail-soft: a rename failure must never block the upgrade.
			$this->logger->warning(
				'RenameCommitmentSchemas: step failed; leaving the schemas untouched.',
				['exception' => $e->getMessage()]
			);
			$output->warning('RenameCommitmentSchemas: skipped (' . $e->getMessage() . ').');
		}//end try
	}//end run()

	/**
	 * Rename one schema slug, when it is safe to do so.
	 *
	 * @param string             $old       The Dutch slug as stored.
	 * @param string             $new       The English slug to move to.
	 * @param array<int, string> $schemaIds Schema ids owned by shillinq's register(s).
	 * @param IOutput            $output    Repair output channel.
	 *
	 * @return bool True when a row was renamed.
	 */
	private function renameOne(string $old, string $new, array $schemaIds, IOutput $output): bool {
		$oldId = $this->schemaIdBySlug(slug: $old, schemaIds: $schemaIds);
		if ($oldId === null) {
			// Already renamed, or this instance never had the schema.
			return false;
		}

		if ($this->schemaIdBySlug(slug: $new, schemaIds: $schemaIds) !== null) {
			// Both slugs live in shillinq's register: an import already created
			// the duplicate. Picking a winner would silently discard one side's
			// objects, so refuse and surface it instead.
			$this->logger->warning(
				'RenameCommitmentSchemas: both the old and the new slug exist; refusing to merge.',
				['old' => $old, 'new' => $new]
			);
			$output->warning(
				'RenameCommitmentSchemas: "' . $old . '" and "' . $new . '" BOTH exist in the shillinq register. '
				. 'Refusing to merge them automatically — resolve the duplicate by hand.'
			);
			return false;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update('openregister_schemas')
			->set('slug', $qb->createNamedParameter($new))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($oldId)))
			->andWhere($qb->expr()->eq('slug', $qb->createNamedParameter($old)));
		$qb->executeStatement();

		$this->retitle(schemaId: $oldId, old: $old, new: $new);
		$this->repointObjects(old: $old, new: $new);

		$output->info('RenameCommitmentSchemas: "' . $old . '" -> "' . $new . '".');
		return true;
	}//end renameOne()

	/**
	 * Move the human-readable title when it still reads as the old slug.
	 *
	 * A title the operator has customised is left alone — only a title that is
	 * literally the old schema name is moved, so this cannot clobber a
	 * deliberate label.
	 *
	 * @param int|string $schemaId The schema row id.
	 * @param string     $old      The Dutch slug.
	 * @param string     $new      The English slug.
	 *
	 * @return void
	 */
	private function retitle(int|string $schemaId, string $old, string $new): void {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('openregister_schemas')
				->set('title', $qb->createNamedParameter($new))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($schemaId)))
				->andWhere($qb->expr()->eq('title', $qb->createNamedParameter($old)));
			$qb->executeStatement();
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameCommitmentSchemas: could not move the schema title.',
				['old' => $old, 'exception' => $e->getMessage()]
			);
		}
	}//end retitle()

	/**
	 * Repoint object rows that reference the schema by SLUG rather than by id.
	 *
	 * `openregister_objects.schema` holds either form. Id-bearing rows need no
	 * change — the id is stable across the rename — but a slug-bearing row would
	 * be orphaned by it.
	 *
	 * @param string $old The Dutch slug.
	 * @param string $new The English slug.
	 *
	 * @return void
	 */
	private function repointObjects(string $old, string $new): void {
		try {
			if ($this->db->tableExists('openregister_objects') === false) {
				return;
			}

			$qb = $this->db->getQueryBuilder();
			$qb->update('openregister_objects')
				->set('schema', $qb->createNamedParameter($new))
				->where($qb->expr()->eq('schema', $qb->createNamedParameter($old)));
			$moved = $qb->executeStatement();

			if ($moved > 0) {
				$this->logger->info(
					'RenameCommitmentSchemas: repointed slug-bearing object rows.',
					['old' => $old, 'new' => $new, 'rows' => $moved]
				);
			}
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameCommitmentSchemas: could not repoint slug-bearing object rows.',
				['old' => $old, 'exception' => $e->getMessage()]
			);
		}//end try
	}//end repointObjects()

	/**
	 * Resolve a schema id by slug, restricted to shillinq's own schemas.
	 *
	 * The restriction is what keeps this off another app's identically-slugged
	 * schema; a slug is only unique per register, not instance-wide.
	 *
	 * @param string             $slug      The slug to resolve.
	 * @param array<int, string> $schemaIds Schema ids owned by shillinq's register(s).
	 *
	 * @return int|string|null The schema id, or null when absent.
	 */
	private function schemaIdBySlug(string $slug, array $schemaIds): int|string|null {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('openregister_schemas')
			->where($qb->expr()->eq('slug', $qb->createNamedParameter($slug)))
			->andWhere($qb->expr()->in('id', $qb->createNamedParameter($schemaIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->setMaxResults(1);
		$id = $qb->executeQuery()->fetchOne();
		if ($id === false) {
			return null;
		}

		return $id;
	}//end schemaIdBySlug()

	/**
	 * Collect the schema ids linked to shillinq's register(s).
	 *
	 * `openregister_registers.schemas` is a JSON array of schema ids.
	 *
	 * @return array<int, string> Schema ids, as strings for a portable IN().
	 */
	private function ownSchemaIds(): array {
		try {
			$rows = $this->db->executeQuery(
				'SELECT schemas FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?',
				[self::REGISTER_SLUG_PREFIX . '%']
			)->fetchAll(\PDO::FETCH_COLUMN);
		} catch (Exception $e) {
			$this->logger->warning(
				'RenameCommitmentSchemas: could not resolve the shillinq register(s).',
				['exception' => $e->getMessage()]
			);
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$decoded = json_decode((string)$row, true);
			if (is_array($decoded) === false) {
				continue;
			}

			foreach ($decoded as $id) {
				if (is_scalar($id) === true && (string)$id !== '') {
					$ids[] = (string)$id;
				}
			}
		}

		return array_values(array_unique($ids));
	}//end ownSchemaIds()
}//end class
