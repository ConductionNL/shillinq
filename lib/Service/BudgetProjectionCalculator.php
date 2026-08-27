<?php

/**
 * Budget Projection Calculator
 *
 * The arithmetic half of the `budget-projection-engine` change: the exact
 * growth-rate arithmetic, its degenerate-case rules, the account-level vs.
 * group-level decision, the actual/projected seam, and both trend and
 * cumulative series construction (`design.md` §1-§6, REQ-BPE-001..010).
 *
 * It reads NOTHING. Every input is passed in as plain data — mirroring
 * {@see BbvProgrammeBudgetCalculator}'s "it reads NOTHING" contract exactly
 * — which is what makes the step-exclusion table, the minimum-data floor,
 * the outlier trim and the actual/projected seam testable without an
 * OpenRegister at all. {@see BudgetProjectionReader} is the only class in
 * this change that talks to the store.
 *
 * ## The `validSteps` count reported alongside a rate is the PRE-TRIM count
 *
 * `growthRate()` reports `validSteps` as the number of steps that passed
 * the §2b inclusion test — BEFORE the §2e outlier trim removes the single
 * highest and lowest. Trimming is a distinct, later operation on an
 * already-valid step set, not a second validity test: a caller asking "how
 * much history backs this rate" wants the total number of usable
 * month-over-month comparisons found, with the trim recorded separately
 * (auditable by inspection — "11 valid steps, top and bottom trimmed" is a
 * more complete answer than "9 valid steps" would be on its own).
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
 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;

/**
 * Pure growth-rate, extrapolation, seam and roll-up arithmetic (REQ-BPE-001..010).
 *
 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md
 *
 * @SuppressWarnings(PHPMD.ShortVariable) `extrapolate()`'s `$v0` parameter
 * name is the literal public surface `tasks.md`/`design.md` §2g mandate
 * ("`extrapolate(v0: int, rate: float, k: int): int`", "V₀") — renaming it
 * would break the documented named-argument contract other specs cite.
 */
class BudgetProjectionCalculator {
	/**
	 * Below this many included pairwise steps, an account is unprojectable
	 * (REQ-BPE-004, `design.md` §2d).
	 *
	 * @var integer
	 */
	public const MIN_VALID_STEPS = 3;

	/**
	 * At this many included steps or more, the single highest and single
	 * lowest are trimmed before averaging (REQ-BPE-002, `design.md` §2e).
	 *
	 * @var integer
	 */
	public const OUTLIER_TRIM_MIN_STEPS = 5;

	/**
	 * A projection never looks further ahead than one fiscal year
	 * (REQ-BPE-005, `design.md` §2g).
	 *
	 * @var integer
	 */
	public const PROJECTION_HORIZON_MONTHS = 12;

	/**
	 * Select the quantity to project for an account type (REQ-BPE-001,
	 * `design.md` §1): closing balance for the three stock types, net
	 * movement for the two flow types.
	 *
	 * @param string $accountType One of `assets`, `liabilities`, `equity`, `revenue`, `expenses`.
	 *
	 * @return string Either `closingBalance` or `netMovement`.
	 *
	 * @throws InvalidArgumentException When `$accountType` is not one of the five declared values.
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-001
	 */
	public function projectionMetric(string $accountType): string {
		return match ($accountType) {
			'assets', 'liabilities', 'equity' => 'closingBalance',
			'revenue', 'expenses' => 'netMovement',
			default => throw new InvalidArgumentException(
				'BudgetProjectionCalculator: unknown accountType "' . $accountType . '".'
			),
		};

	}//end projectionMetric()

