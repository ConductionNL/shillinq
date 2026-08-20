<?php

/**
 * Unit tests for BudgetProjectionCalculator.
 *
 * Covers every degenerate case the `budget-projection-engine` task brief
 * names (REQ-BPE-001..010, `design.md` §1-§6, §8): the step-exclusion
 * table, the minimum-data floor, the fixed outlier trim, single-rate
 * compounding, the account-type metric selection, the actual/projected
 * seam, both cumulative variants, and the group sum-of-members rule.
 *
 * Every test instantiates {@see BudgetProjectionCalculator} with no
 * arguments and no mocks — REQ-BPE-010's "it reads NOTHING" contract.
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
 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\BudgetProjectionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure growth-rate, extrapolation, seam and roll-up arithmetic.
 */
final class BudgetProjectionCalculatorTest extends TestCase {

	/**
	 * REQ-BPE-010: the calculator has no constructor dependency on the
	 * object store — every test in this class instantiates it bare.
	 *
	 * @return void
	 */
	public function testCalculatorHasNoStoreDependency(): void {
		$calculator = new BudgetProjectionCalculator();

		$this->assertInstanceOf(BudgetProjectionCalculator::class, $calculator);

	}//end testCalculatorHasNoStoreDependency()

	// -- Task group 1: growth-rate step-exclusion table ------------------

	/**
	 * A `0 -> 0` step is included as `g = 0` (`design.md` §2h example 1).
	 *
	 * Series `[1000, 1000, 0, 0, 1000, 1000]` has 5 steps: `1000->1000`
	 * (g=0, included), `1000->0` (g=-1.0, included), `0->0` (the step under
	 * test), `0->1000` (excluded, zero-base), `1000->1000` (g=0, included).
	 * If the `0->0` step were wrongly excluded, only 3 steps would be
	 * valid, not 4 — `validSteps === 4` is direct proof of inclusion.
	 *
	 * @return void
	 */
	public function testZeroToZeroStepIncluded(): void {
		$calculator = new BudgetProjectionCalculator();

		$result = $calculator->growthRate([1000, 1000, 0, 0, 1000, 1000]);

		$this->assertSame(4, $result['validSteps']);
		$this->assertEqualsWithDelta(-0.25, $result['rate'], 0.0001);

	}//end testZeroToZeroStepIncluded()

	/**
	 * A step starting from a zero base is excluded (division by zero has
	 * no percentage).
	 *
	 * Series `[0, 500, 500, 500, 500]`: step `0->500` is the zero-base
	 * step under test; the remaining 3 steps are flat (`g=0`). If the
	 * zero-base step were wrongly included, `validSteps` would be 4, not
	 * 3 — and dividing 500 by 0 would either error or need special-casing,
	 * neither of which this asserts happened.
	 *
	 * @return void
	 */
	public function testZeroBaseStepExcluded(): void {
		$calculator = new BudgetProjectionCalculator();

		$result = $calculator->growthRate([0, 500, 500, 500, 500]);

		$this->assertSame(3, $result['validSteps']);
		$this->assertSame(0.0, $result['rate']);

	}//end testZeroBaseStepExcluded()

	/**
	 * A step ENDING at zero (non-zero base) is included as `g = -1.0` — the
	 * deliberate asymmetry with the zero-BASE exclusion above.
	 *
	 * Series `[500, 0, 500, 550, 605]`: step `500->0` (g=-1.0, included,
	 * under test), `0->500` (excluded, zero-base), `500->550` (g=0.10),
	 * `550->605` (g=0.10). `validSteps === 3` proves the first step was
	 * included (excluding it would leave only 2, below the floor).
	 *
	 * @return void
	 */
	public function testNonZeroToZeroStepIncluded(): void {
		$calculator = new BudgetProjectionCalculator();

		$result = $calculator->growthRate([500, 0, 500, 550, 605]);

		$this->assertSame(3, $result['validSteps']);
		$this->assertEqualsWithDelta((-1.0 + 0.10 + 0.10) / 3, $result['rate'], 0.0001);

	}//end testNonZeroToZeroStepIncluded()

