<?php

/**
 * Unit tests for ArInvoiceUblMapper (REQ-EINV-001).
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\EInvoice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/add-invoice-pdf-export-with-ubl-peppol-support/specs/bookkeeping-einvoicing-ubl-peppol/spec.md#req-einv-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\EInvoice;

use OCA\Shillinq\Service\EInvoice\ArInvoiceUblMapper;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SimpleXMLElement;

/**
 * Covers REQ-EINV-001 scenarios: conformant NLCIUS rendering + draft refusal.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ArInvoiceUblMapperTest extends TestCase {
	/**
	 * Build a realistic issued ARInvoice (mirrors design.md's seed scenario:
	 * 2026-0042, grossAmount 1210, vatAmount 210, netAmount 1000).
	 *
	 * @return array<string,mixed>
	 */
	private function issuedInvoice(): array {
		return [
			'invoiceNumber' => '2026-0042',
			'customerId' => 'DEB-0001',
			'administrationId' => 'adm-shillinq-demo',
			'invoiceDate' => '2026-06-10',
			'dueDate' => '2026-07-10',
			'netAmount' => 1000.0,
			'vatAmount' => 210.0,
			'grossAmount' => 1210.0,
			'currency' => 'EUR',
			'lifecycleState' => 'issued',
			'sellerName' => 'Shillinq Consultancy B.V.',
			'sellerIdentifier' => 'NL809876543B01',
			'sellerVatId' => 'NL809876543B01',
			'sellerTaxRegId' => '12340099',
			'sellerAddress' => 'Hoofdstraat 1, 1000 AA Amsterdam',
			'sellerCountryCode' => 'NL',
			'buyerVatId' => 'NL001234567B01',
			'buyerLegalRegId' => '12340001',
			'buyerAddress' => 'Stadhuisplein 1, 1100 AB Amsterdam',
			'buyerPeppolParticipantId' => '0106:00000000',
			'paymentTerms' => 'net 30',
			'vatBreakdown' => [
				[
					'category' => 'S',
					'taxableAmount' => 1000.0,
					'taxAmount' => 210.0,
					'rate' => 21,
				],
			],
			'invoiceLines' => [
				[
					'lineId' => '1',
					'quantity' => 2.0,
					'unitCode' => 'EA',
					'netAmount' => 600.0,
					'itemName' => 'Consultancy hours',
					'netPrice' => 300.0,
					'vatCategory' => 'S',
					'vatRate' => 0.21,
				],
				[
					'lineId' => '2',
					'quantity' => 1.0,
					'unitCode' => 'EA',
					'netAmount' => 400.0,
					'itemName' => 'Travel expenses',
					'netPrice' => 400.0,
					'vatCategory' => 'S',
					'vatRate' => 0.21,
				],
			],
		];

	}//end issuedInvoice()

	/**
	 * REQ-EINV-001 scenario 1: issued invoice renders conformant NLCIUS XML.
	 *
	 * @return void
	 */
	public function testIssuedInvoiceRendersConformantNlciusXml(): void {
		$mapper = new ArInvoiceUblMapper();
		$xml = $mapper->toNlciusXml(arInvoice: $this->issuedInvoice());

		// Well-formed XML.
		$doc = new SimpleXMLElement($xml);
		self::assertInstanceOf(SimpleXMLElement::class, $doc);

		self::assertStringContainsString(ArInvoiceUblMapper::CUSTOMIZATION_ID, $xml);
		self::assertStringContainsString(ArInvoiceUblMapper::PROFILE_ID, $xml);
		self::assertStringContainsString('<cbc:ID>2026-0042</cbc:ID>', $xml);

		// PayableAmount MUST equal gross (1210.00).
		self::assertMatchesRegularExpression(
			'#<cbc:PayableAmount currencyID="EUR">1210\.00</cbc:PayableAmount>#',
			$xml
		);

		// Each line carries cbc:ID, cbc:InvoicedQuantity, Price/PriceAmount, ClassifiedTaxCategory/Percent.
		self::assertStringContainsString('<cac:InvoiceLine><cbc:ID>1</cbc:ID>', $xml);
		self::assertStringContainsString('<cbc:InvoicedQuantity unitCode="EA">2</cbc:InvoicedQuantity>', $xml);
		self::assertStringContainsString('<cac:Price><cbc:PriceAmount currencyID="EUR">300.00</cbc:PriceAmount></cac:Price>', $xml);
		self::assertStringContainsString('<cbc:Percent>21</cbc:Percent>', $xml);

		self::assertSame(2, substr_count($xml, '<cac:InvoiceLine>'));

	}//end testIssuedInvoiceRendersConformantNlciusXml()

	/**
	 * REQ-EINV-001 scenario 2: a draft invoice cannot be rendered.
	 *
	 * @return void
	 */
	public function testDraftInvoiceRefusesToRender(): void {
		$mapper = new ArInvoiceUblMapper();
		$invoice = $this->issuedInvoice();
		$invoice['lifecycleState'] = 'draft';

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessageMatches('/issued/');

		$mapper->toNlciusXml(arInvoice: $invoice);

	}//end testDraftInvoiceRefusesToRender()

	/**
	 * XML values are properly escaped (defensive against XXE-style / markup
	 * injection via free-text fields like sellerName).
	 *
	 * @return void
	 */
	public function testSpecialCharactersAreEscaped(): void {
		$mapper = new ArInvoiceUblMapper();
		$invoice = $this->issuedInvoice();
		$invoice['sellerName'] = 'A & B <Consultancy> "Ltd"';

		$xml = $mapper->toNlciusXml(arInvoice: $invoice);

		$doc = new SimpleXMLElement($xml);
		self::assertInstanceOf(SimpleXMLElement::class, $doc);
		self::assertStringNotContainsString('<Consultancy>', $xml);
		self::assertStringContainsString('A &amp; B &lt;Consultancy&gt;', $xml);

	}//end testSpecialCharactersAreEscaped()

	/**
	 * REQ-EINV-010: the customer's reference is the buyer reference (BT-10),
	 * in UBL order right after the document currency.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/bookkeeping-einvoicing-ubl-peppol/spec.md (REQ-EINV-010)
	 */
	public function testCustomerReferenceIsTheBuyerReference(): void {
		$invoice = $this->issuedInvoice();
		$invoice['customerReference'] = 'PO-4711 & co';

		$xml = (new ArInvoiceUblMapper())->toNlciusXml(arInvoice: $invoice);

		self::assertInstanceOf(SimpleXMLElement::class, new SimpleXMLElement($xml));
		self::assertStringContainsString(
			'<cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode><cbc:BuyerReference>PO-4711 &amp; co</cbc:BuyerReference><cac:AccountingSupplierParty>',
			$xml
		);
	}//end testCustomerReferenceIsTheBuyerReference()

	/**
	 * REQ-EINV-010: no reference, no empty BuyerReference element.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/arinvoice-field-backfill-and-bt10/specs/bookkeeping-einvoicing-ubl-peppol/spec.md (REQ-EINV-010)
	 */
	public function testNoCustomerReferenceWritesNoBuyerReference(): void {
		$invoice = $this->issuedInvoice();
		$invoice['customerReference'] = '  ';

		$xml = (new ArInvoiceUblMapper())->toNlciusXml(arInvoice: $invoice);

		self::assertStringNotContainsString('BuyerReference', $xml);
	}//end testNoCustomerReferenceWritesNoBuyerReference()
	/**
	 * REQ-SDP-006: a down-payment invoice carries invoice type code 386.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-4.1
	 */
	public function testADownPaymentInvoiceIsTypeCode386(): void {
		$invoice = array_merge(
			$this->issuedInvoice(),
			['invoiceTypeCode' => '386', 'downPayment' => ['kind' => 'down-payment', 'orderReference' => 'order-117']]
		);

		$doc = new SimpleXMLElement((new ArInvoiceUblMapper())->toNlciusXml(arInvoice: $invoice));
		$doc->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');

		self::assertSame('386', (string)$doc->xpath('/*/cbc:InvoiceTypeCode')[0]);

	}//end testADownPaymentInvoiceIsTypeCode386()

	/**
	 * REQ-SDP-006, the spec scenario: the final invoice to Gemeente Voorbeeld
	 * carries the negative deduction line and a billing reference to the
	 * down-payment invoice, and its lines add up to its total (BR-CO-10).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sales-down-payments/tasks.md#task-4.1
	 */
	public function testAFinalInvoiceCarriesTheDeductionAndTheBillingReference(): void {
		$invoice = array_merge(
			$this->issuedInvoice(),
			[
				'netAmount' => 700.0,
				'vatAmount' => 147.0,
				'grossAmount' => 847.0,
				'precedingInvoiceReferences' => [['reference' => '2026-0030', 'issueDate' => '2026-05-01']],
				'downPayment' => ['kind' => 'final', 'orderReference' => 'order-9'],
			]
		);
		$invoice['invoiceLines'][] = [
			'lineId' => '3', 'quantity' => -1, 'unitCode' => 'C62', 'netAmount' => -300.0, 'netPrice' => 300.0,
			'itemName' => 'Down payment 2026-0030 deducted', 'vatCategory' => 'S', 'vatRate' => 0.21, 'downPaymentInvoiceId' => 'ar-30',
		];

		$xml = (new ArInvoiceUblMapper())->toNlciusXml(arInvoice: $invoice);
		$doc = new SimpleXMLElement($xml);
		$doc->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
		$doc->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');

		self::assertSame('2026-0030', (string)$doc->xpath('/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID')[0]);
		self::assertSame('2026-05-01', (string)$doc->xpath('/*/cac:BillingReference/cac:InvoiceDocumentReference/cbc:IssueDate')[0]);
		self::assertLessThan(
			strpos($xml, '<cac:AccountingSupplierParty>'),
			strpos($xml, '<cac:BillingReference>'),
			'UBL puts BillingReference before the seller'
		);

		$deduction = $doc->xpath('/*/cac:InvoiceLine[cbc:ID="3"]')[0];
		$deduction->registerXPathNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
		$deduction->registerXPathNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
		self::assertSame('-1', (string)$deduction->xpath('cbc:InvoicedQuantity')[0]);
		self::assertSame('-300.00', (string)$deduction->xpath('cbc:LineExtensionAmount')[0]);
		self::assertSame('300.00', (string)$deduction->xpath('cac:Price/cbc:PriceAmount')[0], 'BR-27: the price is never negative');

		$lineSum = 0.0;
		foreach ($doc->xpath('/*/cac:InvoiceLine/cbc:LineExtensionAmount') as $amount) {
			$lineSum += (float)$amount;
		}

		self::assertSame(700.0, round($lineSum, 2));
		self::assertSame('700.00', (string)$doc->xpath('/*/cac:LegalMonetaryTotal/cbc:LineExtensionAmount')[0], 'BR-CO-10');

	}//end testAFinalInvoiceCarriesTheDeductionAndTheBillingReference()
}//end class
