<?php

/**
 * Hypothetical-schedule arithmetic for the two `RECURRING_*` budget-scenario
 * modifier kinds (REQ-BSC-006).
 *
 * Split out of {@see BudgetScenarioEvaluator}, which owns the base-vs-scenario
 * cell model (LedgerGroup indexing, account-range resolution, parent rollup)
 * and had grown past PHPMD's `ExcessiveClassComplexity` threshold. This class
 * carries the other, separable job: given a real `CashflowRecurring` row and a
 * modifier, build the hypothetical row(s) the modifier implies, expand both
 * through the SHARED expander, and return the per-month difference.
 *
 * Two invariants this class exists to hold:
 *
 *   1. It NEVER performs schedule arithmetic of its own. Every monthly figure —
 *      real and hypothetical alike — comes from
 *      {@see KnownCostScheduleExpanderInterface::expand()}, so a scenario can
 *      never disagree with a regeneration (`design.md` §6a). Slicing a row's
 *      `validFrom`/`validTo`/`standardAmount` and handing it back to the same
 *      expander is the whole technique.
 *   2. It unwraps that expander's TAGGED UNION exactly once, in
 *      {@see self::expandMonths()}. `expand()` returns
 *      `['kind' => 'amounts', 'monthlyCents' => [...]]`, not a month map;
 *      indexing `["01"]` straight off it silently misses every month, which is
 *      the defect this class's extraction accompanied — both RECURRING_* kinds
 *      had been computing 0 − 0 for all twelve months.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use Throwable;

/**
 * Builds and differences the hypothetical schedules a `RECURRING_*` modifier implies.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
 */
final class RecurringModifierExpander {

	/**
	 * Zero-padded month keys, `"01"` through `"12"`, in calendar order.
	 *
	 * @var list<string>
	 */
	private const MONTH_KEYS = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'];

	/**
	 * Construct the expander.
	 *
	 * @param KnownCostScheduleExpanderInterface $scheduleExpander The shared
	 *        budget-known-costs schedule expander. The SAME instance the
	 *        evaluator was given, so `design.md` §6a's "one arithmetic" rule is
	 *        observable in tests through a single call log.
	 */
	public function __construct(
		private readonly KnownCostScheduleExpanderInterface $scheduleExpander,
	) {
	}//end __construct()

	/**
	 * The per-month cent difference one `RECURRING_*` modifier makes.
	 *
	 * @param array<string,mixed> $real       The real CashflowRecurring row.
	 * @param array<string,mixed> $modifier   The modifier row.
	 * @param string              $type       `RECURRING_END` or `RECURRING_AMOUNT_CHANGE`.
	 * @param int                 $fiscalYear The fiscal year being evaluated.
	 *
	 * @return array<int|string,int>|null `"01".."12"` => signed cent delta, or
	 *         null when the schedule is unknowable because the expander asked for
	 *         operator input (REQ-BKC-003). Null means SKIP, not zero: the delta
	 *         is `hypothetical - real`, so treating a missing real schedule as
	 *         all-zero would post the entire hypothetical amount as the change.
	 *
	 *         `int|string` keys, not `string`: the keys come from MONTH_KEYS, and
	 *         PHP coerces a canonical numeric string key to int, so `"01".."09"`
	 *         stay strings while `10`, `11`, `12` are ints.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-006
	 */
	public function monthlyDeltas(array $real, array $modifier, string $type, int $fiscalYear): ?array {
		// The real row is expanded FIRST, before any hypothetical slice. Call
		// ORDER is asserted by
		// BudgetScenarioEvaluatorTest::testRecurringModifierDelegatesEveryScheduleCallToTheSharedExpander,
		// which reads the shared expander's call log positionally to prove both
		// figures come from the same arithmetic (design.md §6a).
		$realMonthly = $this->expandMonths(row: $real, fiscalYear: $fiscalYear);

		$hypotheticalMonthly = null;
		if ($type === 'RECURRING_END') {
			$hypotheticalMonthly = $this->expandRecurringEnd(real: $real, modifier: $modifier, fiscalYear: $fiscalYear);
		}

		if ($type === 'RECURRING_AMOUNT_CHANGE') {
			$hypotheticalMonthly = $this->expandRecurringAmountChange(real: $real, modifier: $modifier, fiscalYear: $fiscalYear);
		}

		if ($realMonthly === null || $hypotheticalMonthly === null) {
			return null;
		}

		$deltas = [];
		foreach (self::MONTH_KEYS as $monthKey) {
			$deltas[$monthKey] = ((int)($hypotheticalMonthly[$monthKey] ?? 0) - (int)($realMonthly[$monthKey] ?? 0));
		}

		return $deltas;

	}//end monthlyDeltas()