	/**
	 * A step crossing between opposite non-zero signs is excluded
	 * (`design.md` §2h example 2: an `expenses` reversal month).
	 *
	 * Series `[500, 500, -200, 500, 500, 500, 500]`: `500->500` (g=0,
	 * included) x1, `500->-200` (excluded), `-200->500` (excluded),
	 * `500->500` (g=0, included) x3. `validSteps === 4` proves both
	 * sign-flip steps were dropped from the 6 total pairs.
	 *
	 * @return void
	 */
	public function testSignFlipStepExcluded(): void {
		$calculator = new BudgetProjectionCalculator();

		$result = $calculator->growthRate([500, 500, -200, 500, 500, 500, 500]);

		$this->assertSame(4, $result['validSteps']);
		$this->assertSame(0.0, $result['rate']);

	}//end testSignFlipStepExcluded()

	/**
	 * At 5+ included steps, the single highest and single lowest are
	 * trimmed before averaging — a single outlier month cannot dominate
	 * the forward curve (`design.md` §2e, §2h example 4).
	 *
	 * 12 values producing 11 included steps: ten cluster around +2%, one
	 * (step 6) is +80%. The naive (untrimmed) mean is ~9.1% — dragged
	 * toward the outlier; the trimmed mean stays near 2%.
	 *
	 * @return void
	 */
	public function testOutlierIsTrimmedAboveFiveSteps(): void {
		$calculator = new BudgetProjectionCalculator();

		// v0=10000, then +2% x5, +80% x1, +2% x5 (rounded to whole cents).
		$values = [10000, 10200, 10404, 10612, 10824, 11040, 19872, 20269, 20674, 21087, 21509, 21939];

		$result = $calculator->growthRate($values);

		$this->assertSame(11, $result['validSteps']);
		// Trimmed mean stays close to 2% ...
		$this->assertEqualsWithDelta(0.02, $result['rate'], 0.0005);
		// ... nowhere near the ~9.1% the untrimmed mean would be.
		$this->assertLessThan(0.05, $result['rate']);

	}//end testOutlierIsTrimmedAboveFiveSteps()

	/**
	 * Below 5 included steps, nothing is trimmed — even a large outlier
	 * contributes fully to the mean (`design.md` §2e: "removing 2 of e.g.
	 * 3 or 4 values would discard most of the already-thin sample").
	 *
	 * 5 values producing exactly 4 included steps (`~2%, ~2%, ~2%,
	 * ~88.5%`). If trimming wrongly applied, the ~88.5% high and one ~2%
	 * low would be dropped, leaving a mean near 2% instead of ~23.6%.
	 *
	 * @return void
	 */
	public function testNoTrimBelowFiveSteps(): void {
		$calculator = new BudgetProjectionCalculator();

		$values = [10000, 10200, 10404, 10612, 20000];

		$result = $calculator->growthRate($values);

		$this->assertSame(4, $result['validSteps']);
		$this->assertEqualsWithDelta(0.236163, $result['rate'], 0.0005);

	}//end testNoTrimBelowFiveSteps()

	// -- Task group 1: minimum-data floor ---------------------------------

	/**
	 * Exactly 2 included steps is below `MIN_VALID_STEPS = 3`: the
	 * calculator returns a typed unprojectable result, never a fabricated
	 * rate (REQ-BPE-004).
	 *
	 * @return void
	 */
	public function testBelowMinimumStepsIsUnprojectable(): void {
		$calculator = new BudgetProjectionCalculator();

		$result = $calculator->growthRate([10000, 10200, 10404]);

		$this->assertSame('insufficient-data', $result['reason']);
		$this->assertSame(2, $result['validSteps']);
		$this->assertArrayNotHasKey('rate', $result);

	}//end testBelowMinimumStepsIsUnprojectable()

