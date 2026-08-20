<?php

/**
 * BillingModelEngine unit tests (issue #111, Task 27).
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
 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BillingModelEngine;
use PHPUnit\Framework\TestCase;

/**
 * Pure model tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BillingModelEngineTest extends TestCase {

	/**
	 * T&M: 40 hrs @ €150 + 20 hrs @ €100 + €200 expenses = €8,200.
	 *
	 * @return void
	 */
	public function testTAndMMixedResources(): void {
		$eng = new BillingModelEngine();
		$lines = $eng->calculateTAndM(
			timeEntries: [
				['timeEntryId' => 't1', 'resourceType' => 'senior', 'hours' => 40.0, 'rateCents' => 15000, 'rateApplied' => ['rateCents' => 15000]],
				['timeEntryId' => 't2', 'resourceType' => 'junior', 'hours' => 20.0, 'rateCents' => 10000, 'rateApplied' => ['rateCents' => 10000]],
			],
			expenses: [['expenseId' => 'e1', 'description' => 'Travel', 'costAmountCents' => 20000]],
		);

		$totalCents = array_sum(array_column($lines, 'costAmountCents'));
		$this->assertSame(((40 * 15000) + (20 * 10000) + 20000), $totalCents);
		$this->assertCount(3, $lines);

	}//end testTAndMMixedResources()

	/**
	 * Fixed-fee: flat amount + expenses; time hidden.
	 *
	 * @return void
	 */
	public function testFixedFee(): void {
		$eng = new BillingModelEngine();
		$lines = $eng->calculateFixedFee(
			flatFeeCents: 5000000,
			description: 'Project',
			expenses: [['expenseId' => 'e1', 'description' => 'Travel', 'costAmountCents' => 100000]],
			timeHourCount: 200
		);

		$this->assertSame(5000000 + 100000, array_sum(array_column($lines, 'costAmountCents')));
		$this->assertSame('fixed_fee', $lines[0]['sourceType']);

	}//end testFixedFee()

	/**
	 * Milestone line carries metadata.
	 *
	 * @return void
	 */
	public function testMilestone(): void {
		$eng = new BillingModelEngine();
		$lines = $eng->calculateMilestone(
			milestone: [
				'milestoneId' => 'ms-1',
				'milestoneName' => 'Design Phase',
				'milestoneCompletedAt' => '2026-05-20',
				'milestoneBudgetCents' => 2500000,
			],
		);

		$this->assertSame(2500000, $lines[0]['costAmountCents']);
		$this->assertSame('ms-1', $lines[0]['modelSpecificFields']['milestoneId']);

	}//end testMilestone()

	/**
	 * Retainer adds overage when threshold is breached.
	 *
	 * @return void
	 */
	public function testRetainerWithOverage(): void {
		$eng = new BillingModelEngine();
		$lines = $eng->calculateRetainer(
			retainer: [
				'monthlyAmountCents' => 300000,
				'overageHoursThreshold' => 30.0,
				'overageHourlyRateCents' => 10000,
				'effectiveDate' => '2026-01-01',
				'label' => 'Support',
			],
			retainerMonth: '2026-05',
			hoursLogged: 50.0,
			expenses: [],
		);

		// Retainer + (50-30) × €100 = 3000 + 2000 = 5000 euros.
		$total = array_sum(array_column($lines, 'costAmountCents'));
		$this->assertSame(300000 + 200000, $total);
		$this->assertCount(2, $lines);

	}//end testRetainerWithOverage()

	/**
	 * Mixed model combines retainer + setup + overage + expenses.
	 *
	 * @return void
	 */
	public function testMixedModel(): void {
		$eng = new BillingModelEngine();
		$lines = $eng->calculateMixed(
			retainer: [
				'monthlyAmountCents' => 200000,
				'overageHoursThreshold' => 40.0,
				'overageHourlyRateCents' => 12000,
				'effectiveDate' => '2026-01-01',
				'label' => 'Mixed',
			],
			retainerMonth: '2026-05',
			hoursLogged: 60.0,
			setupFeeCents: 100000,
			setupFeeDescription: 'Setup',
			expenses: [['expenseId' => 'e1', 'description' => 'Travel', 'costAmountCents' => 30000]],
		);

		$total = array_sum(array_column($lines, 'costAmountCents'));
		$this->assertSame(200000 + 240000 + 100000 + 30000, $total);

	}//end testMixedModel()

}//end class
