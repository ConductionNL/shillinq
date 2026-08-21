<?php

/**
 * Known Cost Schedule Expander
 *
 * The pure arithmetic half of `budget-known-costs` (REQ-BKC-003, REQ-BKC-006,
 * REQ-BKC-010): expands one `CashflowRecurring`-shaped row into its 12
 * monthly cent contributions for a requested fiscal year. Mirrors
 * `BbvProgrammeBudgetCalculator`'s "reads NOTHING" contract exactly — no
 * constructor dependency on `ObjectServiceInterface` or any OpenRegister
 * type, so every scenario here is a plain PHPUnit fixture, no store, no
 * mock (`design.md` §6).
 *
 * ## Monthly granularity, not the 13-week engine's weekly one
 *
 * `BudgetLine` has 12 monthly slots; `CashflowRecurring.dagFromMonth`
 * (day-of-month precision) is irrelevant at this granularity — this class
 * only needs which calendar month(s) a recurrence lands in for the
 * requested fiscal year, never which day. This is a deliberate, narrower
 * re-derivation of the same schedule fields the (unbuilt) 13-week weekly
 * expansion would have needed — `CashflowWeek`/`CashflowForecastHorizon`
 * are not touched (`design.md` §6a).
 *
 * ## Frequency -> months-in-scope
 *
 *  - `MONTHLY`: `standardAmount` books once per in-scope calendar month,
 *    unchanged.
 *  - `QUARTERLY`: `standardAmount` is spread EVENLY across the 3 months of
 *    each quarter inside `[validFrom, validTo] ∩ fiscalYear`
 *    (`standardAmount ÷ 3` per in-scope month) — a stated convention for
 *    begroting planning purposes, not the actual GL posting shape
 *    (`design.md` §6b/§6d).
 *  - `ANNUALLY`: `standardAmount` books whole in `monthOfYear`, when that
 *    month is in scope.
 *  - `WEEKLY`/`FORTNIGHTLY` (REQ-BKC-010, RULING 2 2026-08-20): exact
 *    per-occurrence date enumeration, NEVER an averaged 52/12 or 26/12
 *    factor. The first occurrence is `validFrom` itself; every subsequent
 *    occurrence steps by 7 (`WEEKLY`) or 14 (`FORTNIGHTLY`) days, bounded
 *    by `validTo` when set. Each in-scope month books
 *    `standardAmount × <occurrences landing in that month>` — a
 *    4-Monday month and a 5-Monday month of the same indefinite recurrence
 *    genuinely receive different totals (`design.md` §6d).
 *
 * ## `validFrom`/`validTo` bounding (REQ-BKC-006)
 *
 * A calendar month strictly before `validFrom`'s month, or strictly after
 * `validTo`'s month when `validTo` is set, contributes `0` — not
 * "unprojectable", a genuine zero. `validTo: null` means every month from
 * `validFrom` onward, across every fiscal year, is in scope (`design.md`
 * §6c).
 *
 * ## Indexation (REQ-BKC-003)
 *
 * `FIXED`: `standardAmount` applies unchanged in every in-scope month,
 * regardless of fiscal year. `CPI_PAST_YEAR` with `cpiRatePercent` set:
 * the amount compounds once per calendar year relative to `validFrom`'s
 * own year — `amountForYear(Y) = standardAmount × (1 + cpiRatePercent /
 * 100) ^ (Y - validFromYear)` — applied uniformly to every in-scope month
 * of fiscal year `Y`, integer cents, rounded once per computed monthly
 * value, never mid-chain (`design.md` §6e). For `WEEKLY`/`FORTNIGHTLY`,
 * the same per-year factor is applied to the per-occurrence amount before
 * multiplying by the month's occurrence count (`design.md` §6d point 3).
 * `CPI_PAST_YEAR` with `cpiRatePercent` null is a declared-but-unusable
 * state (a genuine pre-existing gap this change closes minimally,
 * `design.md` §3a/§3b): {@see expand()} returns a typed
 * `needsOperatorInput` result for the WHOLE row rather than silently
 * substituting `FIXED` or a zero rate.
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
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-003
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-006
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;

/**
 * Pure `CashflowRecurring` -> 12-monthly-cents schedule arithmetic.
 * Reads nothing; every input is the caller's own already-loaded row.
 *
 * Declares `implements KnownCostScheduleExpanderInterface` to close the
 * integration seam budget-scenarios declared for it. Until this declaration
 * existed, `BudgetScenarioRegistration` refused to bind the interface and
 * threw by design, so `BudgetScenarioEvaluator` could not evaluate any
 * `RECURRING_*` modifier at all. Both PRs were green in isolation and red
 * only once both were on `development` — the class landed with #967 and the
 * binding with #981, and neither could observe the other.
 *
 * The signature already matched the interface exactly, as that interface's
 * own docblock states; only the declaration was missing.
 *
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-003
 */
