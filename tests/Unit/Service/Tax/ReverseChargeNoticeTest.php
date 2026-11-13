<?php

/**
 * Unit tests for ReverseChargeNotice and the outputs that print it: the UBL
 * document of an ARInvoice and the invoice PDF.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Tax
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-icp-and-reverse-charge/tasks.md#1-reverse-charge-on-the-invoice
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Tax;

use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use OCA\Shillinq\Service\InvoicePdfGenerator;
use OCA\Shillinq\Service\Tax\ReverseChargeNotice;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * A reverse-charged invoice says so (REQ-ICR-001).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
#[CoversClass(ReverseChargeNotice::class)]
#[CoversClass(ArInvoiceUblMapper::class)]
#[CoversClass(InvoicePdfGenerator::class)]
final class ReverseChargeNoticeTest extends TestCase {

	/**
	 * An issued invoice to a Belgian business with one intra-EU line.
	 *
	 * @param string $category The line's VAT category.
	 * @param string $buyerVat The buyer VAT number on the invoice.
	 *
	 * @return array<string,mixed>
	 */
	private static function invoice(string $category = 'K', string $buyerVat = 'BE0123456749'): array {
		return [
			'invoiceNumber' => '2026-0101',
			'customerId' => 'Brouwerij Het Anker BV',
			'invoiceDate' => '2026-08-03',
			'dueDate' => '2026-09-02',
			'netAmount' => 12000.0,
			'vatAmount' => 0.0,
			'grossAmount' => 12000.0,
			'currency' => 'EUR',
			'lifecycleState' => 'issued',
			'buyerVatId' => $buyerVat,
			'vatBreakdown' => [['category' => $category, 'taxableAmount' => 12000.0, 'taxAmount' => 0.0, 'rate' => 0]],
			'invoiceLines' => [
				['lineId' => '1', 'quantity' => 1.0, 'unitCode' => 'EA', 'netAmount' => 12000.0, 'itemName' => 'Brouwketel', 'netPrice' => 12000.0, 'vatCategory' => $category, 'vatRate' => 0],
			],
		];
	}//end invoice()

	/**
	 * An intra-EU line makes the invoice category K with the buyer's number.
	 *
	 * @return void
	 */
	public function testAnIntraEuLineIsReverseChargedWithTheBuyersNumber(): void {
		$this->assertSame(
			[
				'category' => 'K',
				'buyerVatNumber' => 'BE0123456749',
				'notice' => 'VAT reverse-charged',
				'noticeNl' => 'Btw verlegd',
				'exemptionReasonCode' => 'VATEX-EU-IC',
				'exemptionReason' => 'Intra-Community supply',
			],
			(new ReverseChargeNotice())->forInvoice(self::invoice())
		);
	}//end testAnIntraEuLineIsReverseChargedWithTheBuyersNumber()

	/**
	 * K wins over AE, AE stands alone, a standard invoice is not reverse-charged.
	 *
	 * @return void
	 */
	public function testTheCategoryFollowsTheLines(): void {
		$notice = new ReverseChargeNotice();
		$mixed = self::invoice('AE');
		$mixed['invoiceLines'][] = ['lineId' => '2', 'vatCategory' => 'k'];

		$this->assertSame('K', $notice->categoryOf($mixed));
		$this->assertSame('AE', $notice->categoryOf(self::invoice('AE')));
		$this->assertSame('VATEX-EU-AE', $notice->forInvoice(self::invoice('AE'))['exemptionReasonCode']);
		$this->assertNull($notice->forInvoice(self::invoice('S')));
		$this->assertNull($notice->categoryOf(['invoiceLines' => ['not a line'], 'vatBreakdown' => 'none']));
	}//end testTheCategoryFollowsTheLines()

	/**
	 * The buyer number comes from the invoice, else the customer's vatId, else vatNumber.
	 *
	 * @return void
	 */
	public function testTheBuyerNumberFallsBackOnTheCustomer(): void {
		$notice = new ReverseChargeNotice();
		$without = self::invoice('K', ' ');

		$this->assertSame('DE123456789', $notice->buyerVatNumber($without, ['vatId' => 'DE123456789', 'vatNumber' => 'old']));
		$this->assertSame('old', $notice->buyerVatNumber($without, ['vatNumber' => 'old']));
		$this->assertTrue($notice->lacksBuyerVatNumber($without));
		$this->assertFalse($notice->lacksBuyerVatNumber($without, ['vatId' => 'DE123456789']));
		$this->assertFalse($notice->lacksBuyerVatNumber(self::invoice('S', '')), 'a standard invoice needs no buyer number');
	}//end testTheBuyerNumberFallsBackOnTheCustomer()

	/**
	 * The UBL carries the notice, category K at 0% with its exemption reason, and the buyer number.
	 *
	 * @return void
	 */
	public function testTheUblCarriesCategoryKTheReasonAndTheBuyerNumber(): void {
		$xml = new SimpleXMLElement((new ArInvoiceUblMapper())->toNlciusXml(self::invoice()));
		$xml->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
		$xml->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

		$this->assertSame('Btw verlegd', (string)$xml->xpath('/*/cbc:Note')[0]);
		$category = $xml->xpath('//cac:TaxSubtotal/cac:TaxCategory')[0];
		$this->assertSame('K', (string)$category->children('cbc', true)->ID);
		$this->assertSame('0', (string)$category->children('cbc', true)->Percent);
		$this->assertSame('VATEX-EU-IC', (string)$category->children('cbc', true)->TaxExemptionReasonCode);
		$this->assertSame('K', (string)$xml->xpath('//cac:InvoiceLine/cac:Item/cac:ClassifiedTaxCategory/cbc:ID')[0]);
		$this->assertSame('BE0123456749', (string)$xml->xpath('//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID')[0]);
	}//end testTheUblCarriesCategoryKTheReasonAndTheBuyerNumber()

	/**
	 * A standard invoice's UBL carries no note and no exemption reason.
	 *
	 * @return void
	 */
	public function testAStandardInvoiceCarriesNoNoticeOrReason(): void {
		$invoice = self::invoice('S');
		$invoice['vatBreakdown'][0]['rate'] = 21;
		$xml = (new ArInvoiceUblMapper())->toNlciusXml($invoice);

		$this->assertStringNotContainsString('<cbc:Note>', $xml);
		$this->assertStringNotContainsString('TaxExemptionReason', $xml);
	}//end testAStandardInvoiceCarriesNoNoticeOrReason()

	/**
	 * The invoice PDF prints the notice with the buyer's number, and nothing on a standard invoice.
	 *
	 * @return void
	 */
	public function testThePdfPrintsTheNoticeAndTheBuyerNumber(): void {
		$generator = new InvoicePdfGenerator();

		$html = $generator->generatePdf(self::invoice(), [], [], ['legalName' => 'Brouwerij Het Anker BV'])['html'];
		$this->assertStringContainsString('<p class="reverse-charge"><strong>Btw verlegd</strong> &nbsp; BTW-nummer afnemer: BE0123456749</p>', $html);

		$plain = $generator->generatePdf(self::invoice('S'), [])['html'];
		$this->assertStringNotContainsString('reverse-charge', $plain);
	}//end testThePdfPrintsTheNoticeAndTheBuyerNumber()
}//end class
