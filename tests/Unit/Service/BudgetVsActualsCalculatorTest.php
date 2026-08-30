<?php

/**
 * Unit tests for BudgetVsActualsCalculator.
 *
 * Covers `budget-core-schema` task group 8 (REQ-BCS-008): budgeted-vs-actual
 * resolution per LedgerGroup/month, and the parent-rollup rule (design.md
 * §3d) — a parent's own BudgetLine wins over summing its children on the
 * budgeted side, while the actual side always sums own + children (GL money
 * cannot double-count across disjoint account memberships).
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
 * @spec openspec/changes/budget-core-schema/specs/budget-core-schema/spec.md#req-bcs-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BudgetVsActualsCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure budgeted/actual arithmetic + parent-rollup rule.
 */
final class BudgetVsActualsCalculatorTest extends TestCase {

	/**
	 * The calculator under test.
	 *
	 * @var BudgetVsActualsCalculator
	 */
	private BudgetVsActualsCalculator $calculator;

	/**
	 * Set up the calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new BudgetVsActualsCalculator();

	}//end setUp()

	/**
	 * REQ-BCS-008 scenario: a BudgetLine with month01Amount EUR 10,000 under
	 * a LedgerGroup resolving to account 1000, with GL activity of EUR 8,000
	 * on that account in January, reports budgeted 10,000 EUR / actual 8,000
	 * EUR (in cents: 1000000 / 800000).
	 *
	 * @return void
	 */
	public function testBudgetedAndActualForALeafLedgerGroup(): void {
		$context = [
			'ledgerGroupEntries' => [
				['id' => 'lg-1', 'slug' => 'lg-1-slug', 'memberAccountNumbers' => ['1000']],
			],
			'ledgerGroupKeyToIndex' => ['lg-1' => 0, 'lg-1-slug' => 0],
			'ledgerGroupChildrenByIndex' => [],
			'actualsByAccountMonth' => ['1000' => ['2027-01' => 800000]],
			'budgetLines' => [
				['ledgerGroupId' => 'lg-1', 'month01Amount' => 1000000],
			],
		];

		$this->assertSame(1000000, $this->calculator->budgetedAmount('lg-1', 1, $context));
		$this->assertSame(800000, $this->calculator->actualAmount('lg-1', '2027-01', $context));

	}//end testBudgetedAndActualForALeafLedgerGroup()

	/**
	 * REQ-BCS-005/REQ-BCS-008 scenario: the seeded `Personeel` LedgerGroup
	 * (parent of `Lonen en salarissen`/`Sociale lasten en pensioenlasten`,
	 * no BudgetLine of its own) resolves its budgeted value to the recursive
	 * sum of its children — EUR 30,000 + EUR 8,000 = EUR 38,000
	 * (design.md §3d).
	 *
	 * @return void
	 */
	public function testParentWithoutOwnBudgetLineSumsChildrenForBudgeted(): void {
		$context = [
			'ledgerGroupEntries' => [
				['id' => 'personeel', 'slug' => 'ledger-group-personeel', 'memberAccountNumbers' => []],
				['id' => 'lone', 'slug' => 'ledger-group-lone', 'memberAccountNumbers' => ['4000']],
				['id' => 'socl', 'slug' => 'ledger-group-socl', 'memberAccountNumbers' => ['4100']],
			],
			'ledgerGroupKeyToIndex' => [
				'personeel' => 0,
				'ledger-group-personeel' => 0,
				'lone' => 1,
				'ledger-group-lone' => 1,
				'socl' => 2,
				'ledger-group-socl' => 2,
			],
			'ledgerGroupChildrenByIndex' => [0 => [1, 2]],
			'actualsByAccountMonth' => [],
			'budgetLines' => [
				['ledgerGroupId' => 'lone', 'month01Amount' => 3000000],
				['ledgerGroupId' => 'socl', 'month01Amount' => 800000],
			],
		];

		$this->assertSame(3800000, $this->calculator->budgetedAmount('personeel', 1, $context));

	}//end testParentWithoutOwnBudgetLineSumsChildrenForBudgeted()

	/**
	 * A parent LedgerGroup's OWN BudgetLine wins over summing its children —
	 * prevents double counting when both exist (design.md §3d).
	 *
	 * @return void
	 */
	public function testParentsOwnBudgetLineWinsOverChildrenRollup(): void {
		$context = [
			'ledgerGroupEntries' => [
				['id' => 'personeel', 'slug' => 'ledger-group-personeel', 'memberAccountNumbers' => []],
				['id' => 'lone', 'slug' => 'ledger-group-lone', 'memberAccountNumbers' => ['4000']],
			],
			'ledgerGroupKeyToIndex' => ['personeel' => 0, 'lone' => 1],
			'ledgerGroupChildrenByIndex' => [0 => [1]],
			'actualsByAccountMonth' => [],
			'budgetLines' => [
				// Both the parent AND the child have their own BudgetLine.
				['ledgerGroupId' => 'personeel', 'month01Amount' => 5000000],
				['ledgerGroupId' => 'lone', 'month01Amount' => 3000000],
			],
		];

		// The parent's own 50,000 EUR wins — NOT 50,000 + 30,000.
		$this->assertSame(5000000, $this->calculator->budgetedAmount('personeel', 1, $context));

	}//end testParentsOwnBudgetLineWinsOverChildrenRollup()

