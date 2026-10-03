<?php

/**
 * Unit tests for SupplierInvoiceService (slice 05 of bookkeeping-purchase-order-3way).
 *
 * Covers REQ-PO3W-004 (UBL ingestion) + REQ-PO3W-007 (OCR ingestion) +
 * REQ-SI-002 (lifecycle transitions):
 *  - parseUblInvoice maps header + lines from a minimal UBL Invoice XML;
 *  - ingestUBLInvoice persists a SupplierInvoice at statusCode=received,
 *    sets ublSourceUri + peppolReceivedAt, and is idempotent on retry;
 *  - ingestUBLInvoice masks cross-tenant calls as "Administration not found"
 *    (ADR-005);
 *  - ingestPDFInvoice clamps the ocrConfidenceScore to [0, 1], persists
 *    statusCode=received and stores sourceFormat=pdf;
 *  - setStatus enforces ALLOWED_TRANSITIONS, rejects illegal moves, and
 *    stamps a per-state timestamp.
 *
 * The OpenRegister ObjectService is stubbed with an in-memory schema-keyed
 * store that honours equality filters so cross-administration data never
 * leaks. The stub mirrors the slice-02 PurchaseOrderServiceTest stub so
 * the two tests stay drop-in compatible.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Service\Tax\VatNumberCheck;
use OCA\Shillinq\Service\ViesService;
use OCA\Shillinq\Tests\Unit\Service\Purchasing\SupplierInvoiceChecksTest;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests the OpenRegister-backed SupplierInvoice ingestion service.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SupplierInvoiceServiceTest extends TestCase {

	/**
	 * Mock IAppConfig.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturn('shillinq');
		$this->logger = $this->createMock(LoggerInterface::class);

	}//end setUp()

	/**
	 * Build the service over an in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 * @param string|null $vies A VIES answer: the service then checks foreign sellers; null leaves the check out.
	 *
	 * @return SupplierInvoiceService
	 */
	private function buildService(
		array $data,
		array &$saved,
		array $accessibleAdministrations,
		?string $vies=null,
	): SupplierInvoiceService {
		// ADR-084: this file's own duck-typed stub reached the service through a
		// ContainerInterface mock, while `objectService:` got a bare createMock() —
		// so SupplierInvoiceService read an EMPTY double and the saveObject() path
		// returned its own input, which made three assertions here pass without the
		// store ever being consulted. The shared stub IMPLEMENTS the contract, so
		// PHP itself rejects it if a signature moves upstream.
		$stub = new InMemoryObjectServiceStub(data: $data, saveSink: $saved);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		$settings = $this->createStub(SettingsService::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');

		return new SupplierInvoiceService(
			appConfig: $this->appConfig,
			administrationContext: $administrationContext,
			logger: $this->logger,
			objectService: $stub,
			checks: new SupplierInvoiceChecks($stub, $settings),
			vatNumberCheck: $this->vatNumberCheck(store: $stub, settings: $settings, answer: $vies),
		);

	}//end buildService()

	/**
	 * Build a minimal but standards-shaped Peppol BIS Invoice XML.
	 *
	 * @return string
	 */
	private function ublXml(): string {
		return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
         xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"
         xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2">
    <cbc:ID>INV-ERS-2026-00445</cbc:ID>
    <cbc:IssueDate>2026-07-20</cbc:IssueDate>
    <cbc:DueDate>2026-08-19</cbc:DueDate>
    <cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
    <cac:AccountingSupplierParty>
        <cac:Party>
            <cac:PartyIdentification>
                <cbc:ID>vendor-ers-001</cbc:ID>
            </cac:PartyIdentification>
        </cac:Party>
    </cac:AccountingSupplierParty>
    <cac:PaymentMeans>
        <cbc:PaymentID>REF-001-9914</cbc:PaymentID>
    </cac:PaymentMeans>
    <cac:TaxTotal>
        <cbc:TaxAmount>840.00</cbc:TaxAmount>
    </cac:TaxTotal>
    <cac:LegalMonetaryTotal>
        <cbc:LineExtensionAmount>4000.00</cbc:LineExtensionAmount>
        <cbc:PayableAmount>4840.00</cbc:PayableAmount>
    </cac:LegalMonetaryTotal>
    <cac:InvoiceLine>
        <cbc:ID>1</cbc:ID>
        <cbc:InvoicedQuantity>2</cbc:InvoicedQuantity>
        <cbc:LineExtensionAmount>4000.00</cbc:LineExtensionAmount>
        <cac:Item>
            <cbc:Description>Coffee Pro 1</cbc:Description>
            <cac:SellersItemIdentification>
                <cbc:ID>COFFEE-PRO-1</cbc:ID>
            </cac:SellersItemIdentification>
            <cac:ClassifiedTaxCategory>
                <cbc:Percent>21</cbc:Percent>
            </cac:ClassifiedTaxCategory>
        </cac:Item>
        <cac:Price>
            <cbc:PriceAmount>2000.00</cbc:PriceAmount>
        </cac:Price>
    </cac:InvoiceLine>
</Invoice>
XML;

	}//end ublXml()

	/**
	 * parseUblInvoice maps header fields, totals (cents) and line items.
	 *
	 * @return void
	 */
	public function testParseUblInvoiceMapsHeaderAndLines(): void {
		$saved = [];
		$service = $this->buildService(data: [], saved: $saved, accessibleAdministrations: ['adm-1']);

		$parsed = $service->parseUblInvoice(ublXml: $this->ublXml());

		self::assertSame('INV-ERS-2026-00445', $parsed['invoiceNumber']);
		self::assertSame('vendor-ers-001', $parsed['supplierId']);
		self::assertSame('2026-07-20', $parsed['invoiceDate']);
		self::assertSame('2026-08-19', $parsed['dueDate']);
		self::assertSame('EUR', $parsed['currency']);
		self::assertSame('REF-001-9914', $parsed['paymentReference']);

		// Integer-cent invariant (4000.00 -> 400000; 840.00 -> 84000; 4840.00 -> 484000).
		self::assertSame(400000, $parsed['totalExclVat']);
		self::assertSame(84000, $parsed['totalVat']);
		self::assertSame(484000, $parsed['totalInclVat']);

		self::assertCount(1, $parsed['lines']);
		self::assertSame(1, $parsed['lines'][0]['lineNumber']);
		self::assertSame('COFFEE-PRO-1', $parsed['lines'][0]['productCode']);
		self::assertSame('Coffee Pro 1', $parsed['lines'][0]['description']);
		self::assertSame(2.0, $parsed['lines'][0]['quantity']);
		self::assertSame(200000, $parsed['lines'][0]['unitPrice']);
		self::assertSame(400000, $parsed['lines'][0]['lineExtension']);
		// UBL Percent 21 -> 0.21 fraction.
		self::assertEqualsWithDelta(0.21, $parsed['lines'][0]['vatRate'], 0.0001);

	}//end testParseUblInvoiceMapsHeaderAndLines()

	/**
	 * parseUblInvoice rejects malformed XML.
	 *
	 * @return void
	 */
	public function testParseUblInvoiceRejectsMalformedXml(): void {
		$saved = [];
		$service = $this->buildService(data: [], saved: $saved, accessibleAdministrations: ['adm-1']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('UBL Invoice XML is malformed');

		// The libxml parser emits a warning on parse failure; silence it to keep
		// the test output clean.
		$previous = libxml_use_internal_errors(true);
		try {
			$service->parseUblInvoice(ublXml: '<not-an-xml');
		} finally {
			libxml_use_internal_errors($previous);
		}

	}//end testParseUblInvoiceRejectsMalformedXml()

	/**
	 * parseUblInvoice rejects UBL documents missing the InvoiceNumber id.
	 *
	 * @return void
	 */
	public function testParseUblInvoiceRejectsMissingInvoiceNumber(): void {
		$saved = [];
		$service = $this->buildService(data: [], saved: $saved, accessibleAdministrations: ['adm-1']);

		$xml = '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" '
			. 'xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
			. '<cbc:IssueDate>2026-07-20</cbc:IssueDate>'
			. '</Invoice>';

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('UBL Invoice is missing InvoiceNumber');

		$service->parseUblInvoice(ublXml: $xml);

	}//end testParseUblInvoiceRejectsMissingInvoiceNumber()

	/**
	 * ingestUBLInvoice persists a SupplierInvoice at statusCode=received,
	 * records ublSourceUri + peppolReceivedAt, and embeds the line items.
	 *
	 * @return void
	 */
	public function testIngestUBLInvoicePersistsAtReceivedWithProvenance(): void {
		$saved = [];
		$service = $this->buildService(
			data: ['SupplierInvoice' => []],
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$persisted = $service->ingestUBLInvoice(
			administrationId: 'adm-1',
			ublXml: $this->ublXml(),
			context: [
				'peppolMessageId' => 'msg-2026-07-20-abcdef',
				'peppolReceivedAt' => '2026-07-20T10:15:00+02:00',
			]
		);

		self::assertSame('INV-ERS-2026-00445', $persisted['invoiceNumber']);
		// The party id names no payee, so it is kept as the identifier and
		// supplierId stays empty: a raw id there fails the uuid format.
		self::assertSame('vendor-ers-001', $persisted['supplierIdentifier']);
		self::assertArrayNotHasKey('supplierId', $persisted);
		self::assertSame('adm-1', $persisted['administrationId']);
		self::assertSame('received', $persisted['statusCode']);
		self::assertSame('ubl', $persisted['sourceFormat']);
		self::assertSame('peppol:msg-2026-07-20-abcdef', $persisted['ublSourceUri']);
		self::assertSame('2026-07-20T10:15:00+02:00', $persisted['peppolReceivedAt']);
		self::assertSame(484000, $persisted['totalInclVat']);
		self::assertCount(1, $persisted['lines']);
		// Service stamps an OR record id (the test stub mimics OR's behaviour).
		self::assertNotEmpty($persisted['id']);

		// Exactly one SupplierInvoice save.
		$invoiceSaves = array_filter(
			$saved,
			static fn (array $row): bool => $row['schema'] === 'SupplierInvoice'
		);
		self::assertCount(1, $invoiceSaves);

	}//end testIngestUBLInvoicePersistsAtReceivedWithProvenance()

	/**
	 * ingestUBLInvoice de-duplicates against an existing record so a
	 * Peppol delivery retry does not create a second SupplierInvoice.
	 *
	 * @return void
	 */
	public function testIngestUBLInvoiceIsIdempotentOnRetry(): void {
		$saved = [];
		$service = $this->buildService(
			data: ['SupplierInvoice' => []],
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$first = $service->ingestUBLInvoice(
			administrationId: 'adm-1',
			ublXml: $this->ublXml(),
			context: ['peppolMessageId' => 'msg-1']
		);
		$second = $service->ingestUBLInvoice(
			administrationId: 'adm-1',
			ublXml: $this->ublXml(),
			context: ['peppolMessageId' => 'msg-1-retry']
		);

		self::assertSame($first['id'], $second['id']);
		self::assertSame($first['invoiceNumber'], $second['invoiceNumber']);
		// Only one SupplierInvoice save occurred.
		$invoiceSaves = array_filter(
			$saved,
			static fn (array $row): bool => $row['schema'] === 'SupplierInvoice'
		);
		self::assertCount(1, $invoiceSaves);

	}//end testIngestUBLInvoiceIsIdempotentOnRetry()

	/**
	 * ingestUBLInvoice masks cross-tenant calls as "Administration not found"
	 * per ADR-005.
	 *
	 * @return void
	 */
	public function testIngestUBLInvoiceRejectsCrossTenant(): void {
		$saved = [];
		$service = $this->buildService(
			data: [],
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Administration not found');

		$service->ingestUBLInvoice(
			administrationId: 'adm-OTHER',
			ublXml: $this->ublXml(),
		);

	}//end testIngestUBLInvoiceRejectsCrossTenant()

	/**
	 * ingestPDFInvoice persists a SupplierInvoice with the OCR confidence
	 * score and sourceFormat=pdf.
	 *
	 * @return void
	 */
	public function testIngestPDFInvoicePersistsWithOcrConfidence(): void {
		$saved = [];
		$service = $this->buildService(
			data: ['SupplierInvoice' => []],
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$persisted = $service->ingestPDFInvoice(
			administrationId: 'adm-1',
			ocrPayload: [
				'invoiceNumber' => 'INV-PDF-2026-001',
				'supplierId' => 'vendor-pdf-001',
				'invoiceDate' => '2026-07-15',
				'currency' => 'EUR',
				'totalExclVat' => 1234.56,
				'totalVat' => 259.26,
				'totalInclVat' => 1493.82,
				'lines' => [
					['productCode' => 'A1', 'quantity' => 1, 'unitPrice' => 1234.56, 'lineExtension' => 1234.56, 'vatRate' => 0.21],
				],
			],
			confidenceScore: 0.873,
			context: ['pdfSourceUri' => 'nc-file:42']
		);

		self::assertSame('INV-PDF-2026-001', $persisted['invoiceNumber']);
		self::assertSame('received', $persisted['statusCode']);
		self::assertSame('pdf', $persisted['sourceFormat']);
		// Clamped + rounded to multipleOf 0.01.
		self::assertSame(0.87, $persisted['ocrConfidenceScore']);
		self::assertSame(123456, $persisted['totalExclVat']);
		self::assertSame(25926, $persisted['totalVat']);
		self::assertSame(149382, $persisted['totalInclVat']);
		self::assertSame('nc-file:42', $persisted['pdfSourceUri']);
		self::assertCount(1, $persisted['lines']);
		self::assertSame(123456, $persisted['lines'][0]['lineExtension']);

	}//end testIngestPDFInvoicePersistsWithOcrConfidence()

	/**
	 * ingestPDFInvoice clamps confidence scores outside [0, 1] (some OCR
	 * engines emit noisy values).
	 *
	 * @return void
	 */
	public function testIngestPDFInvoiceClampsConfidenceScore(): void {
		$saved = [];
		$service = $this->buildService(
			data: ['SupplierInvoice' => []],
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$highClamp = $service->ingestPDFInvoice(
			administrationId: 'adm-1',
			ocrPayload: ['invoiceNumber' => 'A', 'supplierId' => 'vendor-a'],
			confidenceScore: 1.34,
		);

		$lowClamp = $service->ingestPDFInvoice(
			administrationId: 'adm-1',
			ocrPayload: ['invoiceNumber' => 'B', 'supplierId' => 'vendor-b'],
			confidenceScore: -0.2,
		);

		self::assertSame(1.0, $highClamp['ocrConfidenceScore']);
		self::assertSame(0.0, $lowClamp['ocrConfidenceScore']);

	}//end testIngestPDFInvoiceClampsConfidenceScore()

	/**
	 * ingestPDFInvoice rejects payloads missing the mandatory identifiers
	 * (an OCR engine that cannot even read the invoice number cannot
	 * produce a usable SupplierInvoice — slice 08's exception workflow
	 * picks up these cases out-of-band).
	 *
	 * @return void
	 */
	public function testIngestPDFInvoiceRejectsPayloadWithoutInvoiceNumber(): void {
		$saved = [];
		$service = $this->buildService(
			data: [],
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('OCR payload is missing invoiceNumber');

		$service->ingestPDFInvoice(
			administrationId: 'adm-1',
			ocrPayload: ['supplierId' => 'vendor-only'],
			confidenceScore: 0.95,
		);

	}//end testIngestPDFInvoiceRejectsPayloadWithoutInvoiceNumber()

	/**
	 * setStatus drives the lifecycle from received -> matching -> matched
	 * and stamps a per-state timestamp.
	 *
	 * @return void
	 */
	public function testSetStatusFollowsHappyPath(): void {
		$saved = [];
		$data = [
			'SupplierInvoice' => [
				[
					'id' => 'inv-1',
					'administrationId' => 'adm-1',
					'invoiceNumber' => 'INV-A',
					'supplierId' => 'vendor-a',
					'statusCode' => 'received',
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$afterMatching = $service->setStatus(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			toStatus: 'matching'
		);
		self::assertSame('matching', $afterMatching['statusCode']);
		self::assertNotEmpty($afterMatching['matchingAt']);

		$afterMatched = $service->setStatus(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			toStatus: 'matched'
		);
		self::assertSame('matched', $afterMatched['statusCode']);

	}//end testSetStatusFollowsHappyPath()

	/**
	 * setStatus rejects an illegal transition (received -> paid skips the
	 * matching + approval steps).
	 *
	 * @return void
	 */
	public function testSetStatusRejectsIllegalTransition(): void {
		$saved = [];
		$data = [
			'SupplierInvoice' => [
				[
					'id' => 'inv-1',
					'administrationId' => 'adm-1',
					'invoiceNumber' => 'INV-A',
					'supplierId' => 'vendor-a',
					'statusCode' => 'received',
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Illegal transition from received to paid');

		$service->setStatus(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			toStatus: 'paid'
		);

	}//end testSetStatusRejectsIllegalTransition()

	/**
	 * setStatus masks cross-tenant access as "Supplier invoice not found"
	 * even when the invoice exists in another administration (ADR-005).
	 *
	 * @return void
	 */
	public function testSetStatusMasksCrossTenantAsNotFound(): void {
		$saved = [];
		$data = [
			'SupplierInvoice' => [
				[
					'id' => 'inv-other',
					'administrationId' => 'adm-OTHER',
					'invoiceNumber' => 'INV-X',
					'supplierId' => 'vendor-x',
					'statusCode' => 'received',
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Supplier invoice not found');

		$service->setStatus(
			administrationId: 'adm-OTHER',
			invoiceId: 'inv-other',
			toStatus: 'matching'
		);

	}//end testSetStatusMasksCrossTenantAsNotFound()

	/**
	 * A UBL invoice naming the supplier by KvK and VAT number, with its IBAN.
	 *
	 * @param string $invoiceNumber The number.
	 * @param string $iban          The IBAN in PaymentMeans.
	 * @param string $kvk           The KvK number in PartyLegalEntity.
	 *
	 * @return string
	 */
	private function ublFromDrukkerij(string $invoiceNumber, string $iban, string $kvk='12345678'): string {
		return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
         xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"
         xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2">
    <cbc:ID>{$invoiceNumber}</cbc:ID>
    <cbc:IssueDate>2026-09-01</cbc:IssueDate>
    <cbc:DocumentCurrencyCode>EUR</cbc:DocumentCurrencyCode>
    <cac:AccountingSupplierParty>
        <cac:Party>
            <cbc:EndpointID schemeID="0106">{$kvk}</cbc:EndpointID>
            <cac:PartyTaxScheme>
                <cbc:CompanyID>NL812345678B01</cbc:CompanyID>
                <cac:TaxScheme><cbc:ID>VAT</cbc:ID></cac:TaxScheme>
            </cac:PartyTaxScheme>
            <cac:PartyLegalEntity>
                <cbc:RegistrationName>Drukkerij Van der Meer B.V.</cbc:RegistrationName>
                <cbc:CompanyID schemeID="0106">{$kvk}</cbc:CompanyID>
            </cac:PartyLegalEntity>
        </cac:Party>
    </cac:AccountingSupplierParty>
    <cac:PaymentMeans>
        <cbc:PaymentMeansCode>58</cbc:PaymentMeansCode>
        <cac:PayeeFinancialAccount><cbc:ID>{$iban}</cbc:ID></cac:PayeeFinancialAccount>
    </cac:PaymentMeans>
    <cac:TaxTotal><cbc:TaxAmount>210.00</cbc:TaxAmount></cac:TaxTotal>
    <cac:LegalMonetaryTotal>
        <cbc:LineExtensionAmount>1000.00</cbc:LineExtensionAmount>
        <cbc:PayableAmount>1210.00</cbc:PayableAmount>
    </cac:LegalMonetaryTotal>
    <cac:InvoiceLine>
        <cbc:ID>1</cbc:ID>
        <cbc:InvoicedQuantity>1</cbc:InvoicedQuantity>
        <cbc:LineExtensionAmount>1000.00</cbc:LineExtensionAmount>
        <cac:Item><cbc:Description>Folders</cbc:Description><cac:ClassifiedTaxCategory><cbc:Percent>21</cbc:Percent></cac:ClassifiedTaxCategory></cac:Item>
        <cac:Price><cbc:PriceAmount>1000.00</cbc:PriceAmount></cac:Price>
    </cac:InvoiceLine>
</Invoice>
XML;

	}//end ublFromDrukkerij()

	/**
	 * REQ-PSII-001: a UBL invoice finds its payee by KvK and keeps the IBAN it names.
	 *
	 * @return void
	 */
	public function testAUblInvoiceFindsItsSupplierByKvk(): void {
		$saved = [];
		$service = $this->buildService(data: SupplierInvoiceChecksTest::records(), saved: $saved, accessibleAdministrations: [SupplierInvoiceChecksTest::ADMIN]);

		$persisted = $service->ingestUBLInvoice(administrationId: SupplierInvoiceChecksTest::ADMIN, ublXml: $this->ublFromDrukkerij('2026-0460', 'NL20INGB0001234567'));

		self::assertSame(SupplierInvoiceChecksTest::DRUKKERIJ, $persisted['supplierId']);
		self::assertSame('NL20INGB0001234567', $persisted['payeeIban']);
		self::assertArrayNotHasKey('ibanMismatch', $persisted);
		self::assertArrayNotHasKey('duplicateOfId', $persisted);
		unset($persisted['id']);
		self::assertSame([], RegisterSchema::errors('SupplierInvoice', $persisted));

	}//end testAUblInvoiceFindsItsSupplierByKvk()

	/**
	 * An invoice from an unknown supplier is saved with the identifier it states.
	 *
	 * @return void
	 */
	public function testAnUnknownSupplierIsSavedWithItsIdentifier(): void {
		$saved = [];
		$service = $this->buildService(data: SupplierInvoiceChecksTest::records(), saved: $saved, accessibleAdministrations: [SupplierInvoiceChecksTest::ADMIN]);

		$persisted = $service->ingestUBLInvoice(
			administrationId: SupplierInvoiceChecksTest::ADMIN,
			ublXml: str_replace('NL812345678B01', 'NL000000000B01', $this->ublFromDrukkerij('2026-0461', 'NL20INGB0001234567', '99999999'))
		);

		self::assertArrayNotHasKey('supplierId', $persisted);
		self::assertSame('99999999', $persisted['supplierIdentifier']);
		unset($persisted['id']);
		self::assertSame([], RegisterSchema::errors('SupplierInvoice', $persisted));

	}//end testAnUnknownSupplierIsSavedWithItsIdentifier()

	/**
	 * REQ-PSII-004: an imported invoice naming another IBAN carries the warning.
	 *
	 * @return void
	 */
	public function testAnImportedInvoiceWithAnotherIbanIsFlagged(): void {
		$saved = [];
		$service = $this->buildService(data: SupplierInvoiceChecksTest::records(), saved: $saved, accessibleAdministrations: [SupplierInvoiceChecksTest::ADMIN]);

		$persisted = $service->ingestUBLInvoice(administrationId: SupplierInvoiceChecksTest::ADMIN, ublXml: $this->ublFromDrukkerij('2026-0456', 'NL02ABNA0123456789'));

		self::assertSame('NL02ABNA0123456789 / NL20INGB0001234567', $persisted['ibanMismatch']);

	}//end testAnImportedInvoiceWithAnotherIbanIsFlagged()

	/**
	 * VIES calls made by the check, as URLs.
	 *
	 * @var list<string>
	 */
	private array $viesCalls = [];

	/**
	 * The real VIES check over a stubbed HTTP client.
	 *
	 * @param InMemoryObjectServiceStub $store    The store.
	 * @param SettingsService           $settings The settings.
	 * @param string|null               $answer   The VIES answer, or null for no check at all.
	 *
	 * @return VatNumberCheck|null
	 */
	private function vatNumberCheck(InMemoryObjectServiceStub $store, SettingsService $settings, ?string $answer): ?VatNumberCheck {
		if ($answer === null) {
			return null;
		}

		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(
			function (string $url) use ($answer): IResponse {
				$this->viesCalls[] = $url;
				$response = $this->createMock(IResponse::class);
				$response->method('getBody')->willReturn($answer);

				return $response;
			}
		);
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);

		return new VatNumberCheck(new ViesService($this->appConfig, $clients, $this->logger, $store), $store, $settings, $this->logger);

	}//end vatNumberCheck()

	/**
	 * Scenarios "A UBL invoice fills the seller's number" and "A Belgian bill arrives".
	 *
	 * @return void
	 */
	public function testABelgianBillCarriesTheSellersNumberAndItsCheck(): void {
		$saved = [];
		$service = $this->buildService(data: SupplierInvoiceChecksTest::records(), saved: $saved, accessibleAdministrations: [SupplierInvoiceChecksTest::ADMIN], vies: '{"valid": true, "name": "Softwarehuis BVBA"}');

		$persisted = $service->ingestUBLInvoice(
			administrationId: SupplierInvoiceChecksTest::ADMIN,
			ublXml: str_replace('NL812345678B01', 'BE0000000000', $this->ublFromDrukkerij('SH-2026-118', 'BE68539007547034', '99999999'))
		);

		self::assertSame('BE0000000000', $persisted['sellerVatId']);
		self::assertSame('valid', $persisted['sellerVatIdValidationStatus']);
		self::assertCount(1, $this->viesCalls);
		self::assertStringContainsString('/BE/vat/0000000000', $this->viesCalls[0]);
		unset($persisted['id']);
		self::assertSame([], RegisterSchema::errors('SupplierInvoice', $persisted));

	}//end testABelgianBillCarriesTheSellersNumberAndItsCheck()

	/**
	 * A Dutch bill keeps the seller's number and is not sent to VIES.
	 *
	 * @return void
	 */
	public function testADutchBillKeepsTheNumberWithoutACheck(): void {
		$saved = [];
		$service = $this->buildService(data: SupplierInvoiceChecksTest::records(), saved: $saved, accessibleAdministrations: [SupplierInvoiceChecksTest::ADMIN], vies: '{"valid": true}');

		$persisted = $service->ingestUBLInvoice(administrationId: SupplierInvoiceChecksTest::ADMIN, ublXml: $this->ublFromDrukkerij('2026-0470', 'NL20INGB0001234567'));

		self::assertSame('NL812345678B01', $persisted['sellerVatId']);
		self::assertArrayNotHasKey('sellerVatIdValidationStatus', $persisted);
		self::assertSame([], $this->viesCalls);

	}//end testADutchBillKeepsTheNumberWithoutACheck()
}//end class
