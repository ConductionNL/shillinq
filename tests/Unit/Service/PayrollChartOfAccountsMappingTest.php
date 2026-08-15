<?php

/**
 * Unit tests for the PayrollChartOfAccountsMapping.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/specs/req-pay-012-gl-boeking.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\PayrollChartOfAccountsMapping;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the canonical RGS 3.5 mapping for payroll GL lines is stable.
 *
 * REQ-PAY-012: every Loonjournaalpost line must reference an account in the
 * 4001-4099 (loonkosten) or 1610-1699 (schulden) ranges. The mapping is the
 * single source of truth so the chart-of-accounts app and downstream reports
 * see one consistent contract.
 */
final class PayrollChartOfAccountsMappingTest extends TestCase {

	/**
	 * Mapping returns the full payroll account set with stable keys.
	 *
	 * @return void
	 */
	public function testAllReturnsAllAccountsAsCanonicalDictionary(): void {
		$all = PayrollChartOfAccountsMapping::all();

		$this->assertCount(10, $all);
		$this->assertSame('4001', $all['brutolonen']);
		$this->assertSame('4002', $all['belastingvrijeVergoedingen']);
		$this->assertSame('4010', $all['socialeLastenWg']);
		$this->assertSame('4012', $all['zvwWg']);
		$this->assertSame('4020', $all['pensioenWg']);
		$this->assertSame('1610', $all['teBetalenNettoLoon']);
		$this->assertSame('1620', $all['afTeDragenLh']);
		$this->assertSame('1630', $all['afTeDragenPremiesSvZvw']);
		$this->assertSame('1640', $all['afTeDragenPensioen']);
		$this->assertSame('1715', $all['teBetalenVakantiegeld']);

	}//end testAllReturnsAllAccountsAsCanonicalDictionary()

	/**
	 * Every account number is within the RGS 3.5 ranges (4001-4099 or 1610-1799).
	 *
	 * @return void
	 */
	public function testAccountNumbersStayInRgsRanges(): void {
		foreach (PayrollChartOfAccountsMapping::all() as $key => $accountNumber) {
			$num = (int)$accountNumber;
			$inCost = ($num >= 4001 && $num <= 4099);
			$inDebts = ($num >= 1610 && $num <= 1799);
			$this->assertTrue(
				($inCost || $inDebts),
				sprintf('Account %s for %s falls outside RGS 3.5 loonkosten/schulden ranges', $accountNumber, $key)
			);
		}

	}//end testAccountNumbersStayInRgsRanges()

	/**
	 * isKnown returns true for canonical accounts and false otherwise.
	 *
	 * @return void
	 */
	public function testIsKnownDiscriminatesCanonicalAccounts(): void {
		$this->assertTrue(PayrollChartOfAccountsMapping::isKnown('4001'));
		$this->assertTrue(PayrollChartOfAccountsMapping::isKnown('1640'));
		$this->assertFalse(PayrollChartOfAccountsMapping::isKnown('9999'));
		$this->assertFalse(PayrollChartOfAccountsMapping::isKnown(''));

	}//end testIsKnownDiscriminatesCanonicalAccounts()
}//end class
