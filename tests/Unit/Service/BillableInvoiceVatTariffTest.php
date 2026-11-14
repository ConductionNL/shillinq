<?php

/**
 * Unit tests for the VAT tariff of generated invoice lines
 * (tax-vat-rates-and-deductibility task 2.2, REQ-TVRD-002).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-vat-rates-and-deductibility/tasks.md#task-2.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\BillableInvoiceRecords;
use OCA\Shillinq\Service\Tax\VatTariffRefusedException;
use OCA\Shillinq\Tests\Unit\Service\Support\SeededVatTariffs;
use PHPUnit\Framework\TestCase;

/**
 * Each generated line is taxed at the tariff valid on the invoice date, and
 * a line without one refuses the invoice.
 */
final class BillableInvoiceVatTariffTest extends TestCase {
	use SeededVatTariffs;

	private const DATE = '2026-10-05';

	/**
	 * The records builder over the seeded tariffs.
	 *
	 * @return BillableInvoiceRecords
	 */
	private function records(): BillableInvoiceRecords {
		return new BillableInvoiceRecords(vat: $this->seededVat());
	}//end records()

	/**
	 * Hours at the high rate and a book at the low rate: EUR 210.00 and EUR 4.50 VAT.
	 *
	 * @return void
	 */
	public function testAMixedRateInvoiceIsTaxedPerTariff(): void {
		$lines = $this->records()->withVatTariffs(
			lineDrafts: [
				['description' => 'Hours', 'costAmountCents' => 100000, 'vatRate' => 21.0],
				['description' => 'Book', 'costAmountCents' => 5000, 'vatTariff' => 'low'],
			],
			documentDate: self::DATE
		);

		self::assertSame(['high', 'low'], array_column($lines, 'vatTariff'));
		self::assertSame([21.0, 9.0], array_column($lines, 'vatRate'));
		self::assertSame([self::DATE, self::DATE], array_column($lines, 'documentDate'));

		$totals = $this->seededVat()->calculateVAT($lines);
		self::assertSame([21000, 450], array_column($totals['breakdown'], 'vatCents'));
		self::assertSame(21450, $totals['vatCents']);
	}//end testAMixedRateInvoiceIsTaxedPerTariff()

	/**
	 * A zero rate maps to the zero tariff and a legacy code to its seeded tariff.
	 *
	 * @return void
	 */
	public function testZeroRatesAndLegacyCodesResolve(): void {
		$lines = $this->records()->withVatTariffs(
			lineDrafts: [['costAmountCents' => 100, 'vatRate' => 0.0], ['costAmountCents' => 100, 'vatTariff' => 'BTW9'], ['costAmountCents' => 100]],
			documentDate: self::DATE
		);

		self::assertSame(['zero', 'BTW9', 'high'], array_column($lines, 'vatTariff'));
		self::assertSame([0.0, 9.0, 21.0], array_column($lines, 'vatRate'));
	}//end testZeroRatesAndLegacyCodesResolve()

	/**
	 * A rate no tariff carries is refused, naming the rate and the date, instead of being set to 21.
	 *
	 * @return void
	 */
	public function testARateWithoutATariffIsRefused(): void {
		try {
			$this->records()->withVatTariffs(lineDrafts: [['costAmountCents' => 100, 'vatRate' => 6.0]], documentDate: self::DATE);
			self::fail('A 6 percent line was accepted.');
		} catch (VatTariffRefusedException $e) {
			self::assertSame('6%', $e->tariffCode);
			self::assertSame(self::DATE, $e->date);
			self::assertSame('VAT tariff "6%" does not exist (document date 2026-10-05).', $e->getMessage());
		}
	}//end testARateWithoutATariffIsRefused()

	/**
	 * A retired tariff is refused on a 2026 invoice, naming the tariff and the date.
	 *
	 * @return void
	 */
	public function testARetiredTariffIsRefused(): void {
		$this->expectException(VatTariffRefusedException::class);
		$this->expectExceptionMessage('VAT tariff "low-2018" is not valid on the document date 2026-10-05.');

		$this->records()->withVatTariffs(lineDrafts: [['costAmountCents' => 100, 'vatTariff' => 'low-2018']], documentDate: self::DATE);
	}//end testARetiredTariffIsRefused()

	/**
	 * An unknown tariff code is refused.
	 *
	 * @return void
	 */
	public function testAnUnknownTariffIsRefused(): void {
		$this->expectException(VatTariffRefusedException::class);
		$this->expectExceptionMessage('VAT tariff "super-low" does not exist (document date 2026-10-05).');

		$this->records()->withVatTariffs(lineDrafts: [['costAmountCents' => 100, 'vatTariff' => 'super-low']], documentDate: self::DATE);
	}//end testAnUnknownTariffIsRefused()
}//end class
