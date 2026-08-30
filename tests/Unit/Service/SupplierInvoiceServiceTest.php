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
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
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
	 *
	 * @return SupplierInvoiceService
	 */
	private function buildService(
		array $data,
		array &$saved,
		array $accessibleAdministrations,
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

		return new SupplierInvoiceService(
			appConfig: $this->appConfig,
			administrationContext: $administrationContext,
			logger: $this->logger,
			objectService: $stub,
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
		self::assertSame('vendor-ers-001', $persisted['supplierId']);
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
}//end class