class KnownCostScheduleExpander implements KnownCostScheduleExpanderInterface {
	/**
	 * Zero-padded month keys, `"01"` through `"12"`, in calendar order.
	 *
	 * @var list<string>
	 */
	private const MONTH_KEYS = ['01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12'];

	/**
	 * Expand one `CashflowRecurring`-shaped row into its 12 monthly cent
	 * contributions for `$fiscalYear`.
	 *
	 * @param array<string,mixed> $recurring `CashflowRecurring`-shaped array (`frequency`,
	 *                                       `standardAmount`, `validFrom`, `validTo`,
	 *                                       `indexationRule`, `cpiRatePercent`, `monthOfYear`).
	 * @param integer $fiscalYear The calendar year to compute monthly amounts for.
	 * @param array<string,mixed>|null $contract The linked Contract, when `contractReference` is
	 *                                           set — unused by this class's own arithmetic (the
	 *                                           Contract window is enforced by
	 *                                           {@see \OCA\Shillinq\Guard\CashflowRecurringGuard}
	 *                                           at save time, REQ-BKC-002); accepted for the
	 *                                           design's declared public surface / call-site
	 *                                           parity (`design.md` §6).
	 *
	 * @return array{kind:string,monthlyCents?:array<int|string,int>} Either
	 *         `['kind' => 'amounts', 'monthlyCents' => ["01" => int, ..., "12" => int]]` or
	 *         `['kind' => 'needsOperatorInput']` when `indexationRule = CPI_PAST_YEAR` and
	 *         `cpiRatePercent` is null (REQ-BKC-003).
	 *
	 *         `int|string` keys, not `string`: built from MONTH_KEYS `['01' … '12']`,
	 *         and PHP coerces a canonical numeric string key to int, so `"01".."09"`
	 *         stay strings while `10`, `11`, `12` are ints.
	 *
	 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-003
	 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-006
	 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-010
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $contract is documented
	 *     above as accepted for the design's declared public surface / call-site
	 *     parity and is genuinely unused by this pure class's own arithmetic.
	 *     It is deliberately NOT named `$_contract`: the interface calls it
	 *     `$contract`, and this codebase calls with named arguments, so a
	 *     leading-underscore rename here would make `expand(contract: …)` a fatal
	 *     unknown-named-parameter error against the very type the caller holds.
	 *     Psalm reports the divergence as ParamNameMismatch.
	 */
	public function expand(array $recurring, int $fiscalYear, ?array $contract = null): array {
		$indexationRule = (string)($recurring['indexationRule'] ?? 'FIXED');
		$cpiRatePercent = ($recurring['cpiRatePercent'] ?? null);
		if ($indexationRule === 'CPI_PAST_YEAR' && $cpiRatePercent === null) {
			return ['kind' => 'needsOperatorInput'];
		}

		$validFrom = $this->parseDate(value: (string)($recurring['validFrom'] ?? ''));
		if ($validFrom === null) {
			// Malformed input the guard should already have rejected on
			// save — fail closed to an all-zero schedule rather than throw,
			// consistent with this class's pure/no-exceptions contract.
			return ['kind' => 'amounts', 'monthlyCents' => $this->zeroMonths()];
		}

		$validTo = null;
		$validToRaw = ($recurring['validTo'] ?? null);
		if ($validToRaw !== null && $validToRaw !== '') {
			$validTo = $this->parseDate(value: (string)$validToRaw);
		}

		$factor = $this->indexationFactor(
			indexationRule: $indexationRule,
			cpiRatePercent: $cpiRatePercent,
			validFromYear: (int)$validFrom->format('Y'),
			fiscalYear: $fiscalYear
		);

		$standardAmount = (float)($recurring['standardAmount'] ?? 0.0);
		$frequency = (string)($recurring['frequency'] ?? '');

		$monthlyCents = match ($frequency) {
			'MONTHLY' => $this->expandMonthly(
				standardAmount: $standardAmount,
				factor: $factor,
				validFrom: $validFrom,
				validTo: $validTo,
				fiscalYear: $fiscalYear
			),
			'QUARTERLY' => $this->expandQuarterly(
				standardAmount: $standardAmount,
				factor: $factor,
				validFrom: $validFrom,
				validTo: $validTo,
				fiscalYear: $fiscalYear
			),
			'ANNUALLY' => $this->expandAnnually(
				standardAmount: $standardAmount,
				factor: $factor,
				validFrom: $validFrom,
				validTo: $validTo,
				fiscalYear: $fiscalYear,
				monthOfYear: (int)($recurring['monthOfYear'] ?? 0)
			),
			'WEEKLY' => $this->expandByOccurrence(
				standardAmount: $standardAmount,
				factor: $factor,
				validFrom: $validFrom,
				validTo: $validTo,
				fiscalYear: $fiscalYear,
				stepDays: 7
			),
			'FORTNIGHTLY' => $this->expandByOccurrence(
				standardAmount: $standardAmount,
				factor: $factor,
				validFrom: $validFrom,
				validTo: $validTo,
				fiscalYear: $fiscalYear,
				stepDays: 14
			),
			default => $this->zeroMonths(),
		};

		return ['kind' => 'amounts', 'monthlyCents' => $monthlyCents];

	}//end expand()