	/**
	 * Call the shared expander and unwrap its tagged union into a flat month map.
	 *
	 * @param array<string,mixed> $row        A CashflowRecurring-shaped row (real or hypothetical).
	 * @param int                 $fiscalYear The fiscal year to expand into.
	 *
	 * @return array<int|string,int>|null Month key => cents, or null on `needsOperatorInput`.
	 */
	private function expandMonths(array $row, int $fiscalYear): ?array {
		$expansion = $this->scheduleExpander->expand($row, $fiscalYear, null);
		if ($expansion['kind'] !== 'amounts') {
			return null;
		}

		return ($expansion['monthlyCents'] ?? null);

	}//end expandMonths()

	/**
	 * `RECURRING_END`: one hypothetical row with `validTo` capped at
	 * `effectiveDate` (never later than the row's own real `validTo`).
	 *
	 * @param array<string,mixed> $real       The real CashflowRecurring row.
	 * @param array<string,mixed> $modifier   The RECURRING_END modifier.
	 * @param int                 $fiscalYear The fiscal year being evaluated.
	 *
	 * @return array<int|string,int>|null Month key => cents, or null when the
	 *         expander needs operator input (REQ-BKC-003).
	 */
	private function expandRecurringEnd(array $real, array $modifier, int $fiscalYear): ?array {
		$effectiveDate = (string)($modifier['effectiveDate'] ?? '');
		$realValidTo = null;
		if (($real['validTo'] ?? null) !== null) {
			$realValidTo = (string)$real['validTo'];
		}

		$cappedValidTo = $effectiveDate;
		if ($realValidTo !== null && $this->compareDates(a: $realValidTo, b: $effectiveDate) < 0) {
			// The real row already ends before the hypothetical cap — no change.
			$cappedValidTo = $realValidTo;
		}

		$hypothetical = array_merge($real, ['validTo' => $cappedValidTo]);

		return $this->expandMonths(row: $hypothetical, fiscalYear: $fiscalYear);

	}//end expandRecurringEnd()

	/**
	 * `RECURRING_AMOUNT_CHANGE`: the real amount still applies strictly
	 * BEFORE `effectiveDate`; `newStandardAmount` applies from
	 * `effectiveDate` forward. Sliced into two rows, both expanded through
	 * the shared expander, then summed (design.md §4b — no second
	 * arithmetic).
	 *
	 * @param array<string,mixed> $real       The real CashflowRecurring row.
	 * @param array<string,mixed> $modifier   The RECURRING_AMOUNT_CHANGE modifier.
	 * @param int                 $fiscalYear The fiscal year being evaluated.
	 *
	 * @return array<int|string,int>|null Month key => cents, or null when either
	 *         slice needs operator input (REQ-BKC-003).
	 */
	private function expandRecurringAmountChange(array $real, array $modifier, int $fiscalYear): ?array {
		$effectiveDate = (string)($modifier['effectiveDate'] ?? '');
		$newStandardAmount = $modifier['newStandardAmount'] ?? null;

		$beforeRow = array_merge($real, ['validTo' => $this->dayBefore(date: $effectiveDate)]);
		$afterRow = array_merge($real, ['validFrom' => $effectiveDate, 'standardAmount' => $newStandardAmount]);

		$before = $this->expandMonths(row: $beforeRow, fiscalYear: $fiscalYear);
		$after = $this->expandMonths(row: $afterRow, fiscalYear: $fiscalYear);
		if ($before === null || $after === null) {
			// One slice of the split is unknowable, so their sum is too. Summing
			// the other half alone would understate the row by the missing slice.
			return null;
		}

		$combined = [];
		foreach (self::MONTH_KEYS as $monthKey) {
			$combined[$monthKey] = ((int)($before[$monthKey] ?? 0) + (int)($after[$monthKey] ?? 0));
		}

		return $combined;

	}//end expandRecurringAmountChange()

	/**
	 * Compare two ISO date strings. Returns negative when `$a` is earlier,
	 * positive when later, 0 when equal or unparseable.
	 *
	 * @param string $a The first date.
	 * @param string $b The second date.
	 *
	 * @return int The comparison result.
	 */
	private function compareDates(string $a, string $b): int {
		try {
			$dateA = new DateTimeImmutable($a);
			$dateB = new DateTimeImmutable($b);
		} catch (Throwable) {
			return 0;
		}

		return ($dateA <=> $dateB);

	}//end compareDates()

	/**
	 * The calendar day immediately before an ISO date string.
	 *
	 * @param string $date The ISO date string.
	 *
	 * @return string The previous day, ISO-formatted; `$date` unchanged when unparseable.
	 */
	private function dayBefore(string $date): string {
		try {
			$parsed = new DateTimeImmutable($date);
		} catch (Throwable) {
			return $date;
		}

		return $parsed->modify('-1 day')->format('Y-m-d');

	}//end dayBefore()
}//end class