	/**
	 * Turn an ordered (oldest to newest) list of monthly net-movement cents
	 * into the metric series `growthRate()`/`extrapolate()` operate on
	 * (`design.md` §1, §7b).
	 *
	 * For `netMovement`, the series is the input, unchanged. For
	 * `closingBalance`, each value is the RUNNING carry-forward sum of the
	 * net movements up to and including that month, seeded at `0` for the
	 * window's first available month — the `TrialBalanceService`
	 * "assumed 0 at first period" convention (`design.md` §7b), reused here
	 * because no earlier data exists within the resolved window to source a
	 * true opening balance from.
	 *
	 * @param list<int> $orderedNetMovementCents Monthly net-movement cents, oldest to newest.
	 * @param string $metric Either `closingBalance` or `netMovement` ({@see projectionMetric()}).
	 *
	 * @return list<int> The metric series, same length and order as the input.
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-001
	 */
	public function metricSeries(array $orderedNetMovementCents, string $metric): array {
		if ($metric === 'netMovement') {
			return array_values($orderedNetMovementCents);
		}

		$running = 0;
		$series = [];
		foreach ($orderedNetMovementCents as $net) {
			$running += $net;
			$series[] = $running;
		}

		return $series;

	}//end metricSeries()

	/**
	 * Compute the mean pairwise growth rate over an ordered (oldest to
	 * newest) metric series (REQ-BPE-002, `design.md` §2b-§2f).
	 *
	 * For each consecutive pair `(v_{i-1}, v_i)`:
	 *  - both `0` -> included as `g = 0` (a flat rate is real and computable);
	 *  - `v_{i-1} = 0`, `v_i != 0` -> EXCLUDED (division by zero — "growing
	 *    from nothing" is a change of state, not a percentage);
	 *  - `v_{i-1} != 0`, `v_i = 0` -> included as `g = -1.0` (a fully
	 *    computable -100% rate — this asymmetry with the row above is
	 *    deliberate, `design.md` §2b);
	 *  - opposite non-zero signs -> EXCLUDED (a ratio between a positive and
	 *    a negative value is mathematically defined but not a meaningful
	 *    percentage anyone can act on);
	 *  - same non-zero sign -> included as `(v_i / v_{i-1}) - 1`.
	 *
	 * Below `MIN_VALID_STEPS` included steps, the series is unprojectable
	 * (REQ-BPE-004). At `OUTLIER_TRIM_MIN_STEPS` or more included steps,
	 * the single highest and single lowest are dropped before averaging
	 * (REQ-BPE-002, `design.md` §2e) — a fixed "trim one from each end",
	 * not a percentile-based trim, so which two months were dropped is
	 * always answerable by inspection.
	 *
	 * @param list<int> $values The metric series, oldest to newest (up to 12 values).
	 *
	 * @return array{rate: float, validSteps: int}|array{reason: string, validSteps: int}
	 *         A projectable result, or an `unprojectable` one carrying the
	 *         actual valid-step count — NEVER a fabricated rate
	 *         (REQ-BPE-004).
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-002
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-004
	 */
	public function growthRate(array $values): array {
		$steps = [];
		$count = count($values);
		for ($i = 1; $i < $count; $i++) {
			$previous = $values[$i - 1];
			$current = $values[$i];

			if ($previous === 0 && $current === 0) {
				// Flat at zero is a real, computable rate.
				$steps[] = 0.0;
				continue;
			}

			if ($previous === 0) {
				// $current !== 0 here: growing "from nothing" has no
				// percentage — division by zero, excluded.
				continue;
			}

			$previousIsPositive = ($previous > 0);
			$currentIsPositive = ($current > 0);
			if ($current !== 0 && $currentIsPositive !== $previousIsPositive) {
				// Opposite non-zero signs: a mathematically defined but
				// meaningless ratio (REQ-BPE-002's sign-flip exclusion).
				continue;
			}

			// Either same-sign non-zero pair, or a non-zero base going to
			// zero (a fully computable -100% rate) — both included.
			$steps[] = (($current / $previous) - 1.0);
		}//end for

		$validSteps = count($steps);
		if ($validSteps < self::MIN_VALID_STEPS) {
			return ['reason' => 'insufficient-data', 'validSteps' => $validSteps];
		}

		$forAveraging = $steps;
		if ($validSteps >= self::OUTLIER_TRIM_MIN_STEPS) {
			sort($forAveraging);
			array_shift($forAveraging);
			array_pop($forAveraging);
		}

		$rate = (array_sum($forAveraging) / count($forAveraging));

		return ['rate' => $rate, 'validSteps' => $validSteps];

	}//end growthRate()