	/**
	 * The indexation multiplier for `$fiscalYear`.
	 *
	 * `FIXED`: always `1.0`. `CPI_PAST_YEAR` (rate present, the only case
	 * reaching this method — the null-rate case short-circuits in
	 * {@see expand()}): compounds once per calendar year relative to
	 * `validFrom`'s own year (`design.md` §6e).
	 *
	 * @param string $indexationRule `FIXED` or `CPI_PAST_YEAR`.
	 * @param float|int|null $cpiRatePercent The operator-supplied annual rate, when set.
	 * @param integer $validFromYear The calendar year `validFrom` falls in.
	 * @param integer $fiscalYear The fiscal year being computed.
	 *
	 * @return float The multiplier to apply to `standardAmount`.
	 */
	private function indexationFactor(
		string $indexationRule,
		float|int|null $cpiRatePercent,
		int $validFromYear,
		int $fiscalYear
	): float {
		if ($indexationRule !== 'CPI_PAST_YEAR' || $cpiRatePercent === null) {
			return 1.0;
		}

		$yearsElapsed = max(0, ($fiscalYear - $validFromYear));

		return ((1.0 + ((float)$cpiRatePercent / 100.0)) ** $yearsElapsed);

	}//end indexationFactor()

	/**
	 * `MONTHLY`: `standardAmount` books once per in-scope month, unchanged.
	 *
	 * @param float $standardAmount Base amount in EUR for one occurrence.
	 * @param float $factor The indexation multiplier for the fiscal year.
	 * @param DateTimeImmutable $validFrom The row's validFrom.
	 * @param DateTimeImmutable|null $validTo The row's validTo, or null when indefinite.
	 * @param integer $fiscalYear The fiscal year being computed.
	 *
	 * @return array<string,int> `"01".."12" => cents`.
	 */
	private function expandMonthly(
		float $standardAmount,
		float $factor,
		DateTimeImmutable $validFrom,
		?DateTimeImmutable $validTo,
		int $fiscalYear
	): array {
		$months = $this->zeroMonths();
		foreach ($this->inScopeMonths(validFrom: $validFrom, validTo: $validTo, fiscalYear: $fiscalYear) as $monthNumber) {
			$months[$this->monthKey(monthNumber: $monthNumber)] = $this->toCents(euros: ($standardAmount * $factor));
		}

		return $months;

	}//end expandMonthly()

