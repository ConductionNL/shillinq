<?php

/**
 * BBV Provincie Programme Budget Calculator
 *
 * The arithmetic half of the provincies-BBV Compliance Dashboard (#866/#862):
 * everything REQ-BBC-001..003 says about combining budget, commitment and GL
 * spend into per-programme rows, the four KPI totals, the traffic light, the
 * two chart series, the cumulative monthly trend and the overspend exception
 * list.
 *
 * It reads NOTHING. Every input is passed in, which is what makes the rules
 * testable without an OpenRegister at all: the traffic-light thresholds, the
 * REQ-BBC-002 "no programme selected shows no data" rule and the "a quiet month
 * must not close the gap in the trend line" rule are the parts most likely to
 * be got wrong, and they are exactly the parts a stubbed object store would
 * have hidden behind fixture plumbing. The split mirrors
 * {@see FinancialDashboardService} / {@see FinancialSeriesCalculator}.
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
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure programme budget-vs-actuals arithmetic (REQ-BBC-001..003).
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
class BbvProgrammeBudgetCalculator {
	/**
	 * Remaining-share at or above which a programme is green (REQ-BBC-001).
	 *
	 * @var float
	 */
	public const GREEN_THRESHOLD = 0.15;

	/**
	 * Largest number of months a fiscal-year window may span before the trend
	 * loop gives up. A malformed window must not spin forever.
	 *
	 * @var integer
	 */
	private const MAX_TREND_MONTHS = 120;

	/**
	 * Decide which programmes the envelope reports on.
	 *
	 * @param array<int,string> $universe The declared programme vocabulary.
	 * @param array<int,string>|null $requested The REQ-BBC-002 programme filter;
	 *                                          null = no filter, `[]` = none selected.
	 * @param array<int,string> $seen Programme codes present in the data.
	 *
	 * @return list<string> The programme codes, in the declared order.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function selectedProgrammes(array $universe, ?array $requested, array $seen): array {
		$codes = $universe;
		foreach ($seen as $code) {
			if ($code !== '' && in_array($code, $codes, true) === false) {
				$codes[] = $code;
			}
		}

		// REQ-BBC-002: "Selecting no programme MUST show no data (not all
		// programmes)." ONLY `null` opens the gate. The empty-array case needs
		// no branch of its own — the filter below keeps nothing when nothing
		// is requested — and it deliberately does not have one: an explicit
		// `if ($requested === []) return []` here was written first, and a
		// mutation control proved it was DEAD (breaking it changed no test and
		// no behaviour). A guard that cannot be shown to fail is not a guard;
		// the rule lives in this `=== null` test, which the same control does
		// redden.
		if ($requested === null) {
			return array_values($codes);
		}

		return array_values(
			array_filter(
				$codes,
				static function (string $code) use ($requested): bool {
					return in_array($code, $requested, true);
				}
			)
		);

	}//end selectedProgrammes()

	/**
	 * Build one row per selected programme (REQ-BBC-001).
	 *
	 * @param list<string> $selected Programme codes to report on.
	 * @param array<string,float> $budgetByProgramme Budget sums.
	 * @param array<string,float> $spendByProgramme Spend sums.
	 * @param array<string,float> $committedByProgramme Commitment sums.
	 *
	 * @return list<array<string,mixed>> The per-programme rows.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function buildRows(
		array $selected,
		array $budgetByProgramme,
		array $spendByProgramme,
		array $committedByProgramme,
	): array {
		$rows = [];
		foreach ($selected as $code) {
			$totalBudget = (float)($budgetByProgramme[$code] ?? 0.0);
			$spent = (float)($spendByProgramme[$code] ?? 0.0);
			$committed = (float)($committedByProgramme[$code] ?? 0.0);
			$remaining = ($totalBudget - ($committed + $spent));

			$rows[] = [
				'programmeStructure' => $code,
				'programme' => $this->humanise(code: $code),
				'totalBudget' => $totalBudget,
				'committed' => $committed,
				'spent' => $spent,
				'remaining' => $remaining,
				'overspent' => max(0.0, -$remaining),
				'remainingRatio' => $this->ratio(part: $remaining, whole: $totalBudget),
				'utilisation' => $this->ratio(part: ($committed + $spent), whole: $totalBudget),
				'status' => $this->trafficLight(remaining: $remaining, totalBudget: $totalBudget),
			];
		}

		return $rows;

	}//end buildRows()

	/**
	 * Apply the REQ-BBC-001 traffic-light rule.
	 *
	 * @param float $remaining Remaining budget in EUR.
	 * @param float $totalBudget Total budget in EUR.
	 *
	 * @return string One of `green`, `yellow`, `red`.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function trafficLight(float $remaining, float $totalBudget): string {
		if ($remaining < 0.0) {
			return 'red';
		}

		if ($totalBudget <= 0.0) {
			// No budget and no overspend: nothing to be amber about. Amber
			// here would put every unbudgeted programme permanently at risk.
			return 'green';
		}

		if (($remaining / $totalBudget) >= self::GREEN_THRESHOLD) {
			return 'green';
		}

		return 'yellow';

	}//end trafficLight()

	/**
	 * Aggregate the four REQ-BBC-001 KPI values across the reported programmes.
	 *
	 * @param list<array<string,mixed>> $rows The per-programme rows.
	 *
	 * @return array<string,mixed> The KPI bag.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function totalsFor(array $rows): array {
		$totalBudget = $this->sumOf(rows: $rows, key: 'totalBudget');
		$committed = $this->sumOf(rows: $rows, key: 'committed');
		$spent = $this->sumOf(rows: $rows, key: 'spent');
		$remaining = ($totalBudget - ($committed + $spent));

		return [
			'totalBudget' => $totalBudget,
			'committed' => $committed,
			'spent' => $spent,
			'remaining' => $remaining,
			'remainingRatio' => $this->ratio(part: $remaining, whole: $totalBudget),
			'utilisation' => $this->ratio(part: ($committed + $spent), whole: $totalBudget),
			'status' => $this->trafficLight(remaining: $remaining, totalBudget: $totalBudget),
			'programmeCount' => count($rows),
		];

	}//end totalsFor()

	/**
	 * Shape the per-programme rows as the parallel arrays a chart widget's
	 * `endpointSource` maps onto (`labelsPath` plus one `path` per series).
	 *
	 * @param list<array<string,mixed>> $rows The per-programme rows.
	 *
	 * @return array<string,mixed> Labels, three series and the rows themselves.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function seriesFor(array $rows): array {
		$labels = [];
		$budget = [];
		$spent = [];
		$committed = [];
		foreach ($rows as $row) {
			$labels[] = (string)$row['programme'];
			$budget[] = round((float)$row['totalBudget'], 2);
			$spent[] = round((float)$row['spent'], 2);
			$committed[] = round((float)$row['committed'], 2);
		}

		return [
			'labels' => $labels,
			'budget' => $budget,
			'spent' => $spent,
			'committed' => $committed,
			'rows' => $rows,
		];

	}//end seriesFor()

	/**
	 * Build the cumulative monthly spend trend (REQ-BBC-001 "Trend Chart").
	 *
	 * A month with no GL postings carries the PREVIOUS cumulative value and is
	 * never omitted — the requirement is explicit that a quiet month must show
	 * as flat rather than close the gap in the line.
	 *
	 * @param string $startDate ISO start of the fiscal-year window.
	 * @param string $endDate ISO end of the fiscal-year window.
	 * @param array<string,float> $monthlySpend Spend keyed `YYYY-MM`.
	 * @param boolean $anyProgrammeSelected False when REQ-BBC-002's "no programme
	 *                                      selected" state is active, which must
	 *                                      flatten the line rather than show
	 *                                      unattributed spend.
	 * @param float $totalBudget Total budget, drawn as the flat reference line.
	 *
	 * @return array<string,mixed> Months, cumulative spend and the budget reference.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function trendFor(
		string $startDate,
		string $endDate,
		array $monthlySpend,
		bool $anyProgrammeSelected,
		float $totalBudget,
	): array {
		$months = $this->monthsBetween(startDate: $startDate, endDate: $endDate);

		$cumulative = [];
		$reference = [];
		$running = 0.0;
		foreach ($months as $month) {
			if ($anyProgrammeSelected === true) {
				$running += (float)($monthlySpend[$month] ?? 0.0);
			}

			$cumulative[] = round($running, 2);
			$reference[] = round($totalBudget, 2);
		}

		return [
			'months' => $months,
			'cumulativeSpend' => $cumulative,
			'budgetReference' => $reference,
		];

	}//end trendFor()

	/**
	 * Enumerate the `YYYY-MM` buckets a window spans, inclusive.
	 *
	 * @param string $startDate ISO start date.
	 * @param string $endDate ISO end date.
	 *
	 * @return list<string> The month buckets.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function monthsBetween(string $startDate, string $endDate): array {
		$cursor = substr($startDate, 0, 7);
		$last = substr($endDate, 0, 7);
		if ($cursor === '' || $last === '' || $cursor > $last) {
			return [];
		}

		$months = [];
		$guard = 0;
		while ($cursor <= $last && $guard < self::MAX_TREND_MONTHS) {
			$months[] = $cursor;
			$cursor = $this->nextMonth(month: $cursor);
			$guard++;
		}

		return $months;

	}//end monthsBetween()

	/**
	 * Build the REQ-BBC-003 exception list: overspent programmes, worst first.
	 *
	 * @param list<array<string,mixed>> $rows The per-programme rows.
	 *
	 * @return list<array<string,mixed>> The overspent rows.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function exceptionsFor(array $rows): array {
		$exceptions = array_values(
			array_filter(
				$rows,
				static function (array $row): bool {
					return ((float)$row['remaining'] < 0.0);
				}
			)
		);

		usort(
			$exceptions,
			static function (array $left, array $right): int {
				return ((float)$right['overspent'] <=> (float)$left['overspent']);
			}
		);

		return $exceptions;

	}//end exceptionsFor()

	/**
	 * Turn a lowercase programme or account-type code into a display label.
	 *
	 * @param string $code The code.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function humanise(string $code): string {
		return ucfirst($code);

	}//end humanise()

	/**
	 * Divide safely.
	 *
	 * @param float $part The numerator.
	 * @param float $whole The denominator.
	 *
	 * @return float The ratio, or 0.0 when the denominator is (near) zero.
	 */
	private function ratio(float $part, float $whole): float {
		// Half a cent, not `=== 0.0`: these are money totals accumulated by
		// repeated addition, so a budget that really is zero can land on a
		// residue like 1.0e-13 — and dividing by that reports a utilisation of
		// several trillion percent rather than the "no budget" the row means.
		if (abs($whole) < 0.005) {
			return 0.0;
		}

		return ($part / $whole);

	}//end ratio()

	/**
	 * Sum one numeric key across the rows.
	 *
	 * @param list<array<string,mixed>> $rows The per-programme rows.
	 * @param string $key The key to sum.
	 *
	 * @return float The total.
	 */
	private function sumOf(array $rows, string $key): float {
		$total = 0.0;
		foreach ($rows as $row) {
			$total += (float)($row[$key] ?? 0);
		}

		return $total;

	}//end sumOf()

	/**
	 * Advance a `YYYY-MM` bucket by one month.
	 *
	 * @param string $month The bucket.
	 *
	 * @return string The next bucket.
	 */
	private function nextMonth(string $month): string {
		$year = (int)substr($month, 0, 4);
		$index = (int)substr($month, 5, 2);
		$index++;
		if ($index > 12) {
			$index = 1;
			$year++;
		}

		return sprintf('%04d-%02d', $year, $index);

	}//end nextMonth()
}//end class
