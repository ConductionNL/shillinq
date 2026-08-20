<?php

/**
 * Unit tests for TaxReportCalculator.
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
 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\TaxReportCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure-logic Vpb statement arithmetic (REQ-VPB-003, REQ-VPB-009, REQ-VPB-010, REQ-VPB-012).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class TaxReportCalculatorTest extends TestCase {

	/**
	 * The calculator under test.
	 *
	 * @var TaxReportCalculator
	 */
	private TaxReportCalculator $calc;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new TaxReportCalculator();

	}//end setUp()

	/**
	 * Revenue + deductible expenses produce the right net taxable income (REQ-VPB-009).
	 *
	 * @return void
	 */
	public function testAggregateComputesNetTaxableIncome(): void {
		$rows = [
			['accountNumber' => '8000', 'accountType' => 'revenue', 'taxTreatment' => 'normal', 'amount' => 120000.0, 'side' => 'credit'],
			['accountNumber' => '4000', 'accountType' => 'expenses', 'taxTreatment' => 'normal', 'amount' => 80000.0, 'side' => 'debit'],
		];

		$result = $this->calc->aggregate($rows);

		self::assertSame(120000.0, $result['revenue']);
		self::assertSame(80000.0, $result['operatingExpenses']);
		self::assertSame(40000.0, $result['netTaxableIncome']);
		self::assertSame(0, $result['untaggedCount']);

	}//end testAggregateComputesNetTaxableIncome()

	/**
	 * Non-deductible postings are added back; special are subtracted (REQ-VPB-009).
	 *
	 * @return void
	 */
	public function testNonDeductibleAddedBackAndSpecialSubtracted(): void {
		$rows = [
			['accountNumber' => '8000', 'accountType' => 'revenue', 'taxTreatment' => 'normal', 'amount' => 100000.0, 'side' => 'credit'],
			['accountNumber' => '4000', 'accountType' => 'expenses', 'taxTreatment' => 'normal', 'amount' => 50000.0, 'side' => 'debit'],
			['accountNumber' => '4100', 'accountType' => 'expenses', 'taxTreatment' => 'nonDeductible', 'amount' => 5000.0, 'side' => 'debit'],
			['accountNumber' => '4200', 'accountType' => 'expenses', 'taxTreatment' => 'special', 'amount' => 2000.0, 'side' => 'debit'],
		];

		$result = $this->calc->aggregate($rows);

		self::assertSame(5000.0, $result['nonOperating']);
		self::assertSame(2000.0, $result['specialDeductions']);
		self::assertSame(53000.0, $result['netTaxableIncome']);

	}//end testNonDeductibleAddedBackAndSpecialSubtracted()

	/**
	 * Tax-relevant postings without a tag drive the untagged warning count (REQ-VPB-010).
	 *
	 * @return void
	 */
	public function testUntaggedTaxRelevantPostingsAreCounted(): void {
		$rows = [
			['accountNumber' => '8000', 'accountType' => 'revenue', 'taxTreatment' => '', 'amount' => 1000.0, 'side' => 'credit'],
			['accountNumber' => '4000', 'accountType' => 'expenses', 'taxTreatment' => '', 'amount' => 500.0, 'side' => 'debit'],
			['accountNumber' => '1000', 'accountType' => 'assets', 'taxTreatment' => '', 'amount' => 999.0, 'side' => 'debit'],
		];

		$result = $this->calc->aggregate($rows);

		self::assertSame(2, $result['untaggedCount']);

	}//end testUntaggedTaxRelevantPostingsAreCounted()

	/**
	 * The per-account breakdown is keyed and sorted by account number (REQ-VPB-011).
	 *
	 * @return void
	 */
	public function testBreakdownGroupsByAccount(): void {
		$rows = [
			[
				'accountNumber' => '8000',
				'accountName' => 'Omzet',
				'accountType' => 'revenue',
				'taxTreatment' => 'normal',
				'amount' => 600.0,
				'side' => 'credit',
			],
			[
				'accountNumber' => '8000',
				'accountName' => 'Omzet',
				'accountType' => 'revenue',
				'taxTreatment' => 'normal',
				'amount' => 400.0,
				'side' => 'credit',
			],
		];

		$result = $this->calc->aggregate($rows);

		self::assertCount(1, $result['breakdown']);
		self::assertSame('8000', $result['breakdown'][0]['accountNumber']);
		self::assertSame(1000.0, $result['breakdown'][0]['amount']);

	}//end testBreakdownGroupsByAccount()

	/**
	 * Float amounts are handled in integer cents without drift (REQ-VPB-009).
	 *
	 * @return void
	 */
	public function testCentArithmeticAvoidsFloatDrift(): void {
		$rows = [
			['accountNumber' => '8000', 'accountType' => 'revenue', 'taxTreatment' => 'normal', 'amount' => 0.1, 'side' => 'credit'],
			['accountNumber' => '8001', 'accountType' => 'revenue', 'taxTreatment' => 'normal', 'amount' => 0.2, 'side' => 'credit'],
		];

		$result = $this->calc->aggregate($rows);

		self::assertSame(0.3, $result['revenue']);

	}//end testCentArithmeticAvoidsFloatDrift()

	/**
	 * The Vpb liability estimate applies the two-bracket 2025 rates (REQ-VPB-012).
	 *
	 * @return void
	 */
	public function testEstimateLiabilityTwoBrackets(): void {
		self::assertSame(19000.0, $this->calc->estimateLiability(100000.0));
		self::assertSame(38000.0, $this->calc->estimateLiability(200000.0));
		self::assertSame(63800.0, $this->calc->estimateLiability(300000.0));
		self::assertSame(0.0, $this->calc->estimateLiability(-5000.0));

	}//end testEstimateLiabilityTwoBrackets()
}//end class
