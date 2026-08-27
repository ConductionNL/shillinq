<?php

/**
 * Unit tests for KnownCostScheduleExpander.
 *
 * Covers `budget-known-costs` REQ-BKC-003/006/010: frequency-to-months
 * expansion (monthly/quarterly/annually unchanged; weekly/fortnightly exact
 * occurrence-date enumeration per RULING 2), validFrom/validTo bounding
 * including the indefinite case, and CPI compounding including the
 * needs-operator-input state.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
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
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\KnownCostScheduleExpander;
use PHPUnit\Framework\TestCase;

/**
 * Tests for KnownCostScheduleExpander::expand().
 */
final class KnownCostScheduleExpanderTest extends TestCase {

	/**
	 * The expander under test.
	 *
	 * @var KnownCostScheduleExpander
	 */
	private KnownCostScheduleExpander $expander;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->expander = new KnownCostScheduleExpander();

	}//end setUp()

	/**
	 * A CPI-indexed recurring cost compounds once per calendar year,
	 * relative to validFrom's own year, applied uniformly to every in-scope
	 * month of the requested fiscal year (REQ-BKC-003).
	 *
	 * @return void
	 */
	public function testCpiIndexationCompoundsAnnually(): void {
		$recurring = [
			'standardAmount' => 1000.0,
			'indexationRule' => 'CPI_PAST_YEAR',
			'cpiRatePercent' => 2.0,
			'validFrom' => '2026-01-01',
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
		];

		$result = $this->expander->expand($recurring, 2028);

		self::assertSame('amounts', $result['kind']);
		// Round(1000 * 1.02^2) = 1040.4 -> 104040 cents, identical across
		// every month of fiscal year 2028.
		foreach (array_keys($result['monthlyCents']) as $monthKey) {
			self::assertSame(104040, $result['monthlyCents'][$monthKey], "month {$monthKey}");
		}

	}//end testCpiIndexationCompoundsAnnually()

	/**
	 * CPI indexation with no operator-supplied rate returns a typed
	 * needsOperatorInput result, never a computed number (REQ-BKC-003).
	 *
	 * @return void
	 */
	public function testCpiWithoutRateNeedsOperatorInput(): void {
		$recurring = [
			'standardAmount' => 1000.0,
			'indexationRule' => 'CPI_PAST_YEAR',
			'cpiRatePercent' => null,
			'validFrom' => '2026-01-01',
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
		];

		$result = $this->expander->expand($recurring, 2028);

		self::assertSame(['kind' => 'needsOperatorInput'], $result);

	}//end testCpiWithoutRateNeedsOperatorInput()

	/**
	 * An indefinite recurring cost (validTo null) is budgeted every year
	 * with no end (REQ-BKC-006).
	 *
	 * @return void
	 */
	public function testIndefiniteValidToNeverTerminates(): void {
		$recurring = [
			'standardAmount' => 50.0,
			'indexationRule' => 'FIXED',
			'validFrom' => '2024-01-01',
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
		];

		$result = $this->expander->expand($recurring, 2030);

		self::assertSame('amounts', $result['kind']);
		foreach (array_keys($result['monthlyCents']) as $monthKey) {
			self::assertGreaterThan(0, $result['monthlyCents'][$monthKey], "month {$monthKey}");
		}

	}//end testIndefiniteValidToNeverTerminates()

	/**
	 * A cost starting mid-year is budgeted only from its start month
	 * (REQ-BKC-006).
	 *
	 * @return void
	 */
	public function testMidYearStartBudgetsOnlyFromStartMonth(): void {
		$recurring = [
			'standardAmount' => 100.0,
			'indexationRule' => 'FIXED',
			'validFrom' => '2027-04-01',
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
		];

		$result = $this->expander->expand($recurring, 2027);

		self::assertSame(0, $result['monthlyCents']['01']);
		self::assertSame(0, $result['monthlyCents']['02']);
		self::assertSame(0, $result['monthlyCents']['03']);
		self::assertSame(10000, $result['monthlyCents']['04']);
		self::assertSame(10000, $result['monthlyCents']['12']);

	}//end testMidYearStartBudgetsOnlyFromStartMonth()

	/**
	 * A quarterly recurring cost spreads its amount evenly across the 3
	 * months of each in-scope quarter (design.md §6b/§6d).
	 *
	 * @return void
	 */
	public function testQuarterlySpreadsEvenlyAcrossQuarterMonths(): void {
		$recurring = [
			'standardAmount' => 300.0,
			'indexationRule' => 'FIXED',
			'validFrom' => '2027-01-01',
			'frequency' => 'QUARTERLY',
		];

		$result = $this->expander->expand($recurring, 2027);

		self::assertSame(10000, $result['monthlyCents']['01']);
		self::assertSame(10000, $result['monthlyCents']['02']);
		self::assertSame(10000, $result['monthlyCents']['03']);
		self::assertSame(10000, $result['monthlyCents']['12']);

	}//end testQuarterlySpreadsEvenlyAcrossQuarterMonths()

	/**
	 * An annual recurring cost books whole in its anchor month
	 * (monthOfYear), zero elsewhere.
	 *
	 * @return void
	 */
	public function testAnnuallyBooksWholeInAnchorMonth(): void {
		$recurring = [
			'standardAmount' => 620.0,
			'indexationRule' => 'FIXED',
			'validFrom' => '2024-07-01',
			'frequency' => 'ANNUALLY',
			'monthOfYear' => 7,
			'dagFromMonth' => 1,
		];

		$result = $this->expander->expand($recurring, 2026);

		self::assertSame(62000, $result['monthlyCents']['07']);
		self::assertSame(0, $result['monthlyCents']['01']);
		self::assertSame(0, $result['monthlyCents']['12']);

	}//end testAnnuallyBooksWholeInAnchorMonth()

	/**
	 * REQ-BKC-010: a calendar month containing 5 weekly occurrences (5
	 * Mondays) books 5x the per-occurrence amount, not an averaged 52/12
	 * factor (433) and not a flat x4 approximation (400).
	 *
	 * @return void
	 */
	public function testMonthWithFiveWeeklyOccurrencesSumsAllFive(): void {
		$recurring = [
			'standardAmount' => 100.0,
			'indexationRule' => 'FIXED',
			'validFrom' => '2027-01-04', // A Monday.
			'frequency' => 'WEEKLY',
		];

		// March 2027 contains 5 Mondays on/after 2027-01-04: 01, 08, 15, 22, 29.
		$result = $this->expander->expand($recurring, 2027);

		self::assertSame(50000, $result['monthlyCents']['03'], '5 occurrences * 100.00 EUR');
		self::assertSame(40000, $result['monthlyCents']['01'], '4 occurrences (the common case)');

	}//end testMonthWithFiveWeeklyOccurrencesSumsAllFive()

	/**
	 * REQ-BKC-010: a fortnightly cost's monthly amount is derived by
	 * counting the exact occurrence dates landing inside each month, not a
	 * fixed per-month occurrence assumption.
	 *
	 * @return void
	 */
	public function testFortnightlyExpansionEnumeratesExactOccurrenceDates(): void {
		$recurring = [
			'standardAmount' => 250.0,
			'indexationRule' => 'FIXED',
			'validFrom' => '2027-01-04',
			'frequency' => 'FORTNIGHTLY',
		];

		$result = $this->expander->expand($recurring, 2027);

		// January: 2027-01-04, 2027-01-18 (2 occurrences).
		self::assertSame(50000, $result['monthlyCents']['01']);
		// February: 2027-02-01, 2027-02-15 (2 occurrences).
		self::assertSame(50000, $result['monthlyCents']['02']);

	}//end testFortnightlyExpansionEnumeratesExactOccurrenceDates()

	/**
	 * REQ-BKC-010 point 3: CPI indexation applies to the per-occurrence
	 * amount before the monthly sum — a year-over-year step-up is reflected
	 * uniformly across every occurrence of that fiscal year, not applied to
	 * an already-summed monthly total.
	 *
	 * @return void
	 */
	public function testWeeklyIndexationAppliesPerOccurrenceBeforeMonthlySum(): void {
		$recurring = [
			'standardAmount' => 100.0,
			'indexationRule' => 'CPI_PAST_YEAR',
			'cpiRatePercent' => 10.0,
			'validFrom' => '2027-01-04', // A Monday.
			'frequency' => 'WEEKLY',
		];

		// Fiscal year 2028 is one year after validFrom's year (2027): factor
		// = 1.10^1 = 1.10, so the per-occurrence amount is 110.00 EUR.
		// February 2028 contains exactly 4 occurrence dates (Mondays 07, 14,
		// 21, 28) of the weekly series anchored on 2027-01-04, isolating the
		// indexation arithmetic (110.00, not the un-indexed 100.00) from the
		// occurrence count.
		$result = $this->expander->expand($recurring, 2028);

		self::assertSame(44000, $result['monthlyCents']['02'], '4 occurrences * 110.00 EUR indexed amount');

	}//end testWeeklyIndexationAppliesPerOccurrenceBeforeMonthlySum()

	/**
	 * A fixed-rate recurring cost with a validTo before the requested
	 * fiscal year contributes nothing for that year.
	 *
	 * @return void
	 */
	public function testExpiredRecurringContributesNothing(): void {
		$recurring = [
			'standardAmount' => 100.0,
			'indexationRule' => 'FIXED',
			'validFrom' => '2020-01-01',
			'validTo' => '2021-12-31',
			'frequency' => 'MONTHLY',
			'dagFromMonth' => 1,
		];

		$result = $this->expander->expand($recurring, 2027);

		foreach (array_keys($result['monthlyCents']) as $monthKey) {
			self::assertSame(0, $result['monthlyCents'][$monthKey], "month {$monthKey}");
		}

	}//end testExpiredRecurringContributesNothing()
}//end class
