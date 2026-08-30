<?php

/**
 * Budget schema split migrator
 *
 * The unit-tested migration core for `budget-core-schema`'s resolution of the
 * `Budget` schema collision (`openspec/changes/budget-core-schema/design.md`
 * §1–§2). `lib/Settings/register.d/` used to carry TWO full `Budget` schema
 * declarations — `bookkeeping-provincies-bbv-variant.json` (BBV programme
 * vocabulary: `totalAmount`/`programmeStructure`/`fiscalYear`) and
 * `bookkeeping-verplichtingenadministratie.json` (commitment-capacity
 * vocabulary: `authorised_amount`/`financialYear`/`programmeCode`) — that
 * `SettingsService::deepMergeConfig()` unioned into one franken-schema,
 * concatenating `required` without dedup. This change renames the two
 * fragments' schemas to `BbvProgrammeBudget` and `CommitmentBudget`
 * respectively (two distinct, non-colliding schemas), which means any object
 * still persisted under the retired `Budget` slug must be re-pointed to
 * whichever of the two it actually is.
 *
 * ## Splitting one source slug into two possible targets
 *
 * Unlike {@see SubsidieOrderConsolidationMigrator}'s straight rename (one
 * source slug, one target slug), this migration is a SPLIT: one source slug
 * (`Budget`) fans out to one of two target slugs depending on which
 * vocabulary a given live object's fields match — the same field-presence
 * classification the retired `BbvBudgetVocabulary` used to do at READ time,
 * reused here for classification instead of tolerant reading.
 *
 * ## Fail-closed on anything unclassifiable
 *
 * A row that carries neither vocabulary's identifying fields (or,
 * pathologically, both) cannot be safely re-pointed — guessing would risk
 * silently mis-filing a live budget under the wrong domain. `classify()`
 * returns `null` for such a row, and `migrateBatch()`'s count guard
 * (`assertCountsMatch(sourceCount, bbvCount + commitmentCount)`) ABORTS the
 * whole batch — leaving every source row intact, including the ones that
 * classified cleanly — the moment even one row is unclassifiable. No partial
 * migration, no silent drop, mirroring
 * {@see SubsidieOrderConsolidationMigrator::assertCountsMatch()}'s contract
 * exactly.
 *
 * ## Live re-verification, not a one-time measurement
 *
 * The live `Budget` object count was measured `total: 0` on 2026-08-20
 * (`GET /apps/openregister/api/objects?register=shillinq&schema=Budget&_limit=1`).
 * Given that, this migrator's own fixture-driven unit tests are the
 * acceptance evidence for `budget-core-schema` tasks.md groups 1–2; the
 * live re-check against the shared dev instance (and any other real
 * deployment before this ships there, per the `payroll-leaves-to-hrmq`
 * precedent) is recorded separately in the change's PR description, not
 * satisfied by these tests alone.
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
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Migration;

use RuntimeException;

/**
 * Pure, unit-testable migration core for the `Budget` schema split.
 */
final class BudgetSchemaSplitMigrator {

	/**
	 * The retired, colliding source schema slug.
	 */
	public const SOURCE_SCHEMA = 'Budget';

	/**
	 * The BBV-vocabulary target schema slug.
	 */
	public const TARGET_BBV = 'BbvProgrammeBudget';

	/**
	 * The commitment-vocabulary target schema slug.
	 */
	public const TARGET_COMMITMENT = 'CommitmentBudget';

	/**
	 * Classify one legacy `Budget` object by which vocabulary its fields match.
	 *
	 * Mirrors the field-presence logic the retired `BbvBudgetVocabulary` used
	 * for tolerant READS, reused here for classification: an object carrying
	 * `totalAmount`/`programmeStructure` is BBV-shaped, one carrying
	 * `authorised_amount`/`financialYear` is commitment-shaped. A row
	 * matching NEITHER pair — or, pathologically, BOTH — is unclassifiable
	 * and returns `null` rather than guessing (`design.md` §2b).
	 *
	 * @param array<string, mixed> $object The persisted `Budget` object.
	 *
	 * @return string|null `TARGET_BBV`, `TARGET_COMMITMENT`, or `null` when unclassifiable.
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-003
	 */
	public function classify(array $object): ?string {
		$isBbv = array_key_exists('totalAmount', $object) === true
			&& array_key_exists('programmeStructure', $object) === true;
		$isCommitment = array_key_exists('authorised_amount', $object) === true
			&& array_key_exists('financialYear', $object) === true;

		if ($isBbv === true && $isCommitment === false) {
			return self::TARGET_BBV;
		}

		if ($isCommitment === true && $isBbv === false) {
			return self::TARGET_COMMITMENT;
		}

		// Neither vocabulary matched, or (pathologically) both did — either
		// way this row cannot be safely re-pointed without guessing.
		return null;
	}//end classify()

