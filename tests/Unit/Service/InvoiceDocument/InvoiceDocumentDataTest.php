<?php

/**
 * Unit tests for the sales-invoice template data (REQ-SID-002 to REQ-SID-005).
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
use OCA\Shillinq\Service\InvoiceDocument\InvoiceAmountFormatter;
use OCA\Shillinq\Service\InvoiceDocument\InvoiceDocumentData;
use OCA\Shillinq\Service\InvoiceDocument\InvoiceLabels;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The assembled data over the design's two seed invoices.
 */
#[CoversClass(InvoiceDocumentData::class)]
#[UsesClass(InvoiceLabels::class)]
#[UsesClass(InvoiceAmountFormatter::class)]
#[UsesClass(EpcQrPayload::class)]
final class InvoiceDocumentDataTest extends TestCase {
	/**
	 * The creditor of both invoices.
	 *
	 * @var array<string,string>
	 */
	private const CREDITOR = ['legalName' => 'Adviesbureau Van Dijk', 'iban' => 'NL91ABNA0417164300', 'vatID' => 'NL001234567B01'];

	/**
	 * The assembler, with labels echoing their English source.
	 *
	 * @return InvoiceDocumentData
	 */
	private function data(): InvoiceDocumentData {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);

		return new InvoiceDocumentData(new InvoiceLabels($factory), new InvoiceAmountFormatter());

	}//end data()

	/**
	 * A pound invoice: every amount in pounds, no euro sign, no EPC code.
	 *
	 * @return void
	 */
	public function testAPoundInvoiceShowsPoundsAndNoEpcCode(): void {
		$data = $this->data()->assemble(
			invoice: ['invoiceNumber' => '2026-0043', 'currency' => 'GBP', 'netAmount' => 4800, 'vatAmount' => 0, 'grossAmount' => 4800],
			lines: [['lineNumber' => 1, 'description' => 'Consulting', 'billableUnits' => 40, 'rateApplied' => ['rateCents' => 12000], 'costAmount' => 4800, 'vatRate' => 0]],
			creditor: self::CREDITOR,
			recipient: ['legalName' => 'Brightside Consulting Ltd', 'language' => 'en'],
			layout: ['variant' => 'modern', 'primaryColor' => '#1B4F72'],
			language: 'en'
		);

		self::assertSame('GBP', $data['currency']);
		self::assertSame('£4,800.00', $data['invoice']['gross']);
		self::assertSame(
			['number' => 1, 'description' => 'Consulting', 'quantity' => '40', 'rate' => '£120.00', 'amount' => '£4,800.00', 'vatRate' => '0%'],
			$data['lines'][0]
		);
		self::assertStringNotContainsString('€', (string)json_encode($data, JSON_UNESCAPED_UNICODE));
		self::assertNull($data['qr']['epc']);
		self::assertSame('Invoice', $data['labels']['invoice']);
		self::assertSame('modern', $data['layout']['variant']);
		self::assertSame('Brightside Consulting Ltd', $data['recipient']['name']);

	}//end testAPoundInvoiceShowsPoundsAndNoEpcCode()

	/**
	 * A euro invoice in Dutch carries the EPC code for its amount and number.
	 *
	 * @return void
	 */
	public function testAEuroInvoiceCarriesTheEpcCode(): void {
		$data = $this->data()->assemble(
			invoice: ['invoiceNumber' => '2026-0042', 'currency' => 'EUR', 'grossAmount' => 24200],
			lines: [['lineId' => '1', 'itemName' => 'Advies', 'quantity' => 2, 'netPrice' => 100.0, 'netAmount' => 200.0, 'vatRate' => 21]],
			creditor: self::CREDITOR,
			recipient: ['legalName' => 'Gemeente Voorbeeld'],
			layout: [],
			language: 'nl'
		);

		self::assertSame("€\u{a0}24.200,00", $data['invoice']['gross']);
		self::assertSame("€\u{a0}100,00", $data['lines'][0]['rate']);
		self::assertSame('Advies', $data['lines'][0]['description']);
		self::assertStringContainsString("\nEUR24200.00\n", (string)$data['qr']['epc']);
		self::assertStringEndsWith("\n2026-0042", (string)$data['qr']['epc']);
		self::assertSame(InvoiceDocumentData::DEFAULT_LAYOUT + ['logo' => null], $data['layout']);

	}//end testAEuroInvoiceCarriesTheEpcCode()

	/**
	 * The EPC amount is what is still open, not the gross.
	 *
	 * @return void
	 */
	public function testTheEpcAmountIsTheOpenAmount(): void {
		$data = $this->data()->assemble(
			invoice: ['invoiceNumber' => 'F-9', 'currency' => 'EUR', 'grossAmount' => 1000, 'openAmount' => 250.5],
			lines: [],
			creditor: self::CREDITOR,
			recipient: [],
			layout: [],
			language: 'nl'
		);

		self::assertStringContainsString("\nEUR250.50\n", (string)$data['qr']['epc']);
		self::assertSame("€\u{a0}250,50", $data['invoice']['open']);

	}//end testTheEpcAmountIsTheOpenAmount()

	/**
	 * A layout that hides QR codes gets neither code; an unknown variant is classic.
	 *
	 * @return void
	 */
	public function testHiddenQrCodesAndAnUnknownVariant(): void {
		$data = $this->data()->assemble(
			invoice: ['invoiceNumber' => 'F-1', 'currency' => 'EUR', 'grossAmount' => 10],
			lines: [],
			creditor: self::CREDITOR,
			recipient: [],
			layout: ['showQr' => false, 'variant' => 'baroque', 'unknownField' => 'x'],
			language: 'nl',
			payLink: 'https://pay.example.org/abc'
		);

		self::assertSame(['epc' => null, 'payLink' => null], $data['qr']);
		self::assertSame('classic', $data['layout']['variant']);
		self::assertArrayNotHasKey('unknownField', $data['layout']);

	}//end testHiddenQrCodesAndAnUnknownVariant()

	/**
	 * Only an https payment link becomes a code; the logo is passed as given.
	 *
	 * @return void
	 */
	public function testPayLinkMustBeHttpsAndTheLogoIsEmbedded(): void {
		$base = [
			'invoice' => ['invoiceNumber' => 'F-1', 'currency' => 'GBP', 'grossAmount' => 10],
			'lines' => [],
			'creditor' => self::CREDITOR,
			'recipient' => [],
			'layout' => [],
			'language' => 'en',
		];

		$secure = $this->data()->assemble(...$base, payLink: 'https://pay.example.org/abc', logoDataUri: 'data:image/png;base64,AA==');
		$plain  = $this->data()->assemble(...$base, payLink: 'javascript:alert(1)');

		self::assertSame('https://pay.example.org/abc', $secure['qr']['payLink']);
		self::assertSame('data:image/png;base64,AA==', $secure['layout']['logo']);
		self::assertNull($plain['qr']['payLink']);

	}//end testPayLinkMustBeHttpsAndTheLogoIsEmbedded()

	/**
	 * The VAT breakdown is formatted in the invoice currency.
	 *
	 * @return void
	 */
	public function testTheVatBreakdownIsFormatted(): void {
		$data = $this->data()->assemble(
			invoice: ['currency' => 'EUR', 'summary' => ['breakdown' => [['rate' => 21, 'net' => 100, 'vat' => 21, 'gross' => 121]]]],
			lines: [],
			creditor: [],
			recipient: ['legalName' => 'X'],
			layout: [],
			language: 'en'
		);

		self::assertSame([['rate' => '21%', 'net' => '€100.00', 'vat' => '€21.00', 'gross' => '€121.00']], $data['vatBreakdown']);
		self::assertNull($data['qr']['epc']);

	}//end testTheVatBreakdownIsFormatted()
}//end class