	/**
	 * Exactly 3 included steps clears the floor and projects.
	 *
	 * @return void
	 */
	public function testExactlyMinimumStepsProjects(): void {
		$calculator = new BudgetProjectionCalculator();

		$result = $calculator->growthRate([10000, 10200, 10404, 10612]);

		$this->assertSame(3, $result['validSteps']);
		$this->assertArrayHasKey('rate', $result);
		$this->assertEqualsWithDelta(0.019997, $result['rate'], 0.0005);

	}//end testExactlyMinimumStepsProjects()

	// -- Task group 1: extrapolation --------------------------------------

	/**
	 * Month 3 of a projection equals the last actual compounded three
	 * times: `round(10000 x 1.02^3) = round(10612.08) = 10612` cents — the
	 * exact worked example in `design.md` §2g / REQ-BPE-005's scenario.
	 *
	 * @return void
	 */
	public function testExtrapolationCompoundsSingleRate(): void {
		$calculator = new BudgetProjectionCalculator();

		$this->assertSame(10612, $calculator->extrapolate(10000, 0.02, 3));

	}//end testExtrapolationCompoundsSingleRate()

	// -- Task group 1: account-type metric selection ----------------------

	/**
	 * A stock account's growth rate and projection run over `closingBalance`
	 * (the running carry-forward of net movements), NOT a value derived
	 * from projecting `netMovement` and re-summing (REQ-BPE-001).
	 *
	 * Four months of a flat +1000 cents net movement produce a
	 * closingBalance series `[1000, 2000, 3000, 4000]` — accelerating
	 * ratios (100%, 50%, 33.3%) because the BALANCE keeps growing even
	 * though the MOVEMENT is flat. Projecting the movement instead
	 * (see {@see testFlowAccountProjectsNetMovement()}) would show a flat
	 * 0% rate — a materially different answer, which is the point.
	 *
	 * @return void
	 */
	public function testStockAccountProjectsClosingBalance(): void {
		$calculator = new BudgetProjectionCalculator();

		$this->assertSame('closingBalance', $calculator->projectionMetric('assets'));

		$netMovements = [1000, 1000, 1000, 1000];
		$series = $calculator->metricSeries($netMovements, 'closingBalance');
		$this->assertSame([1000, 2000, 3000, 4000], $series);

		$growth = $calculator->growthRate($series);
		$this->assertSame(3, $growth['validSteps']);
		$this->assertEqualsWithDelta((1.0 + 0.5 + (1.0 / 3.0)) / 3, $growth['rate'], 0.0001);

		$v0 = $series[count($series) - 1];
		$this->assertSame(6444, $calculator->extrapolate($v0, $growth['rate'], 1));

	}//end testStockAccountProjectsClosingBalance()

	/**
	 * A flow account's growth rate and projection run over `netMovement`
	 * directly, unchanged by `metricSeries()` — a flat monthly movement
	 * produces a flat 0% rate and a flat forward projection, the opposite
	 * conclusion {@see testStockAccountProjectsClosingBalance()} reaches on
	 * the SAME raw input, because the metric selected is different
	 * (REQ-BPE-001).
	 *
	 * @return void
	 */
	public function testFlowAccountProjectsNetMovement(): void {
		$calculator = new BudgetProjectionCalculator();

		$this->assertSame('netMovement', $calculator->projectionMetric('revenue'));

		$netMovements = [1000, 1000, 1000, 1000];
		$series = $calculator->metricSeries($netMovements, 'netMovement');
		$this->assertSame($netMovements, $series);

		$growth = $calculator->growthRate($series);
		$this->assertSame(3, $growth['validSteps']);
		$this->assertSame(0.0, $growth['rate']);

		$v0 = $series[count($series) - 1];
		$this->assertSame(1000, $calculator->extrapolate($v0, $growth['rate'], 1));

	}//end testFlowAccountProjectsNetMovement()

