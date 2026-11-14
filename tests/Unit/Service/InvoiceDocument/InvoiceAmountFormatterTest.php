<?php

/**
 * Unit tests for invoice amount formatting per language and currency (REQ-SID-004).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\InvoiceDocument
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-invoice-document/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\InvoiceDocument;

use OCA\Shillinq\Service\InvoiceDocument\InvoiceAmountFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * NumberFormatter output for nl and en with EUR and GBP.
 */
#[CoversClass(InvoiceAmountFormatter::class)]
final class InvoiceAmountFormatterTest extends TestCase {
	/**
	 * English pounds: "£4,800.00", no euro sign.
	 *
	 * @return void
	 */
	public function testEnglishPounds(): void {
		$text = (new InvoiceAmountFormatter())->money(4800.0, 'GBP', 'en');

		self::assertSame('£4,800.00', $text);
		self::assertStringNotContainsString('€', $text);

	}//end testEnglishPounds()

	/**
	 * Dutch euros and Dutch pounds use the Dutch separators.
	 *
	 * @return void
	 */
	public function testDutchEurosAndPounds(): void {
		$formatter = new InvoiceAmountFormatter();

		self::assertSame("€\u{a0}24.200,00", $formatter->money(24200.0, 'EUR', 'nl'));
		self::assertSame("£\u{a0}4.800,00", $formatter->money(4800.0, 'gbp', 'nl'));
		self::assertSame('€24,200.00', $formatter->money(24200.0, 'EUR', 'en'));

	}//end testDutchEurosAndPounds()

	/**
	 * A malformed currency falls back to EUR, an unknown language to Dutch.
	 *
	 * @return void
	 */
	public function testFallbacks(): void {
		$formatter = new InvoiceAmountFormatter();

		self::assertSame("€\u{a0}1,50", $formatter->money(1.5, 'pounds', 'xx'));
		self::assertSame('nl_NL', InvoiceAmountFormatter::locale('xx'));
		self::assertSame('de_DE', InvoiceAmountFormatter::locale('de'));

	}//end testFallbacks()

	/**
	 * Quantities print with at most two decimals in the language.
	 *
	 * @return void
	 */
	public function testNumbers(): void {
		$formatter = new InvoiceAmountFormatter();

		self::assertSame('1,5', $formatter->number(1.5, 'nl'));
		self::assertSame('1.5', $formatter->number(1.5, 'en'));
		self::assertSame('40', $formatter->number(40.0, 'en'));
		self::assertSame('2.33', $formatter->number(2.3333, 'en'));

	}//end testNumbers()
}//end class
