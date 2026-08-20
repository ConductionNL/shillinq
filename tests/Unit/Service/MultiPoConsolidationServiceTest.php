<?php

/**
 * Unit tests for MultiPoConsolidationService (slice 07 of
 * bookkeeping-purchase-order-3way).
 *
 * Covers REQ-PO3W-007:
 *  - enumerateCandidateTuples() honours the supplier filter, the
 *    product_code filter and the 30-day date-proximity window;
 *  - consolidateInvoice() fans out one ThreeWayMatch per invoice line —
 *    auto_approved for unambiguous trios, exception_missing_po for
 *    invoice lines with no candidates, pending for ambiguous lines;
 *  - disambiguateAmbiguousMatches() validates the chosen tuple against
 *    the live candidate set and persists the disambiguationChoice
 *    snapshot on the produced ThreeWayMatch (REQ-PO3W-007 / ADR-005).
 *
 * The OpenRegister ObjectService is stubbed with an in-memory
 * schema-keyed store that honours equality filters so cross-tenant data
 * never leaks. The stub mirrors the slice-05 SupplierInvoiceServiceTest
 * stub so the test layout stays drop-in compatible across the chain.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\MultiPoConsolidationService;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests the OpenRegister-backed multi-PO consolidation service.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class MultiPoConsolidationServiceTest extends TestCase {

	/**
	 * Mock ContainerInterface.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

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
	 * Mock IUserSession.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

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

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');

		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

	}//end setUp()

	/**
	 * Build the service over an in-memory ObjectService stub seeded with
	 * the supplied schema=>rows map.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 *
	 * @return MultiPoConsolidationService
	 */
	private function buildService(
		array $data,
		array &$saved,
		array $accessibleAdministrations,
	): MultiPoConsolidationService {
		$stub = new class($data, $saved) {

			/**
			 * Schema => rows.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			private array $data;

			/**
			 * Captured saves (mutable ref).
			 *
			 * @var array<int,array<string,mixed>>
			 */
			private array $saved;

			/**
			 * Active schema.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Auto-increment id counter for saved objects.
			 *
			 * @var integer
			 */
			private int $idCounter = 0;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
			 * @param array<int,array<string,mixed>> $saved Capture ref.
			 */
			public function __construct(array $data, array &$saved) {
				$this->data = $data;
				$this->saved = &$saved;
			}//end __construct()

			/**
			 * Fluent register setter.
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
			 * Return rows for the active schema, applying equality filters.
			 *
			 * @param array<string,mixed> $params Query parameters.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $params = []): array {
				$rows = ($this->data[$this->schema] ?? []);
				$filters = ($params['filters'] ?? []);
				if ($filters === []) {
					return $rows;
				}

				return array_values(
					array_filter(
						$rows,
						static function (array $row) use ($filters): bool {
							foreach ($filters as $key => $value) {
								if (($row[$key] ?? null) !== $value) {
									return false;
								}
							}

							return true;
						}
					)
				);
			}//end findAll()

			/**
			 * Capture a saved object; stamp an id when absent.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (isset($object['id']) === false || $object['id'] === '') {
					$this->idCounter++;
					$object['id'] = 'tw-' . $this->idCounter;
				}

				$rows = ($this->data[$this->schema] ?? []);
				$updated = false;
				foreach ($rows as $i => $row) {
					if (($row['id'] ?? null) === $object['id']) {
						$this->data[$this->schema][$i] = $object;
						$updated = true;
						break;
					}
				}

				if ($updated === false) {
					$this->data[$this->schema][] = $object;
				}

				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);
		$this->container = $container;

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		// Use the real SupplierInvoiceService against the same in-memory
		// stub so the slice-07 inline PO-link projection is exercised
		// end-to-end alongside the consolidation fan-out.
		$supplierInvoiceService = new SupplierInvoiceService(
			appConfig: $this->appConfig,
			administrationContext: $administrationContext,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

		return new MultiPoConsolidationService(
			appConfig: $this->appConfig,
			administrationContext: $administrationContext,
			userSession: $this->userSession,
			supplierInvoiceService: $supplierInvoiceService,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Seed data for a baseline consolidation scenario: one supplier with
	 * two POs placed in the same window for the same product code, both
	 * received via GRNs, against an invoice covering both PO lines.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function baselineSeed(): array {
		return [
			'SupplierInvoice' => [
				[
					'id' => 'inv-1',
					'invoiceNumber' => 'INV-MAAND-2026-07',
					'supplierId' => 'sup-1',
					'invoiceDate' => '2026-07-31',
					'currency' => 'EUR',
					'administrationId' => 'adm-1',
					'statusCode' => 'received',
					'lines' => [
						[
							'lineNumber' => 1,
							'productCode' => 'COFFEE-PRO-1',
							'description' => 'Coffee Pro 1',
							'quantity' => 2.0,
							'unitPrice' => 200000,
						],
						[
							'lineNumber' => 2,
							'productCode' => 'COFFEE-PRO-1',
							'description' => 'Coffee Pro 1 (week 30)',
							'quantity' => 2.0,
							'unitPrice' => 200000,
						],
					],
				],
			],
			'PurchaseOrder' => [
				[
					'id' => 'po-1',
					'poNumber' => 'PO-2026-001',
					'supplierId' => 'sup-1',
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'po-2',
					'poNumber' => 'PO-2026-002',
					'supplierId' => 'sup-1',
					'administrationId' => 'adm-1',
				],
				// Same product code under a different supplier — must
				// NEVER appear as a candidate (supplier filter).
				[
					'id' => 'po-other',
					'poNumber' => 'PO-2026-OTHER',
					'supplierId' => 'sup-2',
					'administrationId' => 'adm-1',
				],
			],
			'PurchaseOrderLine' => [
				[
					'id' => 'pol-1',
					'poId' => 'po-1',
					'productOrServiceCode' => 'COFFEE-PRO-1',
					'expectedDeliveryDate' => '2026-07-10',
					'unitPrice' => 200000,
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'pol-2',
					'poId' => 'po-2',
					'productOrServiceCode' => 'COFFEE-PRO-1',
					'expectedDeliveryDate' => '2026-07-25',
					'unitPrice' => 200000,
					'administrationId' => 'adm-1',
				],
				// Wrong-supplier PO line — supplier filter must drop it.
				[
					'id' => 'pol-other',
					'poId' => 'po-other',
					'productOrServiceCode' => 'COFFEE-PRO-1',
					'expectedDeliveryDate' => '2026-07-15',
					'unitPrice' => 200000,
					'administrationId' => 'adm-1',
				],
			],
			'GoodsReceiptLine' => [
				[
					'id' => 'grl-1',
					'grnId' => 'grn-1',
					'poLineId' => 'pol-1',
					'quantityReceived' => 2,
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'grl-2',
					'grnId' => 'grn-2',
					'poLineId' => 'pol-2',
					'quantityReceived' => 2,
					'administrationId' => 'adm-1',
				],
			],
			'GoodsReceiptNote' => [
				[
					'id' => 'grn-1',
					'poIds' => ['po-1'],
					'administrationId' => 'adm-1',
				],
				[
					'id' => 'grn-2',
					'poIds' => ['po-2'],
					'administrationId' => 'adm-1',
				],
			],
			'ThreeWayMatch' => [],
		];

	}//end baselineSeed()

	/**
	 * enumerateCandidateTuples returns supplier-scoped + product-scoped +
	 * date-window candidates and drops everything else.
	 *
	 * @return void
	 */
	public function testEnumerateCandidateTuplesHonoursSupplierProductAndDateWindow(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$invoice = ['supplierId' => 'sup-1', 'invoiceDate' => '2026-07-31'];
		$invoiceLine = ['lineNumber' => 1, 'productCode' => 'COFFEE-PRO-1'];

		$candidates = $service->enumerateCandidateTuples(
			administrationId: 'adm-1',
			invoice: $invoice,
			invoiceLine: $invoiceLine
		);

		self::assertCount(2, $candidates, 'Both supplier POs in window must be candidates');
		$poLineIds = array_map(static fn (array $candidate): string => (string)$candidate['poLineId'], $candidates);
		sort($poLineIds);
		self::assertSame(['pol-1', 'pol-2'], $poLineIds);

		// Wrong-supplier PO line must never appear.
		foreach ($candidates as $candidate) {
			self::assertNotSame('pol-other', $candidate['poLineId']);
		}

		// Each candidate carries the GRN line back-reference.
		$grnLineIds = array_map(
			static fn (array $candidate): string => (string)($candidate['grnLineId'] ?? ''),
			$candidates
		);
		sort($grnLineIds);
		self::assertSame(['grl-1', 'grl-2'], $grnLineIds);

	}//end testEnumerateCandidateTuplesHonoursSupplierProductAndDateWindow()

	/**
	 * enumerateCandidateTuples drops PO lines whose expectedDeliveryDate
	 * lies outside the 30-day window around the invoice date.
	 *
	 * @return void
	 */
	public function testEnumerateCandidateTuplesDropsLinesOutsideDateWindow(): void {
		$seed = $this->baselineSeed();
		// Push pol-1 to a date 60 days before the invoice — must be dropped.
		$seed['PurchaseOrderLine'][0]['expectedDeliveryDate'] = '2026-05-31';

		$saved = [];
		$service = $this->buildService(
			data: $seed,
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$invoice = ['supplierId' => 'sup-1', 'invoiceDate' => '2026-07-31'];
		$invoiceLine = ['lineNumber' => 1, 'productCode' => 'COFFEE-PRO-1'];

		$candidates = $service->enumerateCandidateTuples(
			administrationId: 'adm-1',
			invoice: $invoice,
			invoiceLine: $invoiceLine
		);

		self::assertCount(1, $candidates);
		self::assertSame('pol-2', $candidates[0]['poLineId']);

	}//end testEnumerateCandidateTuplesDropsLinesOutsideDateWindow()

	/**
	 * enumerateCandidateTuples returns a single PO-only candidate when
	 * a PO line has no GoodsReceiptLine back-references (services or
	 * pre-receipt invoice — exception_missing_grn upstream).
	 *
	 * @return void
	 */
	public function testEnumerateCandidateTuplesProducesPoOnlyWhenNoGrn(): void {
		$seed = $this->baselineSeed();
		// Remove all GRN lines.
		$seed['GoodsReceiptLine'] = [];

		$saved = [];
		$service = $this->buildService(
			data: $seed,
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$invoice = ['supplierId' => 'sup-1', 'invoiceDate' => '2026-07-31'];
		$invoiceLine = ['lineNumber' => 1, 'productCode' => 'COFFEE-PRO-1'];

		$candidates = $service->enumerateCandidateTuples(
			administrationId: 'adm-1',
			invoice: $invoice,
			invoiceLine: $invoiceLine
		);

		self::assertCount(2, $candidates);
		foreach ($candidates as $candidate) {
			self::assertNull($candidate['grnLineId']);
			self::assertSame('po-only', $candidate['matchSource']);
		}

	}//end testEnumerateCandidateTuplesProducesPoOnlyWhenNoGrn()

	/**
	 * enumerateCandidateTuples returns an empty array on cross-tenant
	 * access (masked as RuntimeException + 404 upstream).
	 *
	 * @return void
	 */
	public function testEnumerateCandidateTuplesRejectsCrossTenantAccess(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(RuntimeException::class);
		$service->enumerateCandidateTuples(
			administrationId: 'adm-2',
			invoice: ['supplierId' => 'sup-1', 'invoiceDate' => '2026-07-31'],
			invoiceLine: ['productCode' => 'COFFEE-PRO-1']
		);

	}//end testEnumerateCandidateTuplesRejectsCrossTenantAccess()

	/**
	 * consolidateInvoice with ambiguous matches leaves the line pending
	 * and creates NO ThreeWayMatch for that invoice line (the operator
	 * is later called through disambiguateAmbiguousMatches()).
	 *
	 * @return void
	 */
	public function testConsolidateInvoiceMarksAmbiguousLinesAsPending(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$results = $service->consolidateInvoice(
			administrationId: 'adm-1',
			invoiceId: 'inv-1'
		);

		self::assertCount(2, $results, 'One result per invoice line');
		foreach ($results as $result) {
			self::assertSame('pending', $result['status']);
			self::assertNull($result['matchId']);
			self::assertSame(2, $result['candidateCount']);
		}

		// No ThreeWayMatch persisted for ambiguous lines.
		$matchSaves = array_filter(
			$saved,
			static fn (array $save): bool => $save['schema'] === 'ThreeWayMatch'
		);
		self::assertCount(0, $matchSaves);

	}//end testConsolidateInvoiceMarksAmbiguousLinesAsPending()

	/**
	 * consolidateInvoice writes one auto_approved ThreeWayMatch per
	 * matched (PO line, GRN line, invoice line) trio when each invoice
	 * line has exactly one candidate.
	 *
	 * @return void
	 */
	public function testConsolidateInvoiceFansOutOneMatchPerUnambiguousTrio(): void {
		// Re-key the seed so each invoice line has a distinct product
		// code → only one candidate tuple per line.
		$seed = $this->baselineSeed();
		$seed['SupplierInvoice'][0]['lines'][1]['productCode'] = 'COFFEE-PRO-2';
		$seed['PurchaseOrderLine'][1]['productOrServiceCode'] = 'COFFEE-PRO-2';

		$saved = [];
		$service = $this->buildService(
			data: $seed,
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$results = $service->consolidateInvoice(
			administrationId: 'adm-1',
			invoiceId: 'inv-1'
		);

		self::assertCount(2, $results);
		self::assertSame('auto', $results[0]['status']);
		self::assertSame('auto', $results[1]['status']);
		self::assertSame(1, $results[0]['candidateCount']);
		self::assertSame(1, $results[1]['candidateCount']);

		$matchSaves = array_values(
			array_filter(
				$saved,
				static fn (array $save): bool => $save['schema'] === 'ThreeWayMatch'
			)
		);
		self::assertCount(2, $matchSaves);

		// Each match carries the trio identifiers.
		$lineNumbers = [];
		foreach ($matchSaves as $save) {
			$object = $save['object'];
			self::assertSame('inv-1', $object['invoiceId']);
			self::assertSame('auto_approved', $object['matchStatus']);
			self::assertSame('adm-1', $object['administrationId']);
			self::assertNotNull($object['poLineId']);
			self::assertNotNull($object['grnLineId']);
			self::assertNotNull($object['invoiceLineNumber']);
			self::assertNull($object['disambiguationChoice']);
			$lineNumbers[] = $object['invoiceLineNumber'];
		}

		sort($lineNumbers);
		self::assertSame([1, 2], $lineNumbers);

	}//end testConsolidateInvoiceFansOutOneMatchPerUnambiguousTrio()

	/**
	 * consolidateInvoice writes an exception_missing_po ThreeWayMatch
	 * for invoice lines that have no candidate tuples.
	 *
	 * @return void
	 */
	public function testConsolidateInvoiceWritesMissingPoExceptionForOrphanLines(): void {
		$seed = $this->baselineSeed();
		// Change second invoice line's product code so it has no PO match.
		$seed['SupplierInvoice'][0]['lines'][1]['productCode'] = 'COFFEE-MYSTERY';

		$saved = [];
		$service = $this->buildService(
			data: $seed,
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$results = $service->consolidateInvoice(
			administrationId: 'adm-1',
			invoiceId: 'inv-1'
		);

		self::assertCount(2, $results);
		// Line 1 still ambiguous (2 candidates), line 2 has no candidate.
		self::assertSame('pending', $results[0]['status']);
		self::assertSame('exception', $results[1]['status']);
		self::assertNotNull($results[1]['matchId']);
		self::assertSame(0, $results[1]['candidateCount']);

		$matchSaves = array_values(
			array_filter(
				$saved,
				static fn (array $save): bool => $save['schema'] === 'ThreeWayMatch'
			)
		);
		self::assertCount(1, $matchSaves);
		self::assertSame('exception_missing_po', $matchSaves[0]['object']['matchStatus']);
		self::assertSame(2, $matchSaves[0]['object']['invoiceLineNumber']);

	}//end testConsolidateInvoiceWritesMissingPoExceptionForOrphanLines()

	/**
	 * disambiguateAmbiguousMatches persists the choice when the chosen
	 * tuple is present in the live candidate set.
	 *
	 * @return void
	 */
	public function testDisambiguateRecordsChoiceAndPersistsSnapshot(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$match = $service->disambiguateAmbiguousMatches(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			invoiceLineNumber: 1,
			chosenPoLineId: 'pol-2',
			chosenGrnLineId: 'grl-2'
		);

		self::assertSame('inv-1', $match['invoiceId']);
		self::assertSame(1, $match['invoiceLineNumber']);
		self::assertSame('pol-2', $match['poLineId']);
		self::assertSame('grl-2', $match['grnLineId']);
		self::assertSame('COFFEE-PRO-1', $match['productCode']);
		self::assertSame(['po-2'], $match['matchedPoIds']);
		self::assertSame(['grn-2'], $match['matchedGrnIds']);
		self::assertSame('auto_approved', $match['matchStatus']);

		self::assertIsArray($match['disambiguationChoice']);
		self::assertSame(2, $match['disambiguationChoice']['candidateCount']);
		self::assertSame('pol-2', $match['disambiguationChoice']['chosenPoLineId']);
		self::assertSame('grl-2', $match['disambiguationChoice']['chosenGrnLineId']);
		self::assertSame(['pol-1'], $match['disambiguationChoice']['rejectedPoLineIds']);
		self::assertSame('alice', $match['disambiguationChoice']['chosenBy']);
		self::assertNotEmpty($match['disambiguationChoice']['chosenAt']);

	}//end testDisambiguateRecordsChoiceAndPersistsSnapshot()

	/**
	 * disambiguateAmbiguousMatches rejects a chosen tuple that is NOT
	 * in the live candidate set (forged client POST).
	 *
	 * @return void
	 */
	public function testDisambiguateRejectsForgedChoice(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Chosen tuple is not in the candidate set');
		$service->disambiguateAmbiguousMatches(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			invoiceLineNumber: 1,
			// pol-other belongs to supplier sup-2 — never in candidate set.
			chosenPoLineId: 'pol-other',
			chosenGrnLineId: ''
		);

	}//end testDisambiguateRejectsForgedChoice()

	/**
	 * disambiguateAmbiguousMatches rejects cross-tenant calls before
	 * touching candidate enumeration (ADR-005 IDOR-safe).
	 *
	 * @return void
	 */
	public function testDisambiguateRejectsCrossTenantAccess(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(RuntimeException::class);
		$service->disambiguateAmbiguousMatches(
			administrationId: 'adm-2',
			invoiceId: 'inv-1',
			invoiceLineNumber: 1,
			chosenPoLineId: 'pol-1',
			chosenGrnLineId: 'grl-1'
		);

	}//end testDisambiguateRejectsCrossTenantAccess()

	/**
	 * Slice-07 inline projection — every per-trio ThreeWayMatch is mirrored
	 * back to the SupplierInvoice document so the embedded invoice line
	 * carries linkedPoLineId / linkedPoId / linkedGrnLineId / linkedMatchId
	 * and the header's matchedPoIds + consolidatedAt reflect the matched
	 * POs (REQ-PO3W-007).
	 *
	 * @return void
	 */
	public function testConsolidateInvoiceMirrorsPoLinksOntoSupplierInvoiceDocument(): void {
		// Distinct product codes per invoice line so each line has exactly
		// one candidate (unambiguous fan-out path).
		$seed = $this->baselineSeed();
		$seed['SupplierInvoice'][0]['lines'][1]['productCode'] = 'COFFEE-PRO-2';
		$seed['PurchaseOrderLine'][1]['productOrServiceCode'] = 'COFFEE-PRO-2';

		$saved = [];
		$service = $this->buildService(
			data: $seed,
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$service->consolidateInvoice(
			administrationId: 'adm-1',
			invoiceId: 'inv-1'
		);

		// The latest SupplierInvoice save carries both per-line PO links
		// and the header-level matchedPoIds.
		$invoiceSaves = array_values(
			array_filter(
				$saved,
				static fn (array $save): bool => $save['schema'] === 'SupplierInvoice'
			)
		);
		self::assertNotEmpty($invoiceSaves, 'Inline projection must save the invoice');

		$latest = end($invoiceSaves)['object'];
		self::assertSame(['po-1', 'po-2'], $latest['matchedPoIds']);
		self::assertNotEmpty($latest['consolidatedAt']);

		$linesByNumber = [];
		foreach ($latest['lines'] as $line) {
			$linesByNumber[$line['lineNumber']] = $line;
		}

		self::assertSame('pol-1', $linesByNumber[1]['linkedPoLineId']);
		self::assertSame('po-1', $linesByNumber[1]['linkedPoId']);
		self::assertSame('grl-1', $linesByNumber[1]['linkedGrnLineId']);
		self::assertNotEmpty($linesByNumber[1]['linkedMatchId']);

		self::assertSame('pol-2', $linesByNumber[2]['linkedPoLineId']);
		self::assertSame('po-2', $linesByNumber[2]['linkedPoId']);
		self::assertSame('grl-2', $linesByNumber[2]['linkedGrnLineId']);
		self::assertNotEmpty($linesByNumber[2]['linkedMatchId']);

	}//end testConsolidateInvoiceMirrorsPoLinksOntoSupplierInvoiceDocument()

	/**
	 * Slice-07 inline projection — operator-resolved disambiguation also
	 * writes the chosen (PO line, GRN line, ThreeWayMatch) link back onto
	 * the SupplierInvoice document so the ambiguous line, once resolved,
	 * carries its linkedPoLineId inline.
	 *
	 * @return void
	 */
	public function testDisambiguateMirrorsChoiceOntoSupplierInvoiceDocument(): void {
		$saved = [];
		$service = $this->buildService(
			data: $this->baselineSeed(),
			saved: $saved,
			accessibleAdministrations: ['adm-1']
		);

		$service->disambiguateAmbiguousMatches(
			administrationId: 'adm-1',
			invoiceId: 'inv-1',
			invoiceLineNumber: 1,
			chosenPoLineId: 'pol-2',
			chosenGrnLineId: 'grl-2'
		);

		$invoiceSaves = array_values(
			array_filter(
				$saved,
				static fn (array $save): bool => $save['schema'] === 'SupplierInvoice'
			)
		);
		self::assertNotEmpty($invoiceSaves);

		$latest = end($invoiceSaves)['object'];
		self::assertSame(['po-2'], $latest['matchedPoIds']);

		$linesByNumber = [];
		foreach ($latest['lines'] as $line) {
			$linesByNumber[$line['lineNumber']] = $line;
		}

		self::assertSame('pol-2', $linesByNumber[1]['linkedPoLineId']);
		self::assertSame('po-2', $linesByNumber[1]['linkedPoId']);
		self::assertSame('grl-2', $linesByNumber[1]['linkedGrnLineId']);
		self::assertNotEmpty($linesByNumber[1]['linkedMatchId']);

	}//end testDisambiguateMirrorsChoiceOntoSupplierInvoiceDocument()
}//end class
