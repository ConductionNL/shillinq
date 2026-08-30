<?php

/**
 * RevenueContract rename migrator
 *
 * The unit-tested migration core for contracts-single-home. The schema-level
 * rename (IFRS-15's `Contract` → `RevenueContract`; CLM's `Contract` stays
 * canonical and unambiguous) is done non-destructively in the register JSON
 * (lib/Settings/register.d/bookkeeping-ifrs15-revenue.json). This class
 * carries the OBJECT-level migration decision logic, mirroring
 * {@see \OCA\Shillinq\Service\Migration\SubsidieOrderConsolidationMigrator}:
 *
 *  1. A discriminator ({@see isIfrs15Shaped()}) that tells an IFRS-15-shaped
 *     `Contract` object (customerId / fixedConsideration / lifecycleState
 *     present, no contractType / status) apart from a CLM-shaped `Contract`
 *     object (contractType / status present) — both may be sitting under the
 *     same `Contract` slug until this migration runs, because the pre-fix
 *     register merged the two schemas into one (see contracts-single-home
 *     design.md §D1). A CLM-shaped object is left untouched even when it also
 *     carries IFRS-15-only leftover fields (e.g. the semantic-invoice-consume
 *     `contract-handoff-demo-2026` seed, which is a CLM contract riding on the
 *     merge, not an IFRS-15 revenue contract).
 *  2. The field-map that re-points a persisted, IFRS-15-shaped object of the
 *     renamed schema to its new schema slug (`mapObjectToRenamedSchema`), pure
 *     and byte-safe.
 *  3. The source→target count guard (`assertCountsMatch`) that ABORTS —
 *     leaving the source data intact — the moment a migrated batch's output
 *     count does not equal the source count, so a migration can never
 *     silently drop rows (CLM-shaped rows are returned unchanged, not
 *     dropped, so the counts always match at the batch level; per-object
 *     rename correctness is the discriminator's job, not the count guard's).
 *
 * ## Deviation (live register migration deferred)
 *
 * The LIVE re-point of persisted `Contract` objects to `RevenueContract`
 * against a running OpenRegister is verified on a live import (the spec marks
 * the full register migration `@e2e exclude … verified on a live import`),
 * mirroring the `SubsidieOrderConsolidationMigrator` precedent's own
 * deviation note. This class is the buildable, unit-tested half; the live
 * re-point is wired as a repair-step phase in
 * {@see \OCA\Shillinq\Repair\InitializeSettings} that is idempotent by
 * construction (a second run finds zero `Contract`-slugged IFRS-15-shaped
 * objects and no-ops).
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Migration;

use RuntimeException;

/**
 * Pure, unit-testable migration core for the IFRS-15 `Contract` →
 * `RevenueContract` rename.
 */
final class RevenueContractRenameMigrator {

	/**
	 * The renamed IFRS-15 revenue-contract schema slug (source).
	 */
	public const REVENUE_CONTRACT_FROM = 'Contract';

	/**
	 * The renamed IFRS-15 revenue-contract schema slug (target).
	 */
	public const REVENUE_CONTRACT_TO = 'RevenueContract';

	/**
	 * CLM-shaped discriminator fields — presence of either means the object
	 * is a generic contract-lifecycle-management `Contract`, never an
	 * IFRS-15 revenue contract, regardless of what other fields it also
	 * carries (design.md §D3).
	 *
	 * @var array<int,string>
	 */
	private const CLM_DISCRIMINATOR_FIELDS = ['contractType', 'status'];

	/**
	 * IFRS-15-shaped discriminator fields — presence of any, absent the CLM
	 * fields above, means the object is an IFRS-15 revenue contract riding
	 * on the pre-fix merged `Contract` slug.
	 *
	 * @var array<int,string>
	 */
	private const IFRS15_DISCRIMINATOR_FIELDS = ['customerId', 'fixedConsideration', 'lifecycleState'];

	/**
	 * The from/to slug pair for the RevenueContract rename.
	 *
	 * @return array{from: string, to: string}
	 *
	 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
	 */
	public function revenueContractRename(): array {
		return ['from' => self::REVENUE_CONTRACT_FROM, 'to' => self::REVENUE_CONTRACT_TO];
	}//end revenueContractRename()

