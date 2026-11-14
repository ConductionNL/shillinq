<?php

/**
 * Golden tests for the trial-balance roll-up and totals.
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
 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\TrialBalanceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * The roll-up walks the parent chain, the totals sum by type and movement.
 */
final class TrialBalanceCalculatorGoldenTest extends TestCase {

	/**
	 * Roll-up over a deep chain, a numeric account, a missing parent, a duplicate row and a cycle.
	 *
	 * @return void
	 */
	public function testRollUpGolden(): void {
		$rows = [
			['accountNumber' => '1000', 'closingBalance' => 1.005],
			['accountNumber' => '1100', 'parentAccountNumber' => 1000, 'closingBalance' => 2],
			['accountNumber' => '1110', 'parentAccountNumber' => '1100', 'closingBalance' => '3.10'],
			['accountNumber' => '', 'parentAccountNumber' => '1000', 'closingBalance' => 99],
			['parentAccountNumber' => '1000', 'closingBalance' => 99],
			['accountNumber' => '2000', 'parentAccountNumber' => '9999', 'closingBalance' => 5],
			['accountNumber' => 'A', 'parentAccountNumber' => 'B', 'closingBalance' => 1],
			['accountNumber' => 'B', 'parentAccountNumber' => 'A', 'closingBalance' => 10],
			['accountNumber' => '3000', 'parentAccountNumber' => null, 'closingBalance' => null],
			['accountNumber' => '1100', 'parentAccountNumber' => '1000', 'closingBalance' => 4],
		];

		$this->assertSame(
			[
				1000 => 811,
				1100 => 710,
				1110 => 310,
				2000 => 500,
				'A'  => 35300,
				'B'  => 36200,
				3000 => 0,
			],
			(new TrialBalanceCalculator())->rollUpParents(rows: $rows)
		);

	}//end testRollUpGolden()

	/**
	 * A self-parented account stops at the guard.
	 *
	 * @return void
	 */
	public function testSelfParentStopsAtGuard(): void {
		$this->assertSame(
			['X' => 6500],
			(new TrialBalanceCalculator())->rollUpParents(rows: [['accountNumber' => 'X', 'parentAccountNumber' => 'X', 'closingBalance' => 1]])
		);

	}//end testSelfParentStopsAtGuard()

	/**
	 * Totals by type and movement, unknown types left out.
	 *
	 * @return void
	 */
	public function testTotalsGolden(): void {
		$rows = [
			['accountType' => 'assets', 'closingBalance' => 10.10, 'debitMovement' => 5, 'creditMovement' => 0],
			['accountType' => 'liabilities', 'closingBalance' => -3, 'debitMovement' => 0, 'creditMovement' => '2.50'],
			['accountType' => 'equity', 'closingBalance' => 1],
			['accountType' => 'revenue', 'closingBalance' => 0.015, 'creditMovement' => 1],
			['accountType' => 'expenses', 'closingBalance' => 7, 'debitMovement' => null],
			['accountType' => 'other', 'closingBalance' => 1000, 'debitMovement' => 1.5],
			['closingBalance' => 1000],
		];

		$calc = new TrialBalanceCalculator();
		$this->assertSame(
			[
				'totalAssets'      => 10.1,
				'totalLiabilities' => -3.0,
				'totalEquity'      => 1.0,
				'totalRevenue'     => 0.02,
				'totalExpenses'    => 7.0,
				'totalDebit'       => 6.5,
				'totalCredit'      => 3.5,
			],
			$calc->totals(rows: $rows)
		);
		$this->assertFalse($calc->isBalanced(rows: $rows));
		$this->assertTrue($calc->isBalanced(rows: [['debitMovement' => '1.10'], ['creditMovement' => 1.1]]));
		$this->assertSame(0.0, $calc->totals(rows: [])['totalDebit']);

	}//end testTotalsGolden()
}//end class