	/**
	 * `projectionMetric()` rejects an account type outside the five
	 * declared values rather than silently defaulting.
	 *
	 * @return void
	 */
	public function testProjectionMetricRejectsUnknownAccountType(): void {
		$calculator = new BudgetProjectionCalculator();

		$this->expectException(InvalidArgumentException::class);
		$calculator->projectionMetric('bogus');

	}//end testProjectionMetricRejectsUnknownAccountType()

	// -- Task group 2: seam ------------------------------------------------

	/**
	 * The seam is resolved per account, not globally: two accounts asked
	 * about the SAME month can resolve differently depending on each
	 * account's own actual data and cutover (REQ-BPE-006).
	 *
	 * @return void
	 */
	public function testSeamIsPerAccountNotGlobal(): void {
		$calculator = new BudgetProjectionCalculator();

		// Account A has actuals through 2026-06: 2026-05 is a real actual.
		$this->assertSame('actual', $calculator->seam(true, '2026-05', '2026-06'));

		// Account B (opened later) has actuals only through 2026-04:
		// 2026-05 is beyond ITS cutover, so it projects.
		$this->assertSame('projected', $calculator->seam(false, '2026-05', '2026-04'));

	}//end testSeamIsPerAccountNotGlobal()

	/**
	 * An actual value is never overridden by a projection, even for a
	 * month that would otherwise fall inside the projection horizon (a
	 * late-posted correction) — REQ-BPE-006.
	 *
	 * @return void
	 */
	public function testSeamNeverOverridesAnActual(): void {
		$calculator = new BudgetProjectionCalculator();

		$this->assertSame('actual', $calculator->seam(true, '2026-07', '2026-06'));

	}//end testSeamNeverOverridesAnActual()

	/**
	 * A month strictly before the account's earliest data (no actual, and
	 * at or before its own last-actual cutover) resolves as unprojectable
	 * — the in-window historical gap, not a future month.
	 *
	 * @return void
	 */
	public function testSeamResolvesInWindowGapAsUnprojectable(): void {
		$calculator = new BudgetProjectionCalculator();

		$this->assertSame('unprojectable', $calculator->seam(false, '2026-03', '2026-06'));

	}//end testSeamResolvesInWindowGapAsUnprojectable()

	// -- Task group 2: cumulative -------------------------------------------

	/**
	 * A flow account's cumulative series is a continuous running sum
	 * across the actual/projected seam — no reset, no gap (REQ-BPE-008).
	 *
	 * @return void
	 */
	public function testFlowCumulativeIsRunningSumAcrossSeam(): void {
		$calculator = new BudgetProjectionCalculator();

		$trend = [
			['kind' => 'actual', 'amount' => 100],
			['kind' => 'actual', 'amount' => 100],
			['kind' => 'actual', 'amount' => 100],
			['kind' => 'actual', 'amount' => 100],
			['kind' => 'actual', 'amount' => 100],
			['kind' => 'actual', 'amount' => 100],
			['kind' => 'projected', 'amount' => 110, 'rate' => 0.10, 'validSteps' => 5],
			['kind' => 'projected', 'amount' => 121, 'rate' => 0.10, 'validSteps' => 5],
			['kind' => 'projected', 'amount' => 133, 'rate' => 0.10, 'validSteps' => 5],
		];

		$cumulative = $calculator->cumulative($trend, 'revenue');

		$this->assertSame([100, 200, 300, 400, 500, 600, 710, 831, 964], $cumulative);

	}//end testFlowCumulativeIsRunningSumAcrossSeam()

