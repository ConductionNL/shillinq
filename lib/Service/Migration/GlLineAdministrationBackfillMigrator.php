<?php

/**
 * GLLine administration backfill migrator
 *
 * The unit-tested migration core for `glline-administration-scope` — the
 * schema + data half of closing a real cross-administration read.
 *
 * ## What was wrong
 *
 * `GLLine` declared NO administration or organisation property. Its
 * administration lived one hop away, on the parent `GLTransaction`, and
 * OpenRegister's `filters` address an object's own JSON properties and cannot
 * join. So the SpendAnalytics category / cost-centre / period views — all
 * three sourced from `GLLine` — aggregated EVERY administration in the
 * register, while the fourth view (spend-by-supplier, sourced from
 * `APTransaction`, which does declare `administrationId`) really was scoped.
 * The service looked scoped. It was not.
 *
 * `glline-administration-scope.json` denormalises `administrationId` onto
 * `GLLine`. This class backfills the rows that predate that fragment.
 *
 * ## Why the naive fix was worse than the bug
 *
 * Switching the filter on FIRST would have addressed a property those rows do
 * not carry. An unmatched filter key matches nothing for every value, so every
 * category / cost-centre / period total would silently read ZERO — a wrong
 * number in a bookkeeping total that looks exactly like a real one, which is
 * worse than an exposure that is at least visible to whoever looks. That is
 * why the ordering in this change is normative, not advisory, and why
 * `SpendAnalyticsService` refuses to serve those views at all until
 * `BackfillGlLineAdministration` has proven the backfill complete.
 *
 * ## Resolving a line's administration
 *
 * Through `GLLine.transactionId`, which this repo's writers populate with
 * EITHER the parent's object UUID or its `transactionNumber` business key
 * (`RuleTestDataSeeder` uses the number when one exists). {@see
 * indexAdministrationsByTransaction()} therefore indexes a `GLTransaction`
 * under every identity it can be addressed by, so a line resolves regardless
 * of which idiom wrote it. A parent whose own `administrationId` is blank
 * indexes NOTHING: it cannot answer the question, and stamping `''` would
 * manufacture a fake scope that then satisfies the completeness gate.
 *
 * ## Fail-closed on anything unclassifiable
 *
 * A `GLLine` whose `transactionId` resolves to no indexed `GLTransaction` is
 * UNCLASSIFIABLE. It is not guessed at, not defaulted to the single/first/
 * default administration, and not silently skipped: {@see classify()} returns
 * `CLASS_UNCLASSIFIABLE`, it is deliberately left out of the classified count,
 * and {@see backfillBatch()}'s count guard ({@see assertCountsMatch()}) ABORTS
 * THE WHOLE BATCH — including every row that resolved cleanly — the moment even
 * one row cannot be resolved. Mirrors
 * {@see BudgetSchemaSplitMigrator::assertCountsMatch()}'s contract exactly. A
 * partially backfilled ledger is the one outcome worse than an un-backfilled
 * one, because it is the state in which the completeness gate is the only
 * thing standing between the reader and a silent zero.
 *
 * ## Idempotent
 *
 * A row that already carries a non-empty `administrationId` is returned
 * BYTE-IDENTICAL and counted as `unchanged`. Re-running changes nothing, which
 * is what makes it safe to wire into a repair step that runs on every upgrade.
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
 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Migration;

use RuntimeException;

/**
 * Pure, unit-testable migration core for the `GLLine.administrationId` backfill.
 */
final class GlLineAdministrationBackfillMigrator {

	/**
	 * The schema whose rows are backfilled.
	 */
	public const SCHEMA_GL_LINE = 'GLLine';

	/**
	 * The parent schema that owns the administration (source of truth).
	 */
	public const SCHEMA_GL_TRANSACTION = 'GLTransaction';

	/**
	 * The denormalised scope property added by
	 * `register.d/glline-administration-scope.json`.
	 */
	public const SCOPE_PROPERTY = 'administrationId';