	/**
	 * Whether a persisted `Contract`-slugged object is IFRS-15-shaped (i.e.
	 * belongs to the revenue-recognition domain, not CLM) and therefore a
	 * candidate for the rename to `RevenueContract`.
	 *
	 * CLM fields take precedence: an object carrying `contractType` or
	 * `status` is a CLM contract even if it also carries IFRS-15-only
	 * leftover fields (e.g. a handoff-demo seed object created before the
	 * merge was un-collided) — it is left under `Contract` untouched.
	 *
	 * @param array<string, mixed> $object The persisted object (data fields, no `@self` required).
	 *
	 * @return bool True when the object is IFRS-15-shaped and should be renamed.
	 *
	 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
	 */
	public function isIfrs15Shaped(array $object): bool {
		foreach (self::CLM_DISCRIMINATOR_FIELDS as $clmField) {
			if (array_key_exists($clmField, $object) === true) {
				return false;
			}
		}

		foreach (self::IFRS15_DISCRIMINATOR_FIELDS as $ifrs15Field) {
			if (array_key_exists($ifrs15Field, $object) === true) {
				return true;
			}
		}

		return false;
	}//end isIfrs15Shaped()

	/**
	 * Re-point a persisted, IFRS-15-shaped object of the renamed schema to
	 * its new slug.
	 *
	 * Pure and byte-safe: only the object's `@self.schema` pointer is
	 * rewritten, and only when it matches `$from` AND the object's data
	 * fields pass {@see isIfrs15Shaped()}; every other field is preserved
	 * verbatim. A CLM-shaped `Contract` object — or one not under `$from` —
	 * is returned unchanged.
	 *
	 * @param array<string, mixed> $object The persisted object (with an `@self` envelope).
	 * @param string $from The source schema slug.
	 * @param string $to The target schema slug.
	 *
	 * @return array<string, mixed> The migrated (or unchanged) object.
	 *
	 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
	 */
	public function mapObjectToRenamedSchema(array $object, string $from, string $to): array {
		$self = ($object['@self'] ?? null);
		if (is_array($self) === true
			&& ($self['schema'] ?? null) === $from
			&& $this->isIfrs15Shaped(object: $object) === true
		) {
			$self['schema'] = $to;
			$object['@self'] = $self;
		}

		return $object;
	}//end mapObjectToRenamedSchema()

	/**
	 * Migrate a batch of source objects, guarded by count.
	 *
	 * Every source object is inspected; IFRS-15-shaped ones are re-pointed to
	 * `$to`, CLM-shaped ones (and anything not under `$from`) are returned
	 * unchanged — no object is ever dropped. The output count MUST equal the
	 * source count or the migration ABORTS (this method never deletes
	 * anything, so an abort leaves the source data intact).
	 *
	 * @param array<int, array<string, mixed>> $sourceObjects The objects under the source slug.
	 * @param string $from The source schema slug.
	 * @param string $to The target schema slug.
	 *
	 * @return array<int, array<string, mixed>> The migrated (and unchanged) objects.
	 *
	 * @throws RuntimeException When the migrated count does not match the source count.
	 *
	 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
	 */
	public function migrateBatch(array $sourceObjects, string $from, string $to): array {
		$migrated = [];
		foreach ($sourceObjects as $object) {
			$migrated[] = $this->mapObjectToRenamedSchema(object: $object, from: $from, to: $to);
		}

		$this->assertCountsMatch(sourceCount: count($sourceObjects), migratedCount: count($migrated));

		return $migrated;
	}//end migrateBatch()

	/**
	 * Assert a source→target object count match, aborting on mismatch.
	 *
	 * @param int $sourceCount The number of source objects.
	 * @param int $migratedCount The number of objects returned by the batch.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the counts differ (no-row-loss guard).
	 *
	 * @spec openspec/changes/contracts-single-home/specs/contracts-single-home/spec.md
	 */
	public function assertCountsMatch(int $sourceCount, int $migratedCount): void {
		if ($sourceCount !== $migratedCount) {
			throw new RuntimeException(
				sprintf(
					'Migration aborted: source count %d does not match migrated count %d; source data left intact.',
					$sourceCount,
					$migratedCount
				)
			);
		}

	}//end assertCountsMatch()
}//end class
