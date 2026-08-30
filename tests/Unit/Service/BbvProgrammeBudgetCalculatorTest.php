<?php

/**
 * Unit tests for BbvProgrammeBudgetCalculator — the REQ-BBC-001..003 rules
 * the provincies-BBV Compliance Dashboard was declared against and which
 * nothing computed (#866/#862).
 *
 * These are the rules most likely to be got wrong and least likely to be
 * noticed when they are: a traffic light one bucket out, an "all deselected"
 * filter that shows everything, a trend line that closes the gap over a quiet
 * month. Each is asserted here against literal numbers, and several carry
 * their own NEGATIVE half — a threshold test that only ever checks the green
 * side passes on a function that returns `'green'` unconditionally.
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
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BbvProgrammeBudgetCalculator;
use PHPUnit\Framework\TestCase;

/**
 * REQ-BBC-001..003 arithmetic tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BbvProgrammeBudgetCalculatorTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var BbvProgrammeBudgetCalculator
	 */
	private BbvProgrammeBudgetCalculator $calculator;

	/**
	 * Build the subject.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new BbvProgrammeBudgetCalculator();
	}//end setUp()

	/**
	 * REQ-BBC-001 scenario "Dashboard KPI cards reflect current fiscal year",
	 * asserted on the literal euro amounts the spec writes out.
	 *
	 * @return void
	 */
	public function testKpiScenarioFromTheSpecProducesTheSpecsNumbers(): void {
		$rows = $this->calculator->buildRows(
			selected: ['mobiliteit'],
			budgetByProgramme: ['mobiliteit' => 1000000.0],
			spendByProgramme: ['mobiliteit' => 600000.0],
			committedByProgramme: ['mobiliteit' => 200000.0]
		);

		$this->assertCount(1, $rows);
		$this->assertSame(1000000.0, $rows[0]['totalBudget']);
		$this->assertSame(600000.0, $rows[0]['spent']);
		$this->assertSame(200000.0, $rows[0]['committed']);
		$this->assertSame(200000.0, $rows[0]['remaining']);
		$this->assertSame(0.2, $rows[0]['remainingRatio']);
		$this->assertSame('green', $rows[0]['status']);
		$this->assertSame(0.0, $rows[0]['overspent']);
	}//end testKpiScenarioFromTheSpecProducesTheSpecsNumbers()

	/**
	 * REQ-BBC-001 scenario "Overspend triggers red status": €500k budget,
	 * €350k spent, €200k committed must read as a €50k overspend, and
	 * `remaining` must be NEGATIVE rather than clamped at zero.
	 *
	 * @return void
	 */
	public function testOverspendScenarioFromTheSpecIsRedAndNegative(): void {
		$rows = $this->calculator->buildRows(
			selected: ['water'],
			budgetByProgramme: ['water' => 500000.0],
			spendByProgramme: ['water' => 350000.0],
			committedByProgramme: ['water' => 200000.0]
		);

		$this->assertSame(-50000.0, $rows[0]['remaining']);
		$this->assertSame(50000.0, $rows[0]['overspent']);
		$this->assertSame('red', $rows[0]['status']);
	}//end testOverspendScenarioFromTheSpecIsRedAndNegative()

	/**
	 * The traffic light must DISCRIMINATE. All three buckets are asserted in
	 * one test, including the exact 15 % boundary, because a rule checked only
	 * on its green side passes on a function that always returns `'green'`.
	 *
	 * @return void
	 */
	public function testTrafficLightSeparatesAllThreeBuckets(): void {
		// Exactly 15 % remaining is GREEN — the spec writes "Remaining >= 15%".
		$this->assertSame('green', $this->calculator->trafficLight(remaining: 150.0, totalBudget: 1000.0));
		// A hair under the boundary flips to yellow.
		$this->assertSame('yellow', $this->calculator->trafficLight(remaining: 149.0, totalBudget: 1000.0));
		// Zero remaining is still yellow, not red: nothing is overspent yet.
		$this->assertSame('yellow', $this->calculator->trafficLight(remaining: 0.0, totalBudget: 1000.0));
		// Any overspend at all is red.
		$this->assertSame('red', $this->calculator->trafficLight(remaining: -0.01, totalBudget: 1000.0));
	}//end testTrafficLightSeparatesAllThreeBuckets()

	/**
	 * A programme with no budget and no overspend is green, not permanently
	 * amber — and a division by that zero budget must not escape as INF.
	 *
	 * @return void
	 */
	public function testZeroBudgetIsGreenAndDoesNotDivideByZero(): void {
		$rows = $this->calculator->buildRows(
			selected: ['cultuur'],
			budgetByProgramme: [],
			spendByProgramme: [],
			committedByProgramme: []
		);

		$this->assertSame('green', $rows[0]['status']);
		$this->assertSame(0.0, $rows[0]['remainingRatio']);
		$this->assertSame(0.0, $rows[0]['utilisation']);
		$this->assertTrue(is_finite($rows[0]['utilisation']));
	}//end testZeroBudgetIsGreenAndDoesNotDivideByZero()

	/**
	 * REQ-BBC-002: "Selecting no programme MUST show no data (not all
	 * programmes)." An empty selection and an absent filter must NOT behave
	 * the same, so both halves are asserted together.
	 *
	 * @return void
	 */
	public function testEmptySelectionShowsNothingWhileAnAbsentFilterShowsEverything(): void {
		$universe = ['ruimte', 'mobiliteit', 'water'];

		$none = $this->calculator->selectedProgrammes(universe: $universe, requested: [], seen: []);
		$this->assertSame([], $none, 'an empty programme selection must show no data');

		$all = $this->calculator->selectedProgrammes(universe: $universe, requested: null, seen: []);
		$this->assertSame($universe, $all, 'an absent programme filter must show every programme');
	}//end testEmptySelectionShowsNothingWhileAnAbsentFilterShowsEverything()

	/**
	 * A programme present in the DATA but outside the declared vocabulary is
	 * still reported — a province that has posted spend to an unlisted
	 * programme must see it rather than have it silently vanish from the
	 * totals.
	 *
	 * @return void
	 */
	public function testAProgrammeSeenOnlyInTheDataIsStillReported(): void {
		$selected = $this->calculator->selectedProgrammes(
			universe: ['ruimte'],
			requested: null,
			seen: ['ruimte', 'onderwijs']
		);

		$this->assertSame(['ruimte', 'onderwijs'], $selected);
	}//end testAProgrammeSeenOnlyInTheDataIsStillReported()

	/**
	 * A requested programme filter narrows to exactly that programme, and the
	 * declared order is preserved rather than the request's order.
	 *
	 * @return void
	 */
	public function testRequestedProgrammesNarrowInDeclaredOrder(): void {
		$selected = $this->calculator->selectedProgrammes(
			universe: ['ruimte', 'mobiliteit', 'water'],
			requested: ['water', 'ruimte'],
			seen: []
		);

		$this->assertSame(['ruimte', 'water'], $selected);
	}//end testRequestedProgrammesNarrowInDeclaredOrder()

	/**
	 * REQ-BBC-003: the exception list contains ONLY overspent programmes and
	 * is sorted largest overspend first. Seeding two in-budget programmes
	 * alongside two overspent ones is what makes "exactly 1 row" in the spec's
	 * scenario mean something.
	 *
	 * @return void
	 */
	public function testExceptionsAreOnlyOverspendsWorstFirst(): void {
		$rows = $this->calculator->buildRows(
			selected: ['ruimte', 'mobiliteit', 'water', 'milieu'],
			budgetByProgramme: [
				'ruimte' => 100000.0,
				'mobiliteit' => 100000.0,
				'water' => 100000.0,
				'milieu' => 100000.0,
			],
			spendByProgramme: [
				'ruimte' => 10000.0,
				'mobiliteit' => 150000.0,
				'water' => 20000.0,
				'milieu' => 200000.0,
			],
			committedByProgramme: []
		);

		$exceptions = $this->calculator->exceptionsFor(rows: $rows);

		$this->assertCount(2, $exceptions);
		$this->assertSame('milieu', $exceptions[0]['programmeStructure']);
		$this->assertSame(100000.0, $exceptions[0]['overspent']);
		$this->assertSame('mobiliteit', $exceptions[1]['programmeStructure']);
		$this->assertSame(50000.0, $exceptions[1]['overspent']);
	}//end testExceptionsAreOnlyOverspendsWorstFirst()

	/**
	 * REQ-BBC-001: "Months with no GL postings MUST appear as zero, not
	 * omitted." Asserted as a CUMULATIVE line, so a quiet month shows the
	 * previous total rather than dropping out of the series — and the month
	 * labels are asserted too, because a series with the right values and the
	 * wrong number of points draws the same shape over the wrong axis.
	 *
	 * @return void
	 */
	public function testTrendZeroFillsQuietMonthsAndKeepsThemInTheAxis(): void {
		$trend = $this->calculator->trendFor(
			startDate: '2026-01-01',
			endDate: '2026-04-30',
			monthlySpend: ['2026-01' => 1000.0, '2026-04' => 500.0],
			anyProgrammeSelected: true,
			totalBudget: 9000.0
		);

		$this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04'], $trend['months']);
		$this->assertSame([1000.0, 1000.0, 1000.0, 1500.0], $trend['cumulativeSpend']);
		$this->assertSame([9000.0, 9000.0, 9000.0, 9000.0], $trend['budgetReference']);
	}//end testTrendZeroFillsQuietMonthsAndKeepsThemInTheAxis()

	/**
	 * A fiscal year that does not start in January still spans twelve buckets
	 * and crosses the year boundary correctly.
	 *
	 * @return void
	 */
	public function testTrendSpansANonCalendarFiscalYear(): void {
		$months = $this->calculator->monthsBetween(startDate: '2025-04-01', endDate: '2026-03-31');

		$this->assertCount(12, $months);
		$this->assertSame('2025-04', $months[0]);
		$this->assertSame('2025-12', $months[8]);
		$this->assertSame('2026-01', $months[9]);
		$this->assertSame('2026-03', $months[11]);
	}//end testTrendSpansANonCalendarFiscalYear()

	/**
	 * With no programme selected the trend line must stay flat rather than
	 * plot spend the KPI cards are not counting — the two would otherwise
	 * disagree on the same screen.
	 *
	 * @return void
	 */
	public function testTrendIsFlatWhenNoProgrammeIsSelected(): void {
		$trend = $this->calculator->trendFor(
			startDate: '2026-01-01',
			endDate: '2026-03-31',
			monthlySpend: ['2026-01' => 1000.0, '2026-02' => 2000.0],
			anyProgrammeSelected: false,
			totalBudget: 0.0
		);

		$this->assertSame([0.0, 0.0, 0.0], $trend['cumulativeSpend']);
	}//end testTrendIsFlatWhenNoProgrammeIsSelected()

	/**
	 * The chart series are PARALLEL arrays keyed off one label list — the
	 * shape `endpointSource.labelsPath` + `series[].path` maps. A series that
	 * is shorter than the labels silently misaligns every bar after the gap.
	 *
	 * @return void
	 */
	public function testChartSeriesAreParallelToTheLabels(): void {
		$rows = $this->calculator->buildRows(
			selected: ['ruimte', 'water'],
			budgetByProgramme: ['ruimte' => 100.0],
			spendByProgramme: ['water' => 25.0],
			committedByProgramme: ['ruimte' => 10.0]
		);

		$series = $this->calculator->seriesFor(rows: $rows);

		$this->assertSame(['Ruimte', 'Water'], $series['labels']);
		$this->assertSame([100.0, 0.0], $series['budget']);
		$this->assertSame([0.0, 25.0], $series['spent']);
		$this->assertSame([10.0, 0.0], $series['committed']);
		$this->assertCount(count($series['labels']), $series['budget']);
		$this->assertCount(count($series['labels']), $series['spent']);
		$this->assertCount(count($series['labels']), $series['committed']);
	}//end testChartSeriesAreParallelToTheLabels()

	/**
	 * The four KPI totals are the sums of the reported rows, and `remaining`
	 * is derived from those totals rather than summed independently — so the
	 * cards can never disagree with the chart on the same screen.
	 *
	 * @return void
	 */
	public function testTotalsAggregateTheReportedRows(): void {
		$rows = $this->calculator->buildRows(
			selected: ['ruimte', 'water'],
			budgetByProgramme: ['ruimte' => 1000.0, 'water' => 500.0],
			spendByProgramme: ['ruimte' => 100.0, 'water' => 400.0],
			committedByProgramme: ['ruimte' => 50.0]
		);

		$totals = $this->calculator->totalsFor(rows: $rows);

		$this->assertSame(1500.0, $totals['totalBudget']);
		$this->assertSame(500.0, $totals['spent']);
		$this->assertSame(50.0, $totals['committed']);
		$this->assertSame(950.0, $totals['remaining']);
		$this->assertSame(2, $totals['programmeCount']);
		$this->assertSame('green', $totals['status']);
	}//end testTotalsAggregateTheReportedRows()
}//end class