	/**
	 * Re-point a persisted object of a renamed schema to its new slug.
	 *
	 * Pure and byte-safe: only the object's `@self.schema` pointer is
	 * rewritten when it matches `$from`; every other field is preserved
	 * verbatim. An object not under `$from` is returned unchanged. Mirrors
	 * {@see SubsidieOrderConsolidationMigrator::mapObjectToRenamedSchema()}.
	 *
	 * @param array<string, mixed> $object The persisted object (with an `@self` envelope).
	 * @param string $from The source schema slug.
	 * @param string $to The target schema slug.
	 *
	 * @return array<string, mixed> The migrated (or unchanged) object.
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-003
	 */
	public function mapObjectToRenamedSchema(array $object, string $from, string $to): array {
		$self = ($object['@self'] ?? null);
		if (is_array($self) === true && ($self['schema'] ?? null) === $from) {
			$self['schema'] = $to;
			$object['@self'] = $self;
		}

		return $object;
	}//end mapObjectToRenamedSchema()

	/**
	 * Migrate a batch of legacy `Budget` objects, splitting by vocabulary.
	 *
	 * Classifies every source object, groups the classifiable ones by target
	 * schema, and re-points each via {@see mapObjectToRenamedSchema()}. The
	 * migrated total (`BbvProgrammeBudget` count + `CommitmentBudget` count)
	 * MUST equal the source count or the migration ABORTS via
	 * {@see assertCountsMatch()} — any single unclassifiable row aborts the
	 * WHOLE batch, including the rows that classified cleanly, so no source
	 * data is ever touched on a partial failure.
	 *
	 * @param array<int, array<string, mixed>> $sourceObjects The objects under `SOURCE_SCHEMA`.
	 *
	 * @return array{BbvProgrammeBudget: array<int, array<string, mixed>>, CommitmentBudget: array<int, array<string, mixed>>}
	 *         The migrated objects, split by target schema.
	 *
	 * @throws RuntimeException When any object is unclassifiable or the migrated count does not match the source count.
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-003
	 */
	public function migrateBatch(array $sourceObjects): array {
		$bbv = [];
		$commitment = [];

		foreach ($sourceObjects as $object) {
			$target = $this->classify(object: $object);
			if ($target === self::TARGET_BBV) {
				$bbv[] = $this->mapObjectToRenamedSchema(object: $object, from: self::SOURCE_SCHEMA, to: self::TARGET_BBV);
				continue;
			}

			if ($target === self::TARGET_COMMITMENT) {
				$commitment[] = $this->mapObjectToRenamedSchema(object: $object, from: self::SOURCE_SCHEMA, to: self::TARGET_COMMITMENT);
				continue;
			}

			// Unclassifiable — deliberately NOT added to either bucket, so the
			// count guard below sees the shortfall and aborts the whole batch.
		}

		$this->assertCountsMatch(sourceCount: count($sourceObjects), migratedCount: (count($bbv) + count($commitment)));

		return [
			self::TARGET_BBV => $bbv,
			self::TARGET_COMMITMENT => $commitment,
		];
	}//end migrateBatch()

	/**
	 * Assert a source→target object count match, aborting on mismatch.
	 *
	 * Mirrors {@see SubsidieOrderConsolidationMigrator::assertCountsMatch()}'s
	 * fail-closed contract exactly: any shortfall (caused by even one
	 * unclassifiable row) throws, and the caller MUST NOT write any of the
	 * migrated buckets when this throws — the source data is left intact.
	 *
	 * @param int $sourceCount The number of source objects.
	 * @param int $migratedCount The number of migrated objects (BBV + commitment).
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the counts differ (no-row-loss guard).
	 *
	 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-003
	 */
	public function assertCountsMatch(int $sourceCount, int $migratedCount): void {
		if ($sourceCount !== $migratedCount) {
			throw new RuntimeException(
				sprintf(
					'Migration aborted: source count %d does not match migrated count %d (unclassifiable row(s) present); source data left intact.',
					$sourceCount,
					$migratedCount
				)
			);
		}
	}//end assertCountsMatch()
}//end class