	/**
	 * Compound a single mean growth rate forward from the last actual value
	 * (REQ-BPE-005, `design.md` §2g).
	 *
	 * `projected(k) = round_cents(v0 * (1 + rate)^k)` — one rate, applied
	 * uniformly at every offset, never re-estimated per step. Rounded to
	 * the nearest cent only at the point the value is returned (PHP's
	 * default `round()`, half-away-from-zero); the ratio itself is never
	 * rounded mid-chain, so compounding does not accumulate rounding drift.
	 *
	 * @param integer $v0 The last actual value, in integer EUR cents.
	 * @param float $rate The mean growth rate (`growthRate()['rate']`), as a ratio (`0.02` = 2%).
	 * @param integer $k The projected month offset beyond the last actual month (`k >= 1`).
	 *
	 * @return integer The projected value, in integer EUR cents, rounded half-away-from-zero.
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-005
	 */
	public function extrapolate(int $v0, float $rate, int $k): int {
		return (int)round($v0 * ((1.0 + $rate) ** $k));

	}//end extrapolate()

	/**
	 * Resolve the actual/projected/unprojectable seam for one account and
	 * one calendar month (REQ-BPE-006, `design.md` §4).
	 *
	 * `lastActualMonth` is resolved PER ACCOUNT by the caller (never a
	 * single global cutover applied to every account in a request) — this
	 * method itself has no notion of "the" cutover, only "this account's"
	 * cutover, which is what makes REQ-BPE-006's "the seam is per-account"
	 * scenario true: calling this twice with two different
	 * `$lastActualMonth` values for the same `$month` is the whole test.
	 *
	 * An actual value ALWAYS wins: a month with real GL data is never
	 * blended with, or overridden by, a projection — including a
	 * late-posted correction for a month that would otherwise fall inside
	 * the projection horizon.
	 *
	 * @param boolean $hasActual Whether an actual value exists for `$month` on this account.
	 * @param string $month The `YYYY-MM` month being resolved.
	 * @param string|null $lastActualMonth This account's own last actual month (`YYYY-MM`), or null when it has none.
	 *
	 * @return string One of `actual`, `projected`, `unprojectable`.
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-006
	 */
	public function seam(bool $hasActual, string $month, ?string $lastActualMonth): string {
		if ($hasActual === true) {
			return 'actual';
		}

		if ($lastActualMonth !== null && $month <= $lastActualMonth) {
			// No trailing data reaches this far back: an in-window
			// historical gap (§2c's "absent"), not a future month.
			return 'unprojectable';
		}

		return 'projected';

	}//end seam()

	/**
	 * Compute one month offset's cumulative series value, given the trend
	 * built so far (REQ-BPE-008, `design.md` §6).
	 *
	 * For flow accounts (`revenue`/`expenses`), `cumulative` is a
	 * continuous fiscal-year-to-date running sum of the `trend` series —
	 * actual where actual, projected where projected, an `unprojectable`
	 * month contributing `0` to the running total (the same "contributes 0,
	 * never withheld" convention `design.md` §5c applies to a group's
	 * unprojectable MEMBER, reused here for a single account's own
	 * unprojectable MONTH).
	 *
	 * For stock accounts (`assets`/`liabilities`/`equity`), `cumulative`
	 * MUST equal `trend` exactly — a closing balance already IS a running
	 * position by construction (REQ-TB-003); summing it across months
	 * would double-count the carried balance, so this deliberately does
	 * NOT sum for stock types.
	 *
	 * @param list<array{kind:string,amount?:int,reason?:string}> $trend The trend series, oldest to newest, one typed result per month.
	 * @param string $accountType The account's type ({@see projectionMetric()}).
	 *
	 * @return list<int> The cumulative series, in EUR cents, same length and order as `$trend`.
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-008
	 */
	public function cumulative(array $trend, string $accountType): array {
		$metric = $this->projectionMetric(accountType: $accountType);

		if ($metric === 'closingBalance') {
			// Stock: a direct copy-through, never re-summed.
			return array_map(
				static function (array $item): int {
					return (int)($item['amount'] ?? 0);
				},
				$trend
			);
		}

		$running = 0;
		$out = [];
		foreach ($trend as $item) {
			$running += (int)($item['amount'] ?? 0);
			$out[] = $running;
		}

		return $out;

	}//end cumulative()

