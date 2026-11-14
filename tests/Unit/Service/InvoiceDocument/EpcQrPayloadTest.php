<?php

/**
 * Unit tests for the EPC069-12 SEPA transfer QR payload (REQ-SID-005).
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

use OCA\Shillinq\Service\InvoiceDocument\EpcQrPayload;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The payload's field order and its refusals.
 */
#[CoversClass(EpcQrPayload::class)]
final class EpcQrPayloadTest extends TestCase {
	/**
	 * Invoice 2026-0042 of EUR 24,200: the eleven fields in EPC069-12 order.
	 *
	 * @return void
	 */
	public function testTheMunicipalityInvoiceNamesAmountAndReference(): void {
		$payload = EpcQrPayload::build(
			name: 'Adviesbureau Van Dijk',
			iban: 'nl91 abna 0417 1643 00',
			amount: 24200.0,
			currency: 'EUR',
			reference: '2026-0042',
			bic: 'abnanl2a'
		);

		self::assertSame(
			"BCD\n002\n1\nSCT\nABNANL2A\nAdviesbureau Van Dijk\nNL91ABNA0417164300\nEUR24200.00\n\n\n2026-0042",
			$payload
		);

	}//end testTheMunicipalityInvoiceNamesAmountAndReference()

	/**
	 * Without a BIC the field stays, empty, so the order holds.
	 *
	 * @return void
	 */
	public function testAnEmptyBicKeepsTheFieldOrder(): void {
		$lines = explode("\n", (string)EpcQrPayload::build('A', 'NL91ABNA0417164300', 12.345, 'eur', 'F-1'));

		self::assertCount(11, $lines);
		self::assertSame('', $lines[4]);
		self::assertSame('EUR12.35', $lines[7]);
		self::assertSame('F-1', $lines[10]);

	}//end testAnEmptyBicKeepsTheFieldOrder()

	/**
	 * Name and reference are cut to the format's lengths.
	 *
	 * @return void
	 */
	public function testNameAndReferenceAreCutToTheFormatLengths(): void {
		$lines = explode("\n", (string)EpcQrPayload::build(str_repeat('n', 80), 'NL91ABNA0417164300', 1.0, 'EUR', str_repeat('r', 150)));

		self::assertSame(70, mb_strlen($lines[5]));
		self::assertSame(140, mb_strlen($lines[10]));

	}//end testNameAndReferenceAreCutToTheFormatLengths()

	/**
	 * Inputs no valid transfer can be built from.
	 *
	 * @return array<string,array{string,string,float,string}>
	 */
	public static function refusals(): array {
		return [
			'pounds' => ['Brightside', 'NL91ABNA0417164300', 4800.0, 'GBP'],
			'no iban' => ['Van Dijk', '', 10.0, 'EUR'],
			'malformed iban' => ['Van Dijk', 'NOT-AN-IBAN', 10.0, 'EUR'],
			'no name' => [' ', 'NL91ABNA0417164300', 10.0, 'EUR'],
			'nothing owed' => ['Van Dijk', 'NL91ABNA0417164300', 0.0, 'EUR'],
			'negative' => ['Van Dijk', 'NL91ABNA0417164300', -5.0, 'EUR'],
			'too large' => ['Van Dijk', 'NL91ABNA0417164300', 1000000000.0, 'EUR'],
		];

	}//end refusals()

	/**
	 * Each refusal gives no payload.
	 *
	 * @param string $name     Name.
	 * @param string $iban     IBAN.
	 * @param float  $amount   Amount.
	 * @param string $currency Currency.
	 *
	 * @return void
	 */
	#[DataProvider('refusals')]
	public function testNoPayloadWithoutAValidTransfer(string $name, string $iban, float $amount, string $currency): void {
		self::assertNull(EpcQrPayload::build($name, $iban, $amount, $currency, 'F-1'));

	}//end testNoPayloadWithoutAValidTransfer()
}//end class