	/**
	 * The actual side always sums own member accounts' GL activity PLUS the
	 * children's own resolved actuals — unconditional, unlike the budgeted
	 * side's "own wins" rule, because GL money across disjoint account
	 * memberships cannot double-count.
	 *
	 * @return void
	 */
	public function testActualAlwaysSumsOwnAndChildren(): void {
		$context = [
			'ledgerGroupEntries' => [
				// The parent has ITS OWN directly-assigned account too.
				['id' => 'parent', 'slug' => 'parent-slug', 'memberAccountNumbers' => ['9000']],
				['id' => 'child', 'slug' => 'child-slug', 'memberAccountNumbers' => ['4000']],
			],
			'ledgerGroupKeyToIndex' => ['parent' => 0, 'child' => 1],
			'ledgerGroupChildrenByIndex' => [0 => [1]],
			'actualsByAccountMonth' => [
				'9000' => ['2027-01' => 100000],
				'4000' => ['2027-01' => 50000],
			],
			'budgetLines' => [],
		];

		// 1,000 EUR (own) + 500 EUR (child) = 1,500 EUR = 150000 cents.
		$this->assertSame(150000, $this->calculator->actualAmount('parent', '2027-01', $context));

	}//end testActualAlwaysSumsOwnAndChildren()

	/**
	 * An unknown LedgerGroup key resolves to zero for both budgeted and
	 * actual, rather than throwing.
	 *
	 * @return void
	 */
	public function testUnknownLedgerGroupKeyResolvesToZero(): void {
		$context = [
			'ledgerGroupEntries' => [],
			'ledgerGroupKeyToIndex' => [],
			'ledgerGroupChildrenByIndex' => [],
			'actualsByAccountMonth' => [],
			'budgetLines' => [],
		];

		$this->assertSame(0, $this->calculator->budgetedAmount('does-not-exist', 1, $context));
		$this->assertSame(0, $this->calculator->actualAmount('does-not-exist', '2027-01', $context));

	}//end testUnknownLedgerGroupKeyResolvesToZero()

	/**
	 * A LedgerGroup with no BudgetLine and no children resolves budgeted to
	 * zero (an empty recursive sum), and a month with no GL activity
	 * resolves actual to zero.
	 *
	 * @return void
	 */
	public function testLeafWithNoDataResolvesToZero(): void {
		$context = [
			'ledgerGroupEntries' => [
				['id' => 'lg-1', 'slug' => 'lg-1-slug', 'memberAccountNumbers' => ['1000']],
			],
			'ledgerGroupKeyToIndex' => ['lg-1' => 0],
			'ledgerGroupChildrenByIndex' => [],
			'actualsByAccountMonth' => [],
			'budgetLines' => [],
		];

		$this->assertSame(0, $this->calculator->budgetedAmount('lg-1', 1, $context));
		$this->assertSame(0, $this->calculator->actualAmount('lg-1', '2027-01', $context));

	}//end testLeafWithNoDataResolvesToZero()

	/**
	 * Each of the 12 calendar months resolves to its own field —
	 * month06Amount for month 6, not a fixed field.
	 *
	 * @return void
	 */
	public function testEachCalendarMonthResolvesItsOwnField(): void {
		$context = [
			'ledgerGroupEntries' => [
				['id' => 'lg-1', 'slug' => 'lg-1-slug', 'memberAccountNumbers' => []],
			],
			'ledgerGroupKeyToIndex' => ['lg-1' => 0],
			'ledgerGroupChildrenByIndex' => [],
			'actualsByAccountMonth' => [],
			'budgetLines' => [
				[
					'ledgerGroupId' => 'lg-1',
					'month01Amount' => 111,
					'month06Amount' => 666,
					'month12Amount' => 1212,
				],
			],
		];

		$this->assertSame(111, $this->calculator->budgetedAmount('lg-1', 1, $context));
		$this->assertSame(666, $this->calculator->budgetedAmount('lg-1', 6, $context));
		$this->assertSame(1212, $this->calculator->budgetedAmount('lg-1', 12, $context));

	}//end testEachCalendarMonthResolvesItsOwnField()
}//end class
