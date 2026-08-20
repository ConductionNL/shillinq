<?php

/**
 * Peppol UBL Invoice -> SupplierInvoice integration test for member 05 of
 * the bookkeeping-purchase-order-3way chain.
 *
 * Wires {@see PeppolInboundUblInvoiceListener} on top of
 * {@see SupplierInvoiceService} with an in-memory OpenRegister ObjectService
 * stub, then dispatches a real {@see ObjectCreatedEvent} carrying a
 * `PeppolInboundMessage` ObjectEntity whose payload is a Peppol BIS Invoice
 * XML, and asserts the listener:
 *
 *  - filters on documentType=Invoice (CreditNote / Order documents are
 *    ignored);
 *  - resolves administrationId from the message;
 *  - calls ingestUBLInvoice with the UBL payload + the Peppol message id;
 *  - produces a persisted SupplierInvoice at statusCode=received with the
 *    parsed UBL header/line items + ubl_source_uri + peppol_received_at.
 *
 * This is the Peppol-received UBL Invoice -> SupplierInvoice creation
 * integration test the slice-05 tasks call for.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion/tasks.md#tests
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\Shillinq\Listener\PeppolInboundUblInvoiceListener;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ListenerSchemaResolver;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Integration: Peppol UBL Invoice event -> persisted SupplierInvoice.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class SupplierInvoicePeppolIngestionIntegrationTest extends TestCase {

	/**
	 * Captured saves from the ObjectService stub, populated by the
	 * SupplierInvoiceService through the wired listener.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Build an in-memory OR ObjectService stub that captures saves and
	 * supports findAll filters.
	 *
	 * @return object
	 */
	private function objectServiceStub(): object {
		return new class($this->saved) {
			/**
			 * Reference to the test's $saved buffer.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * Active schema slug.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Increment id counter to stamp newly-persisted records.
			 *
			 * @var integer
			 */
			private int $idCounter = 0;

			/**
			 * Constructor.
			 *
			 * @param array<int,array<string,mixed>> $saved Capture buffer ref.
			 */
			public function __construct(array &$saved) {
				$this->saved = &$saved;
			}//end __construct()

			/**
			 * Fluent register setter (no-op).
			 *
			 * @param string $register Register slug.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}//end setRegister()

			/**
			 * Fluent schema setter.
			 *
			 * @param string $schema Schema slug.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}//end setSchema()

			/**
			 * Equality-filtered query over previously-captured saves of the
			 * active schema (so SupplierInvoiceService::findOne sees the
			 * record it just wrote — supporting the de-duplication path).
			 *
			 * @param array<string,mixed> $params Query params.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$schema = $this->schema;
				$filters = ($params['filters'] ?? []);

				$matches = [];
				foreach ($this->saved as $entry) {
					if ($entry['schema'] !== $schema) {
						continue;
					}

					$row = $entry['object'];
					$accept = true;
					foreach ($filters as $key => $value) {
						if (($row[$key] ?? null) !== $value) {
							$accept = false;
							break;
						}
					}

					if ($accept === true) {
						$matches[] = $row;
					}
				}

				return $matches;
			}//end findAll()

			/**
			 * Capture a save (stamp an id when absent).
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (isset($object['id']) === false || $object['id'] === '') {
					$this->idCounter++;
					$object['id'] = 'obj-' . $this->idCounter;
				}

				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

	}//end objectServiceStub()

	/**
	 * Build the listener wired to a service that accepts adm-1 and writes
	 * captures into $this->saved.
	 *
	 * @return PeppolInboundUblInvoiceListener
	 */
	private function buildListener(): PeppolInboundUblInvoiceListener {
		$stub = $this->objectServiceStub();

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static fn (string $administrationId): bool => $administrationId === 'adm-1'
		);

		$logger = $this->createMock(LoggerInterface::class);

		$service = new SupplierInvoiceService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		// OpenRegister stamps the numeric schema id on the entity; the slug
		// only ever reaches the listener through the resolver.
		$schemaResolver = $this->createMock(ListenerSchemaResolver::class);
		$schemaResolver->method('schemaSlug')
			->willReturn(PeppolInboundUblInvoiceListener::PEPPOL_INBOUND_SCHEMA);

		return new PeppolInboundUblInvoiceListener(
			supplierInvoices: $service,
			schemaResolver: $schemaResolver,
			logger: $logger,
		);

	}//end buildListener()

	/**
	 * Build a sample Peppol BIS Invoice XML (the same shape the supplier
	 * 'ErenteSchreuders' from the spec scenario would send).
	 *
	 * @return string
	 */
	private function peppolInvoiceXml(): string {
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
                <cbc:ID>erente-schreuders</cbc:ID>
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

	}//end peppolInvoiceXml()

	/**
	 * Construct a PeppolInboundMessage ObjectEntity with the supplied
	 * payload + metadata so the listener can dispatch it.
	 *
	 * @param array<string,mixed> $message Message payload.
	 *
	 * @return ObjectEntity
	 */
	private function peppolInboundEntity(array $message): ObjectEntity {
		$entity = new ObjectEntity();
		// The numeric schema **id**, exactly as OpenRegister stamps it
		// (`setSchema((string) $schema->getId())`) — never the slug.
		$entity->setSchema('6104');
		$entity->setObject($message);

		return $entity;
	}//end peppolInboundEntity()

	/**
	 * REQ-PO3W-004: a Peppol-received UBL Invoice becomes a SupplierInvoice
	 * record at statusCode=received with ubl_source_uri + peppol_received_at.
	 *
	 * @return void
	 */
	public function testPeppolUblInvoiceBecomesSupplierInvoice(): void {
		$listener = $this->buildListener();

		$event = new ObjectCreatedEvent(
			$this->peppolInboundEntity(
				[
					'documentType' => PeppolInboundUblInvoiceListener::DOCUMENT_TYPE_INVOICE,
					'administrationId' => 'adm-1',
					'peppolMessageId' => 'msg-2026-07-20-abcdef',
					'receivedAt' => '2026-07-20T10:15:00+02:00',
					'payload' => $this->peppolInvoiceXml(),
				]
			)
		);

		$listener->handle($event);

		$invoiceSaves = array_values(
			array_filter(
				$this->saved,
				static fn (array $row): bool => $row['schema'] === 'SupplierInvoice'
			)
		);
		self::assertCount(1, $invoiceSaves);

		$persisted = $invoiceSaves[0]['object'];
		self::assertSame('INV-ERS-2026-00445', $persisted['invoiceNumber']);
		self::assertSame('erente-schreuders', $persisted['supplierId']);
		self::assertSame('adm-1', $persisted['administrationId']);
		self::assertSame('received', $persisted['statusCode']);
		self::assertSame('ubl', $persisted['sourceFormat']);
		self::assertSame('peppol:msg-2026-07-20-abcdef', $persisted['ublSourceUri']);
		self::assertSame('2026-07-20T10:15:00+02:00', $persisted['peppolReceivedAt']);
		self::assertSame(484000, $persisted['totalInclVat']);
		self::assertCount(1, $persisted['lines']);
		self::assertSame('COFFEE-PRO-1', $persisted['lines'][0]['productCode']);

	}//end testPeppolUblInvoiceBecomesSupplierInvoice()

	/**
	 * The listener ignores non-Invoice Peppol document types (CreditNote,
	 * Order, etc.) — only Invoice flows to SupplierInvoiceService.
	 *
	 * @return void
	 */
	public function testListenerIgnoresNonInvoiceDocumentTypes(): void {
		$listener = $this->buildListener();

		$event = new ObjectCreatedEvent(
			$this->peppolInboundEntity(
				[
					'documentType' => 'CreditNote',
					'administrationId' => 'adm-1',
					'peppolMessageId' => 'msg-cn-1',
					'receivedAt' => '2026-07-20T10:15:00+02:00',
					'payload' => $this->peppolInvoiceXml(),
				]
			)
		);

		$listener->handle($event);

		$invoiceSaves = array_filter(
			$this->saved,
			static fn (array $row): bool => $row['schema'] === 'SupplierInvoice'
		);
		self::assertCount(0, $invoiceSaves);

	}//end testListenerIgnoresNonInvoiceDocumentTypes()

	/**
	 * Re-delivery of the same Peppol message (same invoiceNumber +
	 * supplierId + administrationId) does not create a second
	 * SupplierInvoice — REQ-PO3W-004 idempotency.
	 *
	 * @return void
	 */
	public function testPeppolRedeliveryIsIdempotent(): void {
		$listener = $this->buildListener();

		$event = new ObjectCreatedEvent(
			$this->peppolInboundEntity(
				[
					'documentType' => PeppolInboundUblInvoiceListener::DOCUMENT_TYPE_INVOICE,
					'administrationId' => 'adm-1',
					'peppolMessageId' => 'msg-1',
					'receivedAt' => '2026-07-20T10:15:00+02:00',
					'payload' => $this->peppolInvoiceXml(),
				]
			)
		);

		// Same Peppol Invoice delivered twice (the access point retries on
		// transient failure).
		$listener->handle($event);
		$listener->handle($event);

		$invoiceSaves = array_filter(
			$this->saved,
			static fn (array $row): bool => $row['schema'] === 'SupplierInvoice'
		);
		self::assertCount(1, $invoiceSaves);

	}//end testPeppolRedeliveryIsIdempotent()

	/**
	 * A Peppol message that arrives without an administrationId is
	 * skipped (not persisted) — the openconnector pipeline is expected to
	 * resolve tenant identity upstream; without it we cannot apply the
	 * ADR-005 IDOR gate.
	 *
	 * @return void
	 */
	public function testMissingAdministrationIdIsSkipped(): void {
		$listener = $this->buildListener();

		$event = new ObjectCreatedEvent(
			$this->peppolInboundEntity(
				[
					'documentType' => PeppolInboundUblInvoiceListener::DOCUMENT_TYPE_INVOICE,
					'peppolMessageId' => 'msg-orphan',
					'receivedAt' => '2026-07-20T10:15:00+02:00',
					'payload' => $this->peppolInvoiceXml(),
				]
			)
		);

		$listener->handle($event);

		$invoiceSaves = array_filter(
			$this->saved,
			static fn (array $row): bool => $row['schema'] === 'SupplierInvoice'
		);
		self::assertCount(0, $invoiceSaves);

	}//end testMissingAdministrationIdIsSkipped()
}//end class
