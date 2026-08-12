<?php

/**
 * Unit tests for RevenueRecognitionCalculator.
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
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\RevenueRecognitionCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure-logic IFRS 15 revenue arithmetic helper.
 *
 * Covers the worked examples from the spec: relative-SSP allocation (REQ-IFRS15-004),
 * residual allocation, cost-to-cost percentage of completion (REQ-IFRS15-005),
 * variable-consideration constraint (REQ-IFRS15-003), contract asset/liability
 * split (REQ-IFRS15-007), remaining amount (REQ-IFRS15-008), and modification
 * classification (REQ-IFRS15-006).
 *
 * PHPUnit assertions take positional ($actual, $expected) arguments; the custom
 * named-parameter sniff does not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RevenueRecognitionCalculatorTest extends TestCase {

	/**
	 * The helper under test.
	 *
	 * @var RevenueRecognitionCalculator
	 */
	private RevenueRecognitionCalculator $calc;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new RevenueRecognitionCalculator();

	}//end setUp()

	/**
	 * Relative-SSP allocation across three POs ties back exactly (REQ-IFRS15-004).
	 *
	 * Spec scenario: SSP 300K / 40K / 80K, total price 360K → 257.14K / 34.29K /
	 * 68.57K, summing exactly to 360K.
	 *
	 * @return void
	 */
	public function testRelativeSspAllocationTiesBack(): void {
		$pos = [
			['poId' => 'saas', 'ssp' => 300000.0],
			['poId' => 'impl', 'ssp' => 40000.0],
			['poId' => 'usage', 'ssp' => 80000.0],
		];

		$allocation = $this->calc->allocateRelativeSsp($pos, 360000.0);

		// 300/420 * 360 = 257142.857... → 257142.86 (largest PO absorbs remainder).
		self::assertEqualsWithDelta(257142.86, $allocation['saas'], 0.01);
		self::assertEqualsWithDelta(34285.71, $allocation['impl'], 0.01);
		self::assertEqualsWithDelta(68571.43, $allocation['usage'], 0.01);

		// The allocation MUST tie back to the total price exactly (in cents).
		$sumCents = 0;
		foreach ($allocation as $amount) {
			$sumCents += (int)round($amount * 100);
		}

		self::assertSame(36000000, $sumCents);

	}//end testRelativeSspAllocationTiesBack()

	/**
	 * Residual method allocates the remainder to the uncertain PO (REQ-IFRS15-004).
	 *
	 * Spec scenario: PO-1 SSP 100K reliable, PO-2 SSP uncertain, total 150K →
	 * PO-1 100K, PO-2 residual 50K.
	 *
	 * @return void
	 */
	public function testResidualAllocation(): void {
		$reliable = [['poId' => 'po1', 'ssp' => 100000.0]];

		$allocation = $this->calc->allocateResidual($reliable, 'po2', 150000.0);

		self::assertSame(100000.0, $allocation['po1']);
		self::assertSame(50000.0, $allocation['po2']);

	}//end testResidualAllocation()

	/**
	 * Cost-to-cost percentage of completion follows actual / revised estimate (REQ-IFRS15-005).
	 *
	 * Spec scenario: 480K actual / 900K revised = 53.33%.
	 *
	 * @return void
	 */
	public function testCostToCostPercentageComplete(): void {
		$pct = $this->calc->percentageComplete(480000.0, 900000.0);
		self::assertSame(53.33, $pct);

		// Cumulative revenue = 53.33% of 1M allocated. With clamped-2dp percentage
		// the cumulative is 533300; the engine uses the precise ratio for the post.
		$cumulative = $this->calc->cumulativeFromPercentage($pct, 1000000.0);
		self::assertEqualsWithDelta(533300.0, $cumulative, 1.0);

	}//end testCostToCostPercentageComplete()

	/**
	 * Percentage complete is clamped to [0,100] and guards divide-by-zero (REQ-IFRS15-005).
	 *
	 * @return void
	 */
	public function testPercentageCompleteClampsAndGuardsZero(): void {
		self::assertSame(0.0, $this->calc->percentageComplete(100.0, 0.0));
		self::assertSame(100.0, $this->calc->percentageComplete(1200.0, 1000.0));

	}//end testPercentageCompleteClampsAndGuardsZero()

	/**
	 * Revised margin turns negative when costs exceed price (REQ-IFRS15-009).
	 *
	 * Spec scenario: 1M price, 900K revised cost → margin 10%. A revised cost of
	 * 1.1M yields a negative margin (onerous-contract signal).
	 *
	 * @return void
	 */
	public function testRevisedMargin(): void {
		self::assertSame(0.1, $this->calc->revisedMargin(1000000.0, 900000.0));
		self::assertTrue($this->calc->revisedMargin(1000000.0, 1100000.0) < 0);

	}//end testRevisedMargin()

	/**
	 * Variable consideration is constrained to the highly-probable amount (REQ-IFRS15-003).
	 *
	 * Spec scenario: estimate 50K constrained to 20K → 20K enters the price.
	 *
	 * @return void
	 */
	public function testVariableConsiderationConstraint(): void {
		self::assertSame(20000.0, $this->calc->constrainedVariable(50000.0, 20000.0));
		// No constraint → full estimate.
		self::assertSame(50000.0, $this->calc->constrainedVariable(50000.0, null));
		// Estimate below the constraint → estimate wins.
		self::assertSame(15000.0, $this->calc->constrainedVariable(15000.0, 20000.0));

	}//end testVariableConsiderationConstraint()

	/**
	 * Total transaction price applies the constraint and the payable reduction (REQ-IFRS15-002).
	 *
	 * @return void
	 */
	public function testTotalTransactionPrice(): void {
		$price = [
			'fixedConsideration' => 360000.0,
			'variableConsideration' => 50000.0,
			'constraintAmount' => 30000.0,
			'significantFinancingComponent' => 0.0,
			'nonCashConsideration' => 0.0,
			'considerationPayableToCustomer' => 10000.0,
		];

		// 360000 + min(50000, 30000) + 0 + 0 - 10000 = 380000.
		self::assertSame(380000.0, $this->calc->totalTransactionPrice($price));

	}//end testTotalTransactionPrice()

	/**
	 * Contract liability arises when billed exceeds recognised (REQ-IFRS15-007).
	 *
	 * Spec scenario (month 6): recognised 89143, billed 90000 → liability 857.
	 *
	 * @return void
	 */
	public function testContractLiabilityWhenBilledExceedsRecognised(): void {
		$split = $this->calc->contractAssetLiability(89143.0, 90000.0);
		self::assertSame(0.0, $split['asset']);
		self::assertSame(857.0, $split['liability']);

	}//end testContractLiabilityWhenBilledExceedsRecognised()

	/**
	 * Contract asset arises when recognised exceeds billed (REQ-IFRS15-007).
	 *
	 * Spec scenario (construction month 6): recognised 533000, billed 500000 →
	 * asset 33000.
	 *
	 * @return void
	 */
	public function testContractAssetWhenRecognisedExceedsBilled(): void {
		$split = $this->calc->contractAssetLiability(533000.0, 500000.0);
		self::assertSame(33000.0, $split['asset']);
		self::assertSame(0.0, $split['liability']);

	}//end testContractAssetWhenRecognisedExceedsBilled()

	/**
	 * Remaining amount = allocated - cumulative recognised, floored at zero (REQ-IFRS15-008).
	 *
	 * @return void
	 */
	public function testRemainingAmount(): void {
		self::assertSame(270857.0, $this->calc->remainingAmount(360000.0, 89143.0));
		// Over-recognised contracts floor at zero, never negative.
		self::assertSame(0.0, $this->calc->remainingAmount(100000.0, 120000.0));

	}//end testRemainingAmount()

	/**
	 * Modification classification follows IFRS 15.18-21 (REQ-IFRS15-006).
	 *
	 * @return void
	 */
	public function testModificationClassification(): void {
		// Distinct new scope at SSP → new contract (IFRS 15.20(a)).
		self::assertSame('new-contract', $this->calc->classifyModification(true, true, false));
		// Price-only change → prospective.
		self::assertSame('prospective', $this->calc->classifyModification(false, false, true));
		// Added scope not distinct → cumulative catch-up.
		self::assertSame('not-distinct-cumulative', $this->calc->classifyModification(false, false, false));

	}//end testModificationClassification()

	/**
	 * Cent arithmetic avoids IEEE-754 drift across the allocation pipeline.
	 *
	 * @return void
	 */
	public function testCentArithmeticAvoidsFloatDrift(): void {
		// 0.1 + 0.2 in cents is exactly 0.3.
		self::assertSame(30, ($this->calc->toCents(0.1) + $this->calc->toCents(0.2)));
		self::assertSame(0.3, $this->calc->fromCents(30));

	}//end testCentArithmeticAvoidsFloatDrift()
}//end class
