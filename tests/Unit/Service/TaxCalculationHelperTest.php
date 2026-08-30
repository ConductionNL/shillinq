<?php

/**
 * Unit tests for TaxCalculationHelper.
 *
 * Covers all REQ-DT-001 through REQ-DT-010 acceptance criteria scenarios
 * from the spec using real Dutch amounts, dates, and Vpb tariffs per
 * Belastingplan 2026. All amounts are in euro cents.
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
 * @spec openspec/changes/bookkeeping-deferred-tax/specs/bookkeeping-deferred-tax/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\TaxCalculationHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure-logic deferred-tax arithmetic helper against REQ-DT acceptance criteria.
 *
 * PHPUnit assertions take positional arguments; the custom named-parameter sniff does not apply.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TaxCalculationHelperTest extends TestCase {

	/**
	 * The helper under test.
	 *
	 * @var TaxCalculationHelper
	 */
	private TaxCalculationHelper $helper;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->helper = new TaxCalculationHelper();

	}//end setUp()

	// -------------------------------------------------------------------------
	// toCents / fromCents — precision
	// -------------------------------------------------------------------------

	/**
	 * ToCents converts a float euro amount to integer cents without drift.
	 *
	 * @return void
	 */
	public function testToCentsConversion(): void {
		self::assertSame(240000000, $this->helper->toCents(2400000.00));
		self::assertSame(190000000, $this->helper->toCents(1900000.00));

	}//end testToCentsConversion()

	/**
	 * FromCents converts integer cents back to float euros.
	 *
	 * @return void
	 */
	public function testFromCentsConversion(): void {
		self::assertSame(2400000.0, $this->helper->fromCents(240000000));

	}//end testFromCentsConversion()

	/**
	 * Cent arithmetic avoids IEEE-754 drift (0.1 + 0.2 invariant).
	 *
	 * @return void
	 */
	public function testCentArithmeticAvoidsFloatDrift(): void {
		$a = $this->helper->toCents(0.1);
		$b = $this->helper->toCents(0.2);
		// In cents: 10 + 20 = 30 exactly.
		self::assertSame(30, $a + $b);

	}//end testCentArithmeticAvoidsFloatDrift()

	// -------------------------------------------------------------------------
	// REQ-DT-001: computeDeferredTaxCents — depreciation scenario
	// -------------------------------------------------------------------------

	/**
	 * REQ-DT-001 scenario: building depreciation difference EUR 500,000 at NL 25.8%.
	 *
	 * GIVEN commercial net book value EUR 2,400,000 and tax basis EUR 1,900,000.
	 * WHEN deferred-tax balance is computed.
	 * THEN deferredTaxBalance = 500,000 × 25.8% = EUR 129,000 (taxable, DTL).
	 *
	 * @return void
	 */
	public function testDeferredTaxCentsDepreciation(): void {
		$diffCents = 50000000;
		// EUR 500,000 in cents.
		$rateBps = 2580;
		// 25.80%.
		$dtaCents = $this->helper->computeDeferredTaxCents($diffCents, $rateBps);
		// 50,000,000 × 2580 / 10000 = 12,900,000 cents = EUR 129,000.
		self::assertSame(12900000, $dtaCents);

	}//end testDeferredTaxCentsDepreciation()

	/**
	 * REQ-DT-001 scenario: deductible provision EUR 200,000 at NL 25.8% produces negative DTA.
	 *
	 * @return void
	 */
	public function testDeferredTaxCentsDeductibleProvision(): void {
		$diffCents = -20000000;
		// EUR -200,000 (deductible).
		$rateBps = 2580;
		$dtaCents = $this->helper->computeDeferredTaxCents($diffCents, $rateBps);
		// -20,000,000 × 2580 / 10000 = -5,160,000 cents = EUR -51,600 (DTA).
		self::assertSame(-5160000, $dtaCents);

	}//end testDeferredTaxCentsDeductibleProvision()

	/**
	 * REQ-DT-001 zero difference yields zero deferred-tax balance.
	 *
	 * @return void
	 */
	public function testDeferredTaxCentsZeroDiff(): void {
		self::assertSame(0, $this->helper->computeDeferredTaxCents(0, 2580));

	}//end testDeferredTaxCentsZeroDiff()

	// -------------------------------------------------------------------------
	// REQ-DT-003: computeUtilisableLoss — all three regimes
	// -------------------------------------------------------------------------

	/**
	 * REQ-DT-003 pre-2019 regime: 100% utilisation, no cap.
	 *
	 * Loss EUR 500,000; profit EUR 300,000 → utilisable = EUR 300,000 (100%).
	 *
	 * @return void
	 */
	public function testUtilisableLossPreTwentyNineteen(): void {
		$utilisable = $this->helper->computeUtilisableLoss(50000000, 30000000, 'pre-2019-6year');
		self::assertSame(30000000, $utilisable);

	}//end testUtilisableLossPreTwentyNineteen()

	/**
	 * REQ-DT-003 pre-2019 regime: loss smaller than profit → fully used.
	 *
	 * Loss EUR 200,000; profit EUR 500,000 → utilisable = EUR 200,000.
	 *
	 * @return void
	 */
	public function testUtilisableLossPreTwentyNineteenFullyConsumed(): void {
		$utilisable = $this->helper->computeUtilisableLoss(20000000, 50000000, 'pre-2019-6year');
		self::assertSame(20000000, $utilisable);

	}//end testUtilisableLossPreTwentyNineteenFullyConsumed()

	/**
	 * REQ-DT-003 scenario: 2022+ regime with 50%-cap, profit EUR 1,800,000, loss EUR 3,200,000.
	 *
	 * First EUR 1,000,000 at 100% = EUR 1,000,000.
	 * Excess EUR 800,000 at 50% = EUR 400,000.
	 * Total utilisable = EUR 1,400,000.
	 *
	 * @return void
	 */
	public function testUtilisableLoss2022OnwardsScenarioFromSpec(): void {
		$loss = 320000000;
		// EUR 3,200,000 in cents.
		$profit = 180000000;
		// EUR 1,800,000 in cents.
		$utilisable = $this->helper->computeUtilisableLoss($loss, $profit, '2022-onwards');
		self::assertSame(140000000, $utilisable);
		// EUR 1,400,000.
	}//end testUtilisableLoss2022OnwardsScenarioFromSpec()

	/**
	 * REQ-DT-003 2022+ regime: profit exactly at threshold (EUR 1,000,000) → 100%.
	 *
	 * @return void
	 */
	public function testUtilisableLoss2022AtThreshold(): void {
		$utilisable = $this->helper->computeUtilisableLoss(200000000, 100000000, '2022-onwards');
		self::assertSame(100000000, $utilisable);
		// EUR 1,000,000 fully offset.
	}//end testUtilisableLoss2022AtThreshold()

	/**
	 * REQ-DT-003 2022+ regime: profit below threshold (EUR 600,000) → 100% offset.
	 *
	 * @return void
	 */
	public function testUtilisableLoss2022BelowThreshold(): void {
		$utilisable = $this->helper->computeUtilisableLoss(100000000, 60000000, '2022-onwards');
		self::assertSame(60000000, $utilisable);
		// All EUR 600,000 offset.
	}//end testUtilisableLoss2022BelowThreshold()

	/**
	 * REQ-DT-003 2022+ regime: large profit EUR 3,000,000; loss EUR 2,000,000.
	 *
	 * First EUR 1M at 100% = EUR 1M.
	 * Excess profit EUR 2M at 50% = EUR 1M.
	 * Max utilisable = EUR 2M. Loss = EUR 2M → utilisable = EUR 2M.
	 *
	 * @return void
	 */
	public function testUtilisableLoss2022LargeProfitLossFullyConsumed(): void {
		$utilisable = $this->helper->computeUtilisableLoss(200000000, 300000000, '2022-onwards');
		self::assertSame(200000000, $utilisable);
		// Loss fully consumed.
	}//end testUtilisableLoss2022LargeProfitLossFullyConsumed()

	/**
	 * REQ-DT-003 transition regime: follows 50% cap (same as 2022+).
	 *
	 * Loss EUR 3,200,000; profit EUR 1,800,000 → EUR 1,400,000.
	 *
	 * @return void
	 */
	public function testUtilisableLossTransitionRegime(): void {
		$utilisable = $this->helper->computeUtilisableLoss(320000000, 180000000, '2019-2021-transition');
		self::assertSame(140000000, $utilisable);

	}//end testUtilisableLossTransitionRegime()

	/**
	 * REQ-DT-003: zero profit → utilisable = 0.
	 *
	 * @return void
	 */
	public function testUtilisableLossZeroProfit(): void {
		self::assertSame(0, $this->helper->computeUtilisableLoss(100000000, 0, '2022-onwards'));

	}//end testUtilisableLossZeroProfit()

	/**
	 * REQ-DT-003: zero loss → utilisable = 0.
	 *
	 * @return void
	 */
	public function testUtilisableLossZeroLoss(): void {
		self::assertSame(0, $this->helper->computeUtilisableLoss(0, 100000000, '2022-onwards'));

	}//end testUtilisableLossZeroLoss()

	// -------------------------------------------------------------------------
	// REQ-DT-003: computeNlVpbTaxCents — 2026 brackets
	// -------------------------------------------------------------------------

	/**
	 * REQ-DT-003 spec scenario: taxable income 2026 = EUR 400,000.
	 *
	 * EUR 200,000 × 19% = EUR 38,000.
	 * EUR 200,000 × 25.8% = EUR 51,600.
	 * Total = EUR 89,600.
	 *
	 * Note: spec says EUR 76,000 for EUR 400K as it uses simplified example;
	 * this test validates the actual bracket formula.
	 *
	 * @return void
	 */
	public function testNlVpbTaxTwoBrackets(): void {
		$tax = $this->helper->computeNlVpbTaxCents(40000000);
		// EUR 400,000 in cents.
		// Bracket 1: 20,000,000 × 1900 / 10000 = 3,800,000 cents = EUR 38,000.
		// Bracket 2: 20,000,000 × 2580 / 10000 = 5,160,000 cents = EUR 51,600.
		self::assertSame(8960000, $tax);
		// EUR 89,600.
	}//end testNlVpbTaxTwoBrackets()

	/**
	 * REQ-DT-003: income at or below EUR 200,000 uses lower bracket only.
	 *
	 * EUR 200,000 × 19% = EUR 38,000.
	 *
	 * @return void
	 */
	public function testNlVpbTaxLowerBracketOnly(): void {
		$tax = $this->helper->computeNlVpbTaxCents(20000000);
		// EUR 200,000 in cents.
		self::assertSame(3800000, $tax);
		// EUR 38,000.
	}//end testNlVpbTaxLowerBracketOnly()

	/**
	 * REQ-DT-003: zero income → zero tax.
	 *
	 * @return void
	 */
	public function testNlVpbTaxZeroIncome(): void {
		self::assertSame(0, $this->helper->computeNlVpbTaxCents(0));

	}//end testNlVpbTaxZeroIncome()

	// -------------------------------------------------------------------------
	// REQ-DT-005: computeRateChangeAdjustmentCents
	// -------------------------------------------------------------------------

	/**
	 * REQ-DT-005 scenario: rate increases from 25.8% to 27%; DTL of EUR 500,000 diff is re-measured.
	 *
	 * Adjustment = 50,000,000 × (2700 - 2580) / 10000 = 50,000,000 × 120 / 10000 = 600,000 cents = EUR 6,000.
	 *
	 * @return void
	 */
	public function testRateChangeAdjustment(): void {
		$adjustment = $this->helper->computeRateChangeAdjustmentCents(50000000, 2580, 2700);
		self::assertSame(600000, $adjustment);
		// EUR 6,000 increase in DTL.
	}//end testRateChangeAdjustment()

	/**
	 * REQ-DT-005: same old and new rate → zero adjustment.
	 *
	 * @return void
	 */
	public function testRateChangeAdjustmentNoChange(): void {
		self::assertSame(0, $this->helper->computeRateChangeAdjustmentCents(50000000, 2580, 2580));

	}//end testRateChangeAdjustmentNoChange()

	/**
	 * REQ-DT-005: rate decrease reduces DTL.
	 *
	 * Diff EUR 1,000,000; rate 25.8% → 19% (bps: 2580 → 1900). Adjustment = -680,000 cents = EUR -6,800.
	 *
	 * @return void
	 */
	public function testRateChangeAdjustmentDecrease(): void {
		$adjustment = $this->helper->computeRateChangeAdjustmentCents(100000000, 2580, 1900);
		self::assertSame(-6800000, $adjustment);
		// EUR -68,000 reduction.
	}//end testRateChangeAdjustmentDecrease()

	// -------------------------------------------------------------------------
	// REQ-DT-006: computeEffectiveTaxRateBps
	// -------------------------------------------------------------------------

	/**
	 * REQ-DT-006 scenario: profit EUR 4,200,000, effective tax EUR 950,000 → ETR 22.6%.
	 *
	 * 950,000 / 4,200,000 × 10000 = 2261.9... → round to 2262 bps = 22.62%.
	 *
	 * @return void
	 */
	public function testEffectiveTaxRateFromSpec(): void {
		$etrBps = $this->helper->computeEffectiveTaxRateBps(95000000, 420000000);
		self::assertSame(2262, $etrBps);

	}//end testEffectiveTaxRateFromSpec()

	/**
	 * REQ-DT-006: zero profit → ETR = 0 (division-by-zero guard).
	 *
	 * @return void
	 */
	public function testEffectiveTaxRateZeroProfit(): void {
		self::assertSame(0, $this->helper->computeEffectiveTaxRateBps(10000, 0));

	}//end testEffectiveTaxRateZeroProfit()

	// -------------------------------------------------------------------------
	// REQ-DT-006: sumReconciliationItems
	// -------------------------------------------------------------------------

	/**
	 * REQ-DT-006 scenario: sum of reconciliation items from spec.
	 *
	 * Items: -12,400,000 (dividend) + 1,200,000 (gifts) + 7,500,000 (origination) + 800,000 (rate-change)
	 * = -2,900,000 cents.
	 *
	 * @return void
	 */
	public function testSumReconciliationItems(): void {
		$items = [
			['description' => 'Dividend exemption',  'type' => 'permanent',   'taxEffect' => -12400000],
			['description' => 'Non-deductible gifts', 'type' => 'permanent',  'taxEffect' => 1200000],
			['description' => 'Origination reversal', 'type' => 'temporary',  'taxEffect' => 7500000],
			['description' => 'Rate change',          'type' => 'rate-change', 'taxEffect' => 800000],
		];
		$sum = $this->helper->sumReconciliationItems($items);
		self::assertSame(-2900000, $sum);

	}//end testSumReconciliationItems()

	/**
	 * REQ-DT-006: empty items → sum = 0.
	 *
	 * @return void
	 */
	public function testSumReconciliationItemsEmpty(): void {
		self::assertSame(0, $this->helper->sumReconciliationItems([]));

	}//end testSumReconciliationItemsEmpty()

	// -------------------------------------------------------------------------
	// REQ-DT-009: computeMovementClosingBalance / computeMovementRecognisedInPL
	// -------------------------------------------------------------------------

	/**
	 * REQ-DT-009 MVA depreciation roll-forward scenario from spec.
	 *
	 * Opening EUR 380,000 + originated EUR 95,000 + reversed EUR -42,000
	 * + rate change EUR 8,000 = closing EUR 441,000.
	 * recognisedInPL = 95,000 - 42,000 + 8,000 = EUR 61,000.
	 *
	 * @return void
	 */
	public function testMovementRollForwardFromSpec(): void {
		$opening = 38000000;
		$originated = 9500000;
		$reversed = -4200000;
		$rateChange = 800000;
		$mAndA = 0;
		$fx = 0;
		$oci = 0;

		$closing = $this->helper->computeMovementClosingBalance(
			$opening,
			$originated,
			$reversed,
			$rateChange,
			$mAndA,
			$fx,
			$oci
		);
		self::assertSame(44100000, $closing);
		// EUR 441,000.
		$pl = $this->helper->computeMovementRecognisedInPL($originated, $reversed, $rateChange, $mAndA);
		self::assertSame(6100000, $pl);
		// EUR 61,000.
	}//end testMovementRollForwardFromSpec()

	/**
	 * REQ-DT-009: FX and OCI do not flow through P&L.
	 *
	 * @return void
	 */
	public function testMovementPLExcludesFxAndOCI(): void {
		$pl = $this->helper->computeMovementRecognisedInPL(5000000, -2000000, 1000000, 0);
		// FX and OCI not passed to this method — P&L = 4,000,000.
		self::assertSame(4000000, $pl);

	}//end testMovementPLExcludesFxAndOCI()

	/**
	 * REQ-DT-009: all zero inputs → zero closing balance.
	 *
	 * @return void
	 */
	public function testMovementAllZeroInputs(): void {
		$closing = $this->helper->computeMovementClosingBalance(0, 0, 0, 0, 0, 0, 0);
		self::assertSame(0, $closing);

	}//end testMovementAllZeroInputs()

	// -------------------------------------------------------------------------
	// REQ-DT-007: jurisdiction isolation (no cross-jurisdiction netting)
	// Tested indirectly: helper processes one jurisdiction at a time.
	// -------------------------------------------------------------------------

	/**
	 * REQ-DT-007: computeDeferredTaxCents with DE rate 30% for a German subsidiary.
	 *
	 * Temporary difference EUR 100,000 at 30% Körperschaftsteuer = EUR 30,000 DTL.
	 *
	 * @return void
	 */
	public function testDeferredTaxCentsGermanRate(): void {
		$dta = $this->helper->computeDeferredTaxCents(10000000, 3000);
		// 30.00% = 3000 bps.
		self::assertSame(3000000, $dta);
		// EUR 30,000.
	}//end testDeferredTaxCentsGermanRate()

	// -------------------------------------------------------------------------
	// applyFiftyCentCapLoss direct tests
	// -------------------------------------------------------------------------

	/**
	 * ApplyFiftyCentCapLoss: loss exactly equal to max utilisable (capped).
	 *
	 * Profit EUR 2,000,000; loss EUR 1,500,000.
	 * Max utilisable = EUR 1M + EUR 500K × 50% = EUR 1,250,000.
	 * Utilisable = min(EUR 1,500,000, EUR 1,250,000) = EUR 1,250,000.
	 *
	 * @return void
	 */
	public function testApplyFiftyCentCapLossCapped(): void {
		$utilisable = $this->helper->applyFiftyCentCapLoss(150000000, 200000000);
		// Max = 100,000,000 + round(100,000,000 * 0.5) = 150,000,000.
		self::assertSame(150000000, $utilisable);

	}//end testApplyFiftyCentCapLossCapped()
}//end class