	/**
	 * `QUARTERLY`: `standardAmount` is spread evenly across the 3 months of
	 * each quarter that are in scope — `standardAmount ÷ 3` per in-scope
	 * month (`design.md` §6b/§6d).
	 *
	 * @param float $standardAmount Base amount in EUR for one occurrence.
	 * @param float $factor The indexation multiplier for the fiscal year.
	 * @param DateTimeImmutable $validFrom The row's validFrom.
	 * @param DateTimeImmutable|null $validTo The row's validTo, or null when indefinite.
	 * @param integer $fiscalYear The fiscal year being computed.
	 *
	 * @return array<string,int> `"01".."12" => cents`.
	 */
	private function expandQuarterly(
		float $standardAmount,
		float $factor,
		DateTimeImmutable $validFrom,
		?DateTimeImmutable $validTo,
		int $fiscalYear
	): array {
		$months = $this->zeroMonths();
		$perMonth = (($standardAmount * $factor) / 3.0);
		foreach ($this->inScopeMonths(validFrom: $validFrom, validTo: $validTo, fiscalYear: $fiscalYear) as $monthNumber) {
			$months[$this->monthKey(monthNumber: $monthNumber)] = $this->toCents(euros: $perMonth);
		}

		return $months;

	}//end expandQuarterly()

	/**
	 * `ANNUALLY`: `standardAmount` books whole in `monthOfYear`, when that
	 * month is in scope.
	 *
	 * @param float $standardAmount Base amount in EUR for one occurrence.
	 * @param float $factor The indexation multiplier for the fiscal year.
	 * @param DateTimeImmutable $validFrom The row's validFrom.
	 * @param DateTimeImmutable|null $validTo The row's validTo, or null when indefinite.
	 * @param integer $fiscalYear The fiscal year being computed.
	 * @param integer $monthOfYear The anchor month, 1-12.
	 *
	 * @return array<string,int> `"01".."12" => cents`.
	 */
	private function expandAnnually(
		float $standardAmount,
		float $factor,
		DateTimeImmutable $validFrom,
		?DateTimeImmutable $validTo,
		int $fiscalYear,
		int $monthOfYear
	): array {
		$months = $this->zeroMonths();
		if ($monthOfYear < 1 || $monthOfYear > 12) {
			return $months;
		}

		$inScope = $this->inScopeMonths(validFrom: $validFrom, validTo: $validTo, fiscalYear: $fiscalYear);
		if (in_array($monthOfYear, $inScope, true) === false) {
			return $months;
		}

		$months[$this->monthKey(monthNumber: $monthOfYear)] = $this->toCents(euros: ($standardAmount * $factor));

		return $months;

	}//end expandAnnually()

	/**
	 * `WEEKLY`/`FORTNIGHTLY` (REQ-BKC-010): enumerate the row's actual
	 * occurrence dates — first occurrence `validFrom`, stepping by
	 * `$stepDays`, bounded by `validTo` when set — and book
	 * `standardAmount × <occurrences in that month>` per in-scope month.
	 * NEVER an averaged 52/12 or 26/12 factor.
	 *
	 * @param float $standardAmount Base amount in EUR for one occurrence.
	 * @param float $factor The indexation multiplier for the fiscal year (applied
	 *                      per-occurrence, before the monthly sum — `design.md` §6d point 3).
	 * @param DateTimeImmutable $validFrom The row's validFrom (the first occurrence).
	 * @param DateTimeImmutable|null $validTo The row's validTo, or null when indefinite.
	 * @param integer $fiscalYear The fiscal year being computed.
	 * @param integer $stepDays 7 for WEEKLY, 14 for FORTNIGHTLY.
	 *
	 * @return array<string,int> `"01".."12" => cents`.
	 */
	private function expandByOccurrence(
		float $standardAmount,
		float $factor,
		DateTimeImmutable $validFrom,
		?DateTimeImmutable $validTo,
		int $fiscalYear,
		int $stepDays
	): array {
		$months = $this->zeroMonths();
		$occurrenceCounts = $this->occurrenceCountsByMonth(
			validFrom: $validFrom,
			validTo: $validTo,
			fiscalYear: $fiscalYear,
			stepDays: $stepDays
		);

		$perOccurrence = ($standardAmount * $factor);
		foreach ($occurrenceCounts as $monthNumber => $count) {
			if ($count <= 0) {
				continue;
			}

			$months[$this->monthKey(monthNumber: $monthNumber)] = $this->toCents(euros: ($perOccurrence * $count));
		}

		return $months;

	}//end expandByOccurrence()