	/**
	 * App-config key holding the backfill-completeness gate.
	 *
	 * Written ONLY by {@see \OCA\Shillinq\Repair\BackfillGlLineAdministration}
	 * after it has RE-READ every `GLLine` row from the store and counted zero
	 * without a scope; read by {@see \OCA\Shillinq\Service\SpendAnalyticsService}
	 * before it will filter a GL-backed aggregation on `administrationId`.
	 */
	public const GATE_CONFIG_KEY = 'glline_administration_backfill_complete';

	/**
	 * The value the gate must hold to count as green.
	 *
	 * A VERSION, not a boolean, on purpose. The gate's real claim is "every
	 * GLLine row carries a scope, AND every code path that writes one stamps
	 * it". The first half is measured at repair time; the second half is a
	 * property of the CODE, which a stored boolean cannot notice changing. So
	 * whenever a new `GLLine` writer is added (or an existing one is reworked),
	 * bump this constant: every deployment's stored value instantly stops
	 * matching, `SpendAnalyticsService` fails closed again, and the repair step
	 * must re-prove completeness against the new writer set before the views
	 * come back. A boolean would have kept answering "yes" on evidence gathered
	 * before that writer existed.
	 *
	 * Contract v1 covers the writers stamped by `glline-administration-scope`:
	 * CogsPosterService, InventoryGlAdjustmentPoster, RuleTestDataSeeder and
	 * VatSuppletieDetectionService.
	 */
	public const GATE_CONTRACT_VERSION = 'v1';

	/**
	 * Classification: the row already carries a scope; leave it untouched.
	 */
	public const CLASS_ALREADY_SCOPED = 'already-scoped';

	/**
	 * Classification: the row's parent was found and the scope can be stamped.
	 */
	public const CLASS_RESOLVED = 'resolved';

	/**
	 * Classification: no parent could answer for this row. Never guessed.
	 */
	public const CLASS_UNCLASSIFIABLE = 'unclassifiable';