	/**
	 * Sum a `LedgerGroup`'s resolved members' typed results for one month
	 * into the group's own typed result (REQ-BPE-007, `design.md` §5c).
	 *
	 * Each member contributes its own amount when `actual` or `projected`,
	 * and `0` when `unprojectable` — the group result is tagged
	 * `partial: true` whenever any contributing member was `unprojectable`,
	 * and is itself only `unprojectable` when EVERY resolved member is
	 * (a narrower, more permissive rule than the per-account
	 * `MIN_VALID_STEPS` floor, deliberately: a verzamelpost usually has
	 * more members than any one account has months of history).
	 *
	 * @param list<array{kind:string,amount?:int,reason?:string,validSteps?:int}> $members One typed result per resolved
	 *        member account, for the same month.
	 *
	 * @return array{kind:string,amount?:int,partial?:bool,reason?:string} The group's own typed result for that month.
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-007
	 */
	public function groupProjected(array $members): array {
		if ($members === []) {
			return ['kind' => 'unprojectable', 'reason' => 'no-history', 'validSteps' => 0];
		}

		$sum = 0;
		$anyUnprojectable = false;
		$anyContributing = false;
		foreach ($members as $member) {
			if (($member['kind'] ?? '') === 'unprojectable') {
				$anyUnprojectable = true;
				continue;
			}

			$anyContributing = true;
			$sum += (int)($member['amount'] ?? 0);
		}

		if ($anyContributing === false) {
			return ['kind' => 'unprojectable', 'reason' => 'insufficient-data', 'validSteps' => 0];
		}

		return ['kind' => 'projected', 'amount' => $sum, 'partial' => $anyUnprojectable];

	}//end groupProjected()

	/**
	 * Advance a `YYYY-MM` bucket by one calendar month (calendar utility,
	 * mirroring {@see BbvProgrammeBudgetCalculator::nextMonth()}'s own
	 * precedent for keeping calendar-month arithmetic beside the rest of a
	 * pure calculator rather than duplicating it in the reader or service).
	 *
	 * @param string $month The `YYYY-MM` bucket.
	 *
	 * @return string The next bucket.
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-005
	 */
	public function nextMonth(string $month): string {
		$year = (int)substr($month, 0, 4);
		$index = (int)substr($month, 5, 2);
		$index++;
		if ($index > 12) {
			$index = 1;
			$year++;
		}

		return sprintf('%04d-%02d', $year, $index);

	}//end nextMonth()

	/**
	 * The whole-month offset from one `YYYY-MM` bucket to another
	 * (calendar utility; the `$k` `extrapolate()` needs when a caller is
	 * walking a horizon of future months rather than stepping one month at
	 * a time).
	 *
	 * @param string $fromMonth The base `YYYY-MM` bucket (typically an account's `lastActualMonth`).
	 * @param string $toMonth The target `YYYY-MM` bucket.
	 *
	 * @return integer The number of whole months `$toMonth` is after `$fromMonth` (may be negative).
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-005
	 */
	public function monthOffset(string $fromMonth, string $toMonth): int {
		$fromYear = (int)substr($fromMonth, 0, 4);
		$fromIndex = (int)substr($fromMonth, 5, 2);
		$toYear = (int)substr($toMonth, 0, 4);
		$toIndex = (int)substr($toMonth, 5, 2);

		return ((($toYear - $fromYear) * 12) + ($toIndex - $fromIndex));

	}//end monthOffset()
}//end class
