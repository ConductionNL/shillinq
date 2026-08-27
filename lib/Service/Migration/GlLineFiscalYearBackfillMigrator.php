<?php

/**
 * Shillinq GlLineFiscalYearBackfillMigrator
 *
 * Pure migration core for the `GLLine.fiscalYearId` backfill: resolves each
 * line's fiscal year through its parent `GLTransaction` and stamps it onto the
 * line, so line-level roll-ups can group by year.
 *
 * ## Why the property exists at all
 *
 * `GLLine` declared no fiscal-year property. Three segment-P&L aggregations
 * grouped by `GLLine.fiscalYearId` regardless, so every row landed in ONE null
 * bucket — a plausible total rather than an error, which is why it survived.
 * `periodId` was deliberately NOT substituted: a period is a finer grain than
 * a year, so grouping by it would have silently changed what those roll-ups
 * mean instead of fixing them.
 *
 * ## How this differs from the administration backfill
 *
 * {@see GlLineAdministrationBackfillMigrator} aborts the WHOLE batch when one
 * row is unclassifiable, because `administrationId` is a tenant scope: a
 * half-scoped ledger makes a filter return a silent zero, and a wrong number
 * in a bookkeeping total is worse than refusing.
 *
 * `fiscalYearId` is a GROUPING key, not a scope. A line that cannot resolve
 * one is not a leak and does not zero anything — it appears as a null bucket,
 * which is visible in the result. Aborting every resolvable line because one
 * ancient row lost its transaction would therefore trade a visible gap for no
 * backfill at all.
 *
 * So this migrator stamps what resolves and REPORTS what did not, as a count
 * of the whole set rather than a sample. The caller is expected to surface
 * that count: a backfill that silently left rows behind is the same
 * fake-completeness problem in a quieter form.
 *
 * Idempotent and non-destructive: a line that already carries a fiscal year is
 * returned untouched even when the parent now says something else. Re-pointing
 * a posted line to a different year is a bigger decision than a backfill gets
 * to make, so a disagreement is reported rather than resolved.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Migration;

use RuntimeException;

/**
 * Resolves and stamps `GLLine.fiscalYearId` from the parent `GLTransaction`.
 */
class GlLineFiscalYearBackfillMigrator {

	/**
	 * Schema slug of the line rows this migrator writes.
	 *
	 * @var string
	 */
	public const SCHEMA_GL_LINE = 'GLLine';

	/**
	 * Schema slug of the parent rows this migrator reads.
	 *
	 * @var string
	 */
	public const SCHEMA_GL_TRANSACTION = 'GLTransaction';

	/**
	 * The property being backfilled, on both schemas.
	 *
	 * @var string
	 */
	public const YEAR_PROPERTY = 'fiscalYearId';

	/**
	 * The line already carried a fiscal year; left untouched.
	 *
	 * @var string
	 */
	public const CLASS_ALREADY_STAMPED = 'already-stamped';

	/**
	 * The line resolved a fiscal year through its parent transaction.
	 *
	 * @var string
	 */
	public const CLASS_RESOLVED = 'resolved';

	/**
	 * No fiscal year could be resolved. Reported, never fatal.
	 *
	 * @var string
	 */
	public const CLASS_UNRESOLVABLE = 'unresolvable';

	/**
	 * Index every identity a `GLTransaction` answers to against its fiscal year.
	 *
	 * A transaction carrying no fiscal year is NOT indexed. An entry mapping to
	 * `''` would let classify() call a row resolved while stamping an empty
	 * year, which is the fake-completeness failure this whole step exists to
	 * avoid.
	 *
	 * @param array<int, array<string, mixed>> $glTransactions Parent rows.
	 *
	 * @return array<string, string> Identity => fiscal year id.
	 */
	public function indexFiscalYearsByTransaction(array $glTransactions): array {
		$index = [];

		foreach ($glTransactions as $transaction) {
			if (is_array($transaction) === false) {
				continue;
			}

			$fiscalYearId = trim((string)($transaction[self::YEAR_PROPERTY] ?? ''));
			if ($fiscalYearId === '') {
				// Cannot answer for its own lines — index nothing rather than
				// index an empty year.
				continue;
			}

			foreach ($this->identitiesOf(row: $transaction) as $identity) {
				$index[$identity] = $fiscalYearId;
			}
		}

		return $index;
	}//end indexFiscalYearsByTransaction()