	/**
	 * Count how many exact occurrence dates (starting at `$validFrom`,
	 * stepped by `$stepDays`, bounded by `$validTo` when set) fall inside
	 * each calendar month of `$fiscalYear`.
	 *
	 * @param DateTimeImmutable $validFrom The first occurrence.
	 * @param DateTimeImmutable|null $validTo The last permitted occurrence date, or null.
	 * @param integer $fiscalYear The fiscal year to bucket occurrences into.
	 * @param integer $stepDays The step, in days, between occurrences.
	 *
	 * @return array<int,int> Calendar month (1-12) => occurrence count.
	 */
	private function occurrenceCountsByMonth(
		DateTimeImmutable $validFrom,
		?DateTimeImmutable $validTo,
		int $fiscalYear,
		int $stepDays
	): array {
		$counts = array_fill_keys(range(1, 12), 0);

		$yearStart = new DateTimeImmutable($fiscalYear . '-01-01');
		$yearEnd = new DateTimeImmutable($fiscalYear . '-12-31');

		$current = $validFrom;
		while ($current <= $yearEnd) {
			if ($validTo !== null && $current > $validTo) {
				break;
			}

			if ($current >= $yearStart) {
				$counts[(int)$current->format('n')]++;
			}

			$current = $current->modify('+' . $stepDays . ' days');
		}

		return $counts;

	}//end occurrenceCountsByMonth()

	/**
	 * The calendar months (1-12) of `$fiscalYear` that fall inside
	 * `[validFrom, validTo]` (REQ-BKC-006). Compared by `(year, month)`
	 * pairs, not by day, since this class only needs month granularity.
	 *
	 * @param DateTimeImmutable $validFrom The row's validFrom.
	 * @param DateTimeImmutable|null $validTo The row's validTo, or null when indefinite.
	 * @param integer $fiscalYear The fiscal year being computed.
	 *
	 * @return list<int> The in-scope calendar months, ascending.
	 */
	private function inScopeMonths(DateTimeImmutable $validFrom, ?DateTimeImmutable $validTo, int $fiscalYear): array {
		$validFromKey = (((int)$validFrom->format('Y') * 12) + (int)$validFrom->format('n'));
		$validToKey = null;
		if ($validTo !== null) {
			$validToKey = (((int)$validTo->format('Y') * 12) + (int)$validTo->format('n'));
		}

		$months = [];
		for ($month = 1; $month <= 12; $month++) {
			$monthKey = (($fiscalYear * 12) + $month);
			if ($monthKey < $validFromKey) {
				continue;
			}

			if ($validToKey !== null && $monthKey > $validToKey) {
				continue;
			}

			$months[] = $month;
		}

		return $months;

	}//end inScopeMonths()

	/**
	 * Convert a EUR amount to integer cents, rounded once.
	 *
	 * @param float $euros The amount in EUR.
	 *
	 * @return integer The amount in integer cents.
	 */
	private function toCents(float $euros): int {
		return (int)round(($euros * 100.0));

	}//end toCents()

	/**
	 * An all-zero 12-month cents array.
	 *
	 * Keyed by `int|string`, not `string`: PHP coerces a canonical numeric
	 * string array key to an integer, so `array_fill_keys(self::MONTH_KEYS, 0)`
	 * yields STRING keys for `"01".."09"` (the leading zero makes them
	 * non-canonical) and INT keys for `10`, `11`, `12`. One array, two key
	 * types. Lookups by `"10"` still work — the lookup coerces the same way —
	 * but a consumer that tests `is_string($key)` will be wrong for a quarter
	 * of the year.
	 *
	 * @return array<int|string,int> `"01".."12" => 0`.
	 */
	private function zeroMonths(): array {
		return array_fill_keys(self::MONTH_KEYS, 0);

	}//end zeroMonths()

	/**
	 * Resolve the `monthNN` key for a 1-12 calendar month.
	 *
	 * @param integer $monthNumber The calendar month, 1-12.
	 *
	 * @return string The zero-padded key (`"01"`.."12"`); `"01"` for any out-of-range input.
	 */
	private function monthKey(int $monthNumber): string {
		$index = ($monthNumber - 1);
		if ($index < 0 || $index > 11) {
			return self::MONTH_KEYS[0];
		}

		return self::MONTH_KEYS[$index];

	}//end monthKey()

	/**
	 * Parse an ISO date string into an immutable date, or null on failure.
	 *
	 * @param string $value The date string.
	 *
	 * @return DateTimeImmutable|null The parsed date, or null when unparseable/empty.
	 */
	private function parseDate(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Throwable) {
			return null;
		}

	}//end parseDate()
}//end class