	/**
	 * Index every `GLTransaction` under each identity a `GLLine` may reference.
	 *
	 * `GLLine.transactionId` is populated with the parent's object UUID by most
	 * writers and with its `transactionNumber` business key by at least one
	 * (`RuleTestDataSeeder`). Indexing both — plus the `@self.id` envelope form
	 * OpenRegister returns — is what makes the resolution total rather than
	 * idiom-dependent. A transaction whose own scope is blank is deliberately
	 * NOT indexed: it cannot answer the question, and an entry mapping to `''`
	 * would let {@see classify()} call a row resolved while stamping an empty
	 * scope, which is exactly the fake-completeness failure the gate exists to
	 * catch.
	 *
	 * @param array<int, array<string, mixed>> $glTransactions The parent rows.
	 *
	 * @return array<string, string> Identity → administrationId.
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function indexAdministrationsByTransaction(array $glTransactions): array {
		$index = [];

		foreach ($glTransactions as $transaction) {
			$administrationId = trim((string)($transaction[self::SCOPE_PROPERTY] ?? ''));
			if ($administrationId === '') {
				// Cannot answer for its own lines — index nothing rather than
				// index an empty scope.
				continue;
			}

			foreach ($this->identitiesOf(row: $transaction) as $identity) {
				$index[$identity] = $administrationId;
			}
		}

		return $index;
	}//end indexAdministrationsByTransaction()

	/**
	 * Resolve one `GLLine`'s administration through its `transactionId`.
	 *
	 * @param array<string, mixed> $glLine The persisted line.
	 * @param array<string, string> $index The index from {@see indexAdministrationsByTransaction()}.
	 *
	 * @return string|null The parent's administrationId, or null when unresolvable.
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function resolveAdministrationId(array $glLine, array $index): ?string {
		$transactionId = trim((string)($glLine['transactionId'] ?? ''));
		if ($transactionId === '') {
			return null;
		}

		$resolved = ($index[$transactionId] ?? null);
		if (is_string($resolved) === false || $resolved === '') {
			return null;
		}

		return $resolved;
	}//end resolveAdministrationId()

	/**
	 * Classify one `GLLine` for the backfill.
	 *
	 * @param array<string, mixed> $glLine The persisted line.
	 * @param array<string, string> $index The parent index.
	 *
	 * @return string One of the `CLASS_*` constants.
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function classify(array $glLine, array $index): string {
		if (trim((string)($glLine[self::SCOPE_PROPERTY] ?? '')) !== '') {
			return self::CLASS_ALREADY_SCOPED;
		}

		if ($this->resolveAdministrationId(glLine: $glLine, index: $index) === null) {
			return self::CLASS_UNCLASSIFIABLE;
		}

		return self::CLASS_RESOLVED;
	}//end classify()

	/**
	 * Stamp a resolved administration onto one line, preserving every other field.
	 *
	 * Pure and byte-safe: only `administrationId` is written, and only when the
	 * row does not already carry one — an already-scoped row is returned
	 * unchanged even if the parent now says something else, so a re-run can
	 * never rewrite live bookkeeping scope. A disagreement is REPORTED by
	 * {@see backfillBatch()} instead, because silently re-pointing a posted
	 * line to a different administration is a bigger decision than a backfill
	 * gets to make on its own.
	 *
	 * @param array<string, mixed> $glLine The persisted line.
	 * @param string $administrationId The parent's administrationId.
	 *
	 * @return array<string, mixed> The stamped (or unchanged) line.
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function stampAdministrationId(array $glLine, string $administrationId): array {
		if (trim((string)($glLine[self::SCOPE_PROPERTY] ?? '')) !== '') {
			return $glLine;
		}

		if (trim($administrationId) === '') {
			return $glLine;
		}

		$glLine[self::SCOPE_PROPERTY] = $administrationId;

		return $glLine;
	}//end stampAdministrationId()

	/**
	 * Backfill a batch of `GLLine` rows from their parent `GLTransaction` rows.
	 *
	 * Classifies EVERY row first, then count-verifies before returning
	 * anything: `already-scoped + resolved` MUST equal the number of rows seen,
	 * or {@see assertCountsMatch()} throws and the caller MUST NOT write any of
	 * the returned rows. One unclassifiable line aborts the whole batch,
	 * including the lines that resolved cleanly.
	 *
	 * The returned `lines` are keyed by the SAME integer offsets as
	 * `$glLines`, so a caller can pair each migrated row with the source row it
	 * came from (and hence with its object id) without re-deriving anything.
	 * Only offsets that were actually changed are present.
	 *
	 * @param array<int, array<string, mixed>> $glLines The rows under `GLLine`.
	 * @param array<int, array<string, mixed>> $glTransactions The parent rows.
	 *
	 * @return array{lines: array<int, array<string, mixed>>, total: int, backfilled: int, unchanged: int, unclassifiable: int, conflicting: int}
	 *         The changed rows (by source offset) plus the report counts.
	 *
	 * @throws RuntimeException When any row is unclassifiable (count guard).
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function backfillBatch(array $glLines, array $glTransactions): array {
		$index = $this->indexAdministrationsByTransaction(glTransactions: $glTransactions);

		$changed = [];
		$backfilled = 0;
		$unchanged = 0;
		$unclassifiable = 0;
		$conflicting = 0;

		foreach ($glLines as $offset => $glLine) {
			$classification = $this->classify(glLine: $glLine, index: $index);

			if ($classification === self::CLASS_ALREADY_SCOPED) {
				$unchanged++;
				$parentScope = $this->resolveAdministrationId(glLine: $glLine, index: $index);
				if ($parentScope !== null && $parentScope !== trim((string)$glLine[self::SCOPE_PROPERTY])) {
					// Reported, never rewritten — see stampAdministrationId().
					$conflicting++;
				}

				continue;
			}

			if ($classification === self::CLASS_RESOLVED) {
				$administrationId = (string)$this->resolveAdministrationId(glLine: $glLine, index: $index);
				$changed[$offset] = $this->stampAdministrationId(
					glLine: $glLine,
					administrationId: $administrationId
				);
				$backfilled++;
				continue;
			}

			// Unclassifiable — deliberately NOT counted as classified, so the
			// guard below sees the shortfall and aborts the whole batch.
			$unclassifiable++;
		}//end foreach

		$this->assertCountsMatch(
			sourceCount: count($glLines),
			classifiedCount: ($backfilled + $unchanged)
		);

		return [
			'lines' => $changed,
			'total' => count($glLines),
			'backfilled' => $backfilled,
			'unchanged' => $unchanged,
			'unclassifiable' => $unclassifiable,
			'conflicting' => $conflicting,
		];
	}//end backfillBatch()

	/**
	 * Count `GLLine` rows that still carry no administration.
	 *
	 * THE COMPLETENESS CHECK the whole change turns on (REQ-GLS-003). It is a
	 * TOTAL over every row handed to it, never a spot check or a sample: the
	 * gate it feeds is what tells `SpendAnalyticsService` that switching the
	 * `administrationId` filter on will narrow the totals rather than empty
	 * them, and a sampled "probably zero" is not evidence of that.
	 *
	 * @param array<int, array<string, mixed>> $glLines Every `GLLine` row.
	 *
	 * @return int How many rows lack a non-empty `administrationId`.
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function countMissingAdministrationId(array $glLines): int {
		$missing = 0;

		foreach ($glLines as $glLine) {
			if (trim((string)($glLine[self::SCOPE_PROPERTY] ?? '')) === '') {
				$missing++;
			}
		}

		return $missing;
	}//end countMissingAdministrationId()

	/**
	 * Assert a seen→classified row-count match, aborting on any shortfall.
	 *
	 * Mirrors {@see BudgetSchemaSplitMigrator::assertCountsMatch()}'s
	 * fail-closed contract exactly: any shortfall (caused by even one
	 * unclassifiable row) throws, and the caller MUST NOT write any of the
	 * returned rows when it does — the source data is left intact.
	 *
	 * @param int $sourceCount The number of `GLLine` rows seen.
	 * @param int $classifiedCount The number resolved plus the number already scoped.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the counts differ (no-row-loss guard).
	 *
	 * @spec openspec/changes/glline-administration-scope/specs/glline-administration-scope/spec.md
	 */
	public function assertCountsMatch(int $sourceCount, int $classifiedCount): void {
		if ($sourceCount !== $classifiedCount) {
			throw new RuntimeException(
				sprintf(
					'GLLine administration backfill aborted: %d row(s) seen but only %d classified '
					. '(%d unclassifiable — parent GLTransaction missing or itself unscoped); '
					. 'no row was written, source data left intact.',
					$sourceCount,
					$classifiedCount,
					($sourceCount - $classifiedCount)
				)
			);
		}
	}//end assertCountsMatch()

	/**
	 * Every identity string a `GLTransaction` can be referenced by.
	 *
	 * @param array<string, mixed> $row The transaction row.
	 *
	 * @return array<int, string> The non-empty identities.
	 */
	private function identitiesOf(array $row): array {
		$candidates = [
			($row['id'] ?? null),
			($row['@self']['id'] ?? null),
			($row['uuid'] ?? null),
			($row['transactionNumber'] ?? null),
		];

		$identities = [];
		foreach ($candidates as $candidate) {
			if (is_string($candidate) === false && is_int($candidate) === false) {
				continue;
			}

			$identity = trim((string)$candidate);
			if ($identity === '') {
				continue;
			}

			$identities[$identity] = $identity;
		}

		return array_values($identities);
	}//end identitiesOf()
}//end class