	/**
	 * Resolve one line's fiscal year through its `transactionId`.
	 *
	 * @param array<string, mixed>  $glLine One line row.
	 * @param array<string, string> $index  Output of indexFiscalYearsByTransaction().
	 *
	 * @return string|null The fiscal year id, or null when it cannot be resolved.
	 */
	public function resolveFiscalYearId(array $glLine, array $index): ?string {
		$transactionId = trim((string)($glLine['transactionId'] ?? ''));
		if ($transactionId === '') {
			return null;
		}

		$resolved = ($index[$transactionId] ?? null);
		if (is_string($resolved) === false || $resolved === '') {
			return null;
		}

		return $resolved;
	}//end resolveFiscalYearId()

	/**
	 * Classify one line for the backfill.
	 *
	 * @param array<string, mixed>  $glLine One line row.
	 * @param array<string, string> $index  Output of indexFiscalYearsByTransaction().
	 *
	 * @return string One of the CLASS_* constants.
	 */
	public function classify(array $glLine, array $index): string {
		if (trim((string)($glLine[self::YEAR_PROPERTY] ?? '')) !== '') {
			return self::CLASS_ALREADY_STAMPED;
		}

		if ($this->resolveFiscalYearId(glLine: $glLine, index: $index) === null) {
			return self::CLASS_UNRESOLVABLE;
		}

		return self::CLASS_RESOLVED;
	}//end classify()

	/**
	 * Stamp a resolved fiscal year onto one line, preserving every other field.
	 *
	 * Only `fiscalYearId` is written, and only when the row does not already
	 * carry one — so a re-run can never rewrite the year of a posted line.
	 *
	 * @param array<string, mixed> $glLine       One line row.
	 * @param string               $fiscalYearId The resolved fiscal year id.
	 *
	 * @return array<string, mixed> The line, stamped or unchanged.
	 */
	public function stampFiscalYearId(array $glLine, string $fiscalYearId): array {
		if (trim((string)($glLine[self::YEAR_PROPERTY] ?? '')) !== '') {
			return $glLine;
		}

		if (trim($fiscalYearId) === '') {
			return $glLine;
		}

		$glLine[self::YEAR_PROPERTY] = $fiscalYearId;

		return $glLine;
	}//end stampFiscalYearId()

