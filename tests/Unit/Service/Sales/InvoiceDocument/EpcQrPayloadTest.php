<?php

/**
 * Unit tests for EpcQrPayload.
 *
 * REQ-SID-005: a EUR invoice with an IBAN carries the EPC069-12 payload for
 * its open amount with the invoice number as the reference.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Sales\InvoiceDocument
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
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.4
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales\InvoiceDocument;

use OCA\Shillinq\Service\Sales\InvoiceDocument\EpcQrPayload;
use PHPUnit\Framework\TestCase;

/**
 * Invoice 2026-0042 of EUR 24,200 to Gemeente Voorbeeld.
 */
final class EpcQrPayloadTest extends TestCase {

	/**
	 * The payload follows the EPC069-12 version 002 field order, one field per line.
	 *
	 * @return void
	 */
	public function testThePayloadFollowsTheEpcFieldOrder(): void {
		$creditor = ['legalName' => 'Adviesbureau Van Dijk B.V.', 'iban' => 'NL91 ABNA 0417 1643 00', 'bic' => 'abnanl2a'];
		$payload = (new EpcQrPayload())->forTransfer($creditor, 'EUR', 24200, '2026-0042');

		$this->assertSame(
			[
				'BCD',
				'002',
				'1',
				'SCT',
				'ABNANL2A',
				'Adviesbureau Van Dijk B.V.',
				'NL91ABNA0417164300',
				'EUR24200.00',
				'',
				'',
				'2026-0042',
			],
			explode("\n", (string)$payload)
		);
		$this->assertLessThanOrEqual(331, strlen((string)$payload), 'the standard caps the payload at 331 bytes');
	}//end testThePayloadFollowsTheEpcFieldOrder()

	/**
	 * The BIC may be left empty; long names and references are cut to the standard's lengths.
	 *
	 * @return void
	 */
	public function testOptionalBicAndFieldLengths(): void {
		$creditor = ['legalName' => str_repeat('N', 80), 'iban' => 'NL91ABNA0417164300'];
		$payload = (new EpcQrPayload())->forTransfer($creditor, 'eur', 12.3, str_repeat('R', 150) . "\nx");
		$lines = explode("\n", (string)$payload);

		$this->assertSame('', $lines[4]);
		$this->assertSame(70, mb_strlen($lines[5]));
		$this->assertSame('EUR12.30', $lines[7]);
		$this->assertSame(140, mb_strlen($lines[10]));
		$this->assertCount(11, $lines);
	}//end testOptionalBicAndFieldLengths()

	/**
	 * Another currency, no or a malformed IBAN, no name, nothing open or too much: no payload.
	 *
	 * @return void
	 */
	public function testAnInvoiceThatCannotBePaidByTransferGetsNoPayload(): void {
		$epc = new EpcQrPayload();

		$this->assertNull($epc->forTransfer(['legalName' => 'Van Dijk', 'iban' => 'NL91ABNA0417164300', 'bic' => ''], 'GBP', 4800, '2026-0043'));
		$this->assertNull($epc->forTransfer(['legalName' => 'Van Dijk', 'iban' => '', 'bic' => ''], 'EUR', 100, '2026-0043'));
		$this->assertNull($epc->forTransfer(['legalName' => 'Van Dijk', 'iban' => 'not-an-iban', 'bic' => ''], 'EUR', 100, '2026-0043'));
		$this->assertNull($epc->forTransfer(['legalName' => ' ', 'iban' => 'NL91ABNA0417164300', 'bic' => ''], 'EUR', 100, '2026-0043'));
		$this->assertNull($epc->forTransfer(['legalName' => 'Van Dijk', 'iban' => 'NL91ABNA0417164300', 'bic' => ''], 'EUR', 0.004, '2026-0043'));
		$this->assertNull($epc->forTransfer(['legalName' => 'Van Dijk', 'iban' => 'NL91ABNA0417164300', 'bic' => ''], 'EUR', 1000000000, '2026-0043'));
	}//end testAnInvoiceThatCannotBePaidByTransferGetsNoPayload()
}//end class
