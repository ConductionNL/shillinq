<?php

/**
 * VATCalculationService unit tests (issue #111, Task 29).
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

use OCA\Shillinq\Service\VATCalculationService;
use PHPUnit\Framework\TestCase;

/**
 * Pure totaller tests — no Nextcloud bootstrap required.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class VATCalculationServiceTest extends TestCase {

	/**
	 * Single 21% rate sums correctly.
	 *
	 * @return void
	 */
	public function testSingle21PercentRate(): void {
		$svc = new VATCalculationService();
		$result = $svc->calculateVAT(
			[
				['costAmountCents' => 40000, 'vatRate' => 21.0],
				['costAmountCents' => 60000, 'vatRate' => 21.0],
			]
		);

		$this->assertSame(100000, $result['netCents']);
		$this->assertSame(21000, $result['vatCents']);
		$this->assertSame(121000, $result['grossCents']);
		$this->assertCount(1, $result['breakdown']);

	}//end testSingle21PercentRate()

	/**
	 * Mixed 21% + 9% rates report a per-rate breakdown.
	 *
	 * @return void
	 */
	public function testMixedRates(): void {
		$svc = new VATCalculationService();
		$result = $svc->calculateVAT(
			[
				['costAmountCents' => 40000, 'vatRate' => 21.0],
				['costAmountCents' => 10000, 'vatRate' => 9.0],
			]
		);

		$this->assertSame(50000, $result['netCents']);
		$this->assertSame(8400 + 900, $result['vatCents']);
		$this->assertSame(2, count($result['breakdown']));

	}//end testMixedRates()

	/**
	 * VAT on small amount rounds with bankers' rounding.
	 *
	 * @return void
	 */
	public function testBankersRoundingHalfEven(): void {
		$svc = new VATCalculationService();

		// 10 cents @ 21% = 2.1 cents → 2 (down, bankers rounds 0.5 even but 2.1 -> 2).
		$this->assertSame(2, $svc->vatOnNet(10, 21.0));

		// 50 cents @ 21% = 10.5 cents → 10 (banker's rounds half to even = 10).
		$this->assertSame(10, $svc->vatOnNet(50, 21.0));

	}//end testBankersRoundingHalfEven()

	/**
	 * Zero VAT rate flows through.
	 *
	 * @return void
	 */
	public function testZeroRate(): void {
		$svc = new VATCalculationService();
		$result = $svc->calculateVAT([['costAmountCents' => 12345, 'vatRate' => 0.0]]);

		$this->assertSame(12345, $result['netCents']);
		$this->assertSame(0, $result['vatCents']);
		$this->assertSame(12345, $result['grossCents']);

	}//end testZeroRate()

	/**
	 * isValidRate checks Dutch statutory set.
	 *
	 * @return void
	 */
	public function testIsValidRate(): void {
		$svc = new VATCalculationService();
		$this->assertTrue($svc->isValidRate(21.0));
		$this->assertTrue($svc->isValidRate(9.0));
		$this->assertTrue($svc->isValidRate(6.0));
		$this->assertTrue($svc->isValidRate(0.0));
		$this->assertFalse($svc->isValidRate(15.0));

	}//end testIsValidRate()

}//end class