	/**
	 * A stock account's cumulative series equals its trend series exactly
	 * — closing balances are never re-summed across months (REQ-BPE-008).
	 *
	 * @return void
	 */
	public function testStockCumulativeEqualsTrend(): void {
		$calculator = new BudgetProjectionCalculator();

		$trend = [
			['kind' => 'actual', 'amount' => 1000],
			['kind' => 'actual', 'amount' => 1500],
			['kind' => 'projected', 'amount' => 1800, 'rate' => 0.20, 'validSteps' => 4],
		];

		$cumulative = $calculator->cumulative($trend, 'assets');

		$this->assertSame([1000, 1500, 1800], $cumulative);

	}//end testStockCumulativeEqualsTrend()

	// -- Task group 3: group sum semantics -----------------------------------

	/**
	 * A `LedgerGroup`'s projected value is the sum of its resolved
	 * members' own typed results — no group-level rate is fitted
	 * (REQ-BPE-007).
	 *
	 * @return void
	 */
	public function testGroupSumsMemberProjections(): void {
		$calculator = new BudgetProjectionCalculator();

		$members = [
			['kind' => 'projected', 'amount' => 1000, 'rate' => 0.02, 'validSteps' => 5],
			['kind' => 'projected', 'amount' => 2000, 'rate' => 0.05, 'validSteps' => 8],
			['kind' => 'actual', 'amount' => 1500],
		];

		$result = $calculator->groupProjected($members);

		$this->assertSame('projected', $result['kind']);
		$this->assertSame(4500, $result['amount']);
		$this->assertFalse($result['partial']);

	}//end testGroupSumsMemberProjections()

	/**
	 * A partially unprojectable group still returns the projectable
	 * members' sum, tagged `partial: true` rather than withheld or
	 * silently treating the unprojectable member as an untagged zero
	 * (REQ-BPE-007).
	 *
	 * @return void
	 */
	public function testPartialGroupTaggedNotWithheld(): void {
		$calculator = new BudgetProjectionCalculator();

		$members = [
			['kind' => 'projected', 'amount' => 1000, 'rate' => 0.02, 'validSteps' => 5],
			['kind' => 'unprojectable', 'reason' => 'insufficient-data', 'validSteps' => 2],
		];

		$result = $calculator->groupProjected($members);

		$this->assertSame('projected', $result['kind']);
		$this->assertSame(1000, $result['amount']);
		$this->assertTrue($result['partial']);

	}//end testPartialGroupTaggedNotWithheld()

	/**
	 * A group where EVERY resolved member is unprojectable is itself
	 * unprojectable — the narrower case REQ-BPE-007 also names.
	 *
	 * @return void
	 */
	public function testGroupUnprojectableOnlyWhenEveryMemberIs(): void {
		$calculator = new BudgetProjectionCalculator();

		$members = [
			['kind' => 'unprojectable', 'reason' => 'insufficient-data', 'validSteps' => 1],
			['kind' => 'unprojectable', 'reason' => 'no-history', 'validSteps' => 0],
		];

		$result = $calculator->groupProjected($members);

		$this->assertSame('unprojectable', $result['kind']);

	}//end testGroupUnprojectableOnlyWhenEveryMemberIs()

	// -- Calendar utilities --------------------------------------------------

	/**
	 * `nextMonth()` rolls a December bucket into January of the next year.
	 *
	 * @return void
	 */
	public function testNextMonthRollsOverYearBoundary(): void {
		$calculator = new BudgetProjectionCalculator();

		$this->assertSame('2027-01', $calculator->nextMonth('2026-12'));

	}//end testNextMonthRollsOverYearBoundary()

	/**
	 * `monthOffset()` counts whole months across a year boundary.
	 *
	 * @return void
	 */
	public function testMonthOffsetCountsAcrossYearBoundary(): void {
		$calculator = new BudgetProjectionCalculator();

		$this->assertSame(3, $calculator->monthOffset('2026-11', '2027-02'));
		$this->assertSame(-3, $calculator->monthOffset('2027-02', '2026-11'));

	}//end testMonthOffsetCountsAcrossYearBoundary()
}//end class
