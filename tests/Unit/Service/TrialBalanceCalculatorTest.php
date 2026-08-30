<?php

/**
 * Unit tests for TrialBalanceCalculator.
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
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-3
 * KNOWINGLY DANGLING until shillinq#500 — see TrialBalanceService.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\TrialBalanceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure-logic trial-balance arithmetic helper.
 *
 * Covers REQ-TB-002 (opening from prior period), REQ-TB-003 (closing formula +
 * integer-cent precision), REQ-TB-005 (parent roll-up) and the balanced check.
 *
 * PHPUnit assertions take positional ($actual, $expected) arguments; the custom
 * named-parameter sniff does not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TrialBalanceCalculatorTest extends TestCase {

	/**
	 * The helper under test.
	 *
	 * @var TrialBalanceCalculator
	 */
	private TrialBalanceCalculator $calc;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new TrialBalanceCalculator();

	}//end setUp()

	/**
	 * Closing balance follows opening + (debit - credit) per REQ-TB-003.
	 *
	 * @return void
	 */
	public function testClosingBalanceFormula(): void {
		// 50000 + (10000 - 5000) = 55000, in cents.
		$closing = $this->calc->closingCents(5000000, 1000000, 500000);
		self::assertSame(5500000, $closing);
		self::assertSame(55000.0, $this->calc->fromCents($closing));

	}//end testClosingBalanceFormula()

	/**
	 * Cent conversion avoids IEEE-754 drift (0.1 + 0.2 == 0.3 in cents).
	 *
	 * @return void
	 */
	public function testCentArithmeticAvoidsFloatDrift(): void {
		$sum = ($this->calc->toCents(0.1) + $this->calc->toCents(0.2));
		self::assertSame($this->calc->toCents(0.3), $sum);

	}//end testCentArithmeticAvoidsFloatDrift()

	/**
	 * Opening balance is carried from the prior period's closing balance (REQ-TB-002).
	 *
	 * @return void
	 */
	public function testOpeningFromPriorPeriod(): void {
		$prior = [
			['accountNumber' => '1000', 'closingBalance' => 55000.0],
			['accountNumber' => '2000', 'closingBalance' => 25000.0],
		];
		self::assertSame(5500000, $this->calc->openingFromPrior('1000', $prior));

	}//end testOpeningFromPriorPeriod()

	/**
	 * First period (no prior row) yields a zero opening balance (REQ-TB-002).
	 *
	 * @return void
	 */
	public function testOpeningIsZeroWhenNoPriorPeriod(): void {
		self::assertSame(0, $this->calc->openingFromPrior('9999', []));

	}//end testOpeningIsZeroWhenNoPriorPeriod()

	/**
	 * Parent accounts roll up child closing balances (REQ-TB-005, REQ-TB-020).
	 *
	 * @return void
	 */
	public function testParentRollUp(): void {
		$rows = [
			['accountNumber' => '1000', 'parentAccountNumber' => null, 'closingBalance' => 0.0],
			['accountNumber' => '1100', 'parentAccountNumber' => '1000', 'closingBalance' => 110000.0],
			['accountNumber' => '1300', 'parentAccountNumber' => '1000', 'closingBalance' => 1000.0],
		];
		$rolled = $this->calc->rollUpParents($rows);
		// Parent 1000 = own 0 + 110000 + 1000 = 111000.
		self::assertSame(11100000, $rolled['1000']);
		self::assertSame(11000000, $rolled['1100']);

	}//end testParentRollUp()

	/**
	 * The isBalanced() method returns true when total debits equal total credits (REQ-TB-003 proof).
	 *
	 * @return void
	 */
	public function testIsBalancedTrueWhenDebitsEqualCredits(): void {
		$rows = [
			['debitMovement' => 10000.0, 'creditMovement' => 5000.0],
			['debitMovement' => 3000.0, 'creditMovement' => 8000.0],
		];
		self::assertTrue($this->calc->isBalanced($rows));

	}//end testIsBalancedTrueWhenDebitsEqualCredits()

	/**
	 * The isBalanced() method returns false when debits and credits diverge.
	 *
	 * @return void
	 */
	public function testIsBalancedFalseWhenUnbalanced(): void {
		$rows = [
			['debitMovement' => 10000.0, 'creditMovement' => 5000.0],
			['debitMovement' => 3000.0, 'creditMovement' => 7000.0],
		];
		self::assertFalse($this->calc->isBalanced($rows));

	}//end testIsBalancedFalseWhenUnbalanced()

	/**
	 * Totals sum closing balances per account type for the KPI cards (REQ-TB-011).
	 *
	 * @return void
	 */
	public function testTotalsByAccountType(): void {
		$rows = [
			['accountType' => 'assets', 'closingBalance' => 55000.0, 'debitMovement' => 10000.0, 'creditMovement' => 5000.0],
			['accountType' => 'liabilities', 'closingBalance' => 25000.0, 'debitMovement' => 3000.0, 'creditMovement' => 8000.0],
			['accountType' => 'equity', 'closingBalance' => 30000.0, 'debitMovement' => 0.0, 'creditMovement' => 0.0],
		];
		$totals = $this->calc->totals($rows);
		self::assertSame(55000.0, $totals['totalAssets']);
		self::assertSame(25000.0, $totals['totalLiabilities']);
		self::assertSame(30000.0, $totals['totalEquity']);
		self::assertSame(13000.0, $totals['totalDebit']);
		self::assertSame(13000.0, $totals['totalCredit']);

	}//end testTotalsByAccountType()
}//end class
