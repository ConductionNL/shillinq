<?php

/**
 * Unit tests for InvoiceDocumentData.
 *
 * REQ-SID-003, REQ-SID-004 and REQ-SID-005: the template data carries the
 * labels in the customer's language, every amount in the invoice currency and
 * the scan-to-pay payloads.
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
 * @spec openspec/changes/sales-invoice-document/tasks.md#task-2.3
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Sales\InvoiceDocument;

use OCA\Shillinq\Service\Payment\PayLinkService;
use OCA\Shillinq\Service\Sales\InvoiceDocument\EpcQrPayload;
use OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentData;
use OCA\Shillinq\Service\Sales\InvoiceDocument\InvoiceDocumentLanguage;
use PHPUnit\Framework\TestCase;

/**
 * Brightside Consulting Ltd (en, GBP) and Gemeente Voorbeeld (nl, EUR).
 */
final class InvoiceDocumentDataTest extends TestCase {

	/**
	 * The assembler, with an optional pay link.
	 *
	 * @param string|null $payLink What the pay link service answers.
	 *
	 * @return InvoiceDocumentData
	 */
	private function data(?string $payLink = null): InvoiceDocumentData {
		$payLinks = $this->createMock(PayLinkService::class);
		$payLinks->method('forOpenInvoice')->willReturn($payLink);

		return new InvoiceDocumentData(new InvoiceDocumentLanguage(InvoiceDocumentLanguageTest::factory($this)), new EpcQrPayload(), $payLinks);
	}//end data()

	/**
	 * The creditor, a recipient and a layout.
	 *
	 * @param array<string, mixed> $recipient The recipient.
	 * @param array<string, mixed> $layout    The layout.
	 *
	 * @return array<string, mixed>
	 */
	private static function parties(array $recipient, array $layout = []): array {
		return [
			'creditor' => ['legalName' => 'Adviesbureau Van Dijk B.V.', 'iban' => 'NL91ABNA0417164300', 'bic' => 'ABNANL2A'],
			'recipient' => $recipient,
			'layout' => $layout,
		];
	}//end parties()

	/**
	 * A pound invoice to an English customer reads in English with pounds and no euro sign, and has no EPC code.
	 *
	 * @return void
	 */
	public function testAPoundInvoiceToAnEnglishCustomer(): void {
		$invoice = ['invoiceNumber' => '2026-0043', 'currency' => 'GBP', 'netAmount' => 4800, 'vatAmount' => 0, 'grossAmount' => 4800];
		$lines = [['description' => 'Consulting October', 'quantity' => 40, 'unitPrice' => 120, 'lineAmount' => 4800, 'vatRate' => 0]];
		$parties = self::parties(['legalName' => 'Brightside Consulting Ltd', 'language' => 'en']);

		$data = $this->data()->assemble($invoice, $lines, $parties);

		$this->assertSame(['en', 'GBP'], [$data['language'], $data['currency']]);
		$this->assertSame(['Invoice', 'Due date', 'Total'], [$data['labels']['title'], $data['labels']['dueDate'], $data['labels']['total']]);
		$this->assertSame('£4,800.00', InvoiceDocumentLanguageTest::plain($data['totals']['gross']));
		$this->assertSame('£120.00', InvoiceDocumentLanguageTest::plain($data['lines'][0]['price']));
		$this->assertSame('40', $data['lines'][0]['quantity']);
		$this->assertStringNotContainsString('€', (string)json_encode($data, JSON_UNESCAPED_UNICODE));
		$this->assertNull($data['qr']['epc'], 'a pound invoice cannot be paid by SEPA transfer');
	}//end testAPoundInvoiceToAnEnglishCustomer()

	/**
	 * A municipality's euro invoice reads in Dutch and carries the EPC code for its open amount and the pay link.
	 *
	 * @return void
	 */
	public function testAMunicipalityPaysByScanning(): void {
		$invoice = ['invoiceNumber' => '2026-0042', 'currency' => 'EUR', 'netAmount' => 20000, 'vatAmount' => 4200, 'grossAmount' => 24200];
		$parties = self::parties(['legalName' => 'Gemeente Voorbeeld']);

		$data = $this->data(payLink: 'https://portal.example.nl/pay?pay=abc')->assemble($invoice, [], $parties, 'nl');

		$this->assertSame('Factuur', $data['labels']['title']);
		$this->assertSame('€ 24.200,00', InvoiceDocumentLanguageTest::plain($data['totals']['amountDue']));
		$epc = explode("\n", (string)$data['qr']['epc']);
		$this->assertSame(['EUR24200.00', '2026-0042'], [$epc[7], $epc[10]]);
		$this->assertSame('https://portal.example.nl/pay?pay=abc', $data['qr']['payLink']);
	}//end testAMunicipalityPaysByScanning()

	/**
	 * The open amount is amountDue, else gross minus paid; a layout without QR carries neither payload.
	 *
	 * @return void
	 */
	public function testOpenAmountAndTheLayoutSwitch(): void {
		$this->assertSame(150.0, InvoiceDocumentData::openAmount(['amountDue' => '150', 'grossAmount' => 200]));
		$this->assertSame(50.0, InvoiceDocumentData::openAmount(['grossAmount' => 200, 'paidAmount' => 150]));
		$this->assertSame('EUR', InvoiceDocumentData::currencyOf(['currency' => '']));

		$invoice = ['invoiceNumber' => '2026-0044', 'currency' => 'EUR', 'grossAmount' => 100, 'documentLanguage' => 'fr'];
		$parties = self::parties(['language' => 'en'], ['showQr' => false]);
		$data = $this->data(payLink: 'https://portal.example.nl/pay?pay=x')->assemble($invoice, [], $parties);

		$this->assertSame(['epc' => null, 'payLink' => null], $data['qr']);
		$this->assertSame('fr', $data['language'], 'the language recorded on the invoice wins');
		$this->assertSame('Facture', $data['labels']['title']);
	}//end testOpenAmountAndTheLayoutSwitch()
}//end class