	/**
	 * Backfill a batch of `GLLine` rows from their parent `GLTransaction` rows.
	 *
	 * Every row is classified before anything is returned, and the three class
	 * counts are asserted to sum to the number of rows seen — a row that fell
	 * through the classifier silently would otherwise make the report describe
	 * a smaller set than was actually processed.
	 *
	 * Unlike the administration backfill, an unresolvable row does NOT abort
	 * the batch: it is counted and reported. See the class docblock.
	 *
	 * The returned `lines` are keyed by the SAME integer offsets as `$glLines`,
	 * so a caller can pair each migrated row with the source row it came from
	 * without re-deriving anything. Only changed offsets are present.
	 *
	 * @param array<int, array<string, mixed>> $glLines        The line rows.
	 * @param array<int, array<string, mixed>> $glTransactions The parent rows.
	 *
	 * @return array{lines: array<int, array<string, mixed>>, seen: int, stamped: int,
	 *               alreadyStamped: int, unresolvable: int,
	 *               disagreements: array<int, string>}
	 *
	 * @throws RuntimeException When the class counts do not sum to the rows seen.
	 */
	public function backfillBatch(array $glLines, array $glTransactions): array {
		$index = $this->indexFiscalYearsByTransaction(glTransactions: $glTransactions);

		$changed        = [];
		$disagreements  = [];
		$seen           = 0;
		$stamped        = 0;
		$alreadyStamped = 0;
		$unresolvable   = 0;

		foreach ($glLines as $offset => $glLine) {
			if (is_array($glLine) === false) {
				continue;
			}

			$seen++;
			$class = $this->classify(glLine: $glLine, index: $index);

			if ($class === self::CLASS_ALREADY_STAMPED) {
				$alreadyStamped++;

				// A line that already carries a year is never rewritten, but a
				// parent that now disagrees is worth surfacing: silently
				// keeping either value hides a real inconsistency.
				$parentYear = $this->resolveFiscalYearId(glLine: $glLine, index: $index);
				$ownYear    = trim((string)($glLine[self::YEAR_PROPERTY] ?? ''));
				if ($parentYear !== null && $parentYear !== $ownYear) {
					$disagreements[] = sprintf(
						'line at offset %d carries "%s" while its transaction says "%s"',
						(int)$offset,
						$ownYear,
						$parentYear
					);
				}

				continue;
			}

			if ($class === self::CLASS_UNRESOLVABLE) {
				$unresolvable++;
				continue;
			}

			$fiscalYearId = (string)$this->resolveFiscalYearId(glLine: $glLine, index: $index);
			$changed[$offset] = $this->stampFiscalYearId(
				glLine: $glLine,
				fiscalYearId: $fiscalYearId
			);
			$stamped++;
		}//end foreach

		$this->assertCountsMatch(
			sourceCount: $seen,
			classifiedCount: ($stamped + $alreadyStamped + $unresolvable)
		);

		return [
			'lines'          => $changed,
			'seen'           => $seen,
			'stamped'        => $stamped,
			'alreadyStamped' => $alreadyStamped,
			'unresolvable'   => $unresolvable,
			'disagreements'  => $disagreements,
		];
	}//end backfillBatch()

	/**
	 * Count the rows still carrying no fiscal year.
	 *
	 * A TOTAL over the set handed in, never a sample — the point of this method
	 * is to describe the whole store after the fact, so a caller can report
	 * what the backfill actually left behind rather than what it intended.
	 *
	 * @param array<int, array<string, mixed>> $glLines The line rows.
	 *
	 * @return int How many rows lack a fiscal year.
	 */
	public function countMissingFiscalYearId(array $glLines): int {
		$missing = 0;

		foreach ($glLines as $glLine) {
			if (is_array($glLine) === false) {
				continue;
			}

			if (trim((string)($glLine[self::YEAR_PROPERTY] ?? '')) === '') {
				$missing++;
			}
		}

		return $missing;
	}//end countMissingFiscalYearId()

	/**
	 * Refuse a report that describes fewer rows than were processed.
	 *
	 * @param int $sourceCount     Rows seen.
	 * @param int $classifiedCount Rows accounted for by the three classes.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the two disagree.
	 */
	public function assertCountsMatch(int $sourceCount, int $classifiedCount): void {
		if ($sourceCount === $classifiedCount) {
			return;
		}

		throw new RuntimeException(
			sprintf(
				'GLLine fiscal-year backfill saw %d row(s) but classified %d. '
				.'A row that falls through the classifier makes the report describe '
				.'a smaller set than was processed, so nothing is written.',
				$sourceCount,
				$classifiedCount
			)
		);
	}//end assertCountsMatch()

	/**
	 * Every identity a transaction row may be referenced by.
	 *
	 * `transactionId` on a line has been written as the object uuid, the
	 * OpenRegister `@self.id`, and the human transaction number at different
	 * points, so all of them are indexed rather than guessing which one this
	 * install used.
	 *
	 * @param array<string, mixed> $row One transaction row.
	 *
	 * @return array<int, string> The distinct identities, in order.
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
