<?php

/**
 * Unit tests for ThreeWayMatchingEngine (slice 06 of
 * bookkeeping-purchase-order-3way).
 *
 * Covers REQ-PO3W-004 (line-level matching + tolerance evaluation) +
 * REQ-PO3W-006 (configurable tolerance profiles):
 *  - matchLineItems() pairs invoice lines with PO + GRN lines by
 *    product code (exact match wins) and by line number (fallback);
 *  - calculateDivergence() computes price_delta in cents, quantity_delta
 *    in thousandths and vat_delta in cents;
 *  - evaluateMatch() writes an auto_approved ThreeWayMatch when every
 *    line is exact;
 *  - evaluateMatch() writes a within_tolerance ThreeWayMatch when
 *    divergences exist but all are within the applicable
 *    ToleranceProfile;
 *  - evaluateMatch() routes to exception_price when a price divergence
 *    exceeds tolerance;
 *  - evaluateMatch() routes to exception_missing_grn when no accepted
 *    GRN exists for the matched PO;
 *  - evaluateMatch() routes to exception_missing_po when the invoice
 *    carries no matchable PO reference (off-contract spend / member 07
 *    consolidation hand-off);
 *  - the SupplierInvoice transitions to matched / exception as part
 *    of the same call.
 *
 * The OpenRegister ObjectService is stubbed with an in-memory schema-keyed
 * store that honours equality filters so cross-administration data never
 * leaks; the stub mirrors slice-05's SupplierInvoiceServiceTest harness.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Service\ThreeWayMatchingEngine;
use OCA\Shillinq\Service\ToleranceProfileService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the OpenRegister-backed ThreeWayMatchingEngine.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ThreeWayMatchingEngineTest extends TestCase {
	/**
	 * Build the engine + its tolerance + invoice collaborators over an
	 * in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves
	 *                                              (mutable ref).
	 *
	 * @return ThreeWayMatchingEngine
	 */
	private function buildEngine(array $data, array &$saved): ThreeWayMatchingEngine {
		$stub = new class($data, $saved) {

			/**
			 * Schema rows.
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
			 * Id counter.
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
					$object['id'] = 'obj-' . $this->idCounter;
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

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$tolerance = new ToleranceProfileService(
			container: $container,
			appConfig: $appConfig,
			logger:    $logger,
		);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturn(true);
		$administrationContext->method('currentUserId')->willReturn('matcher-bot');

		$invoiceService = new SupplierInvoiceService(
			appConfig:             $appConfig,
			administrationContext: $administrationContext,
			logger:                $logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

		return new ThreeWayMatchingEngine(
			appConfig:        $appConfig,
			toleranceService: $tolerance,
			invoiceService:   $invoiceService,
			logger:           $logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildEngine()

	/**
	 * matchLineItems pairs invoice lines with PO + GRN lines on product
	 * code (exact match wins) and falls back to line number.
	 *
	 * @return void
	 */
	public function testMatchLineItemsPairsOnProductCodeThenLineNumber(): void {
		$saved = [];
		$engine = $this->buildEngine(data: [], saved: $saved);

		$invoiceLines = [
			['lineNumber' => 1, 'productCode' => 'COFFEE-PRO-1', 'quantity' => 10, 'unitPrice' => 200000, 'lineExtension' => 2000000, 'vatRate' => 0.21],
			['lineNumber' => 2, 'productCode' => '', 'quantity' => 5, 'unitPrice' => 500, 'lineExtension' => 2500, 'vatRate' => 0.21],
		];

		$poLines = [
			['id' => 'pol-1', 'poId' => 'po-1', 'lineNumber' => 1, 'productOrServiceCode' => 'COFFEE-PRO-1', 'quantityOrdered' => 10, 'unitPrice' => 200000, 'vatRate' => 2100, 'vatAmount' => 420000],
			['id' => 'pol-2', 'poId' => 'po-1', 'lineNumber' => 2, 'productOrServiceCode' => 'SERVICE-INSTALL', 'quantityOrdered' => 5, 'unitPrice' => 500, 'vatRate' => 2100, 'vatAmount' => 525],
		];

		$grnLines = [
			['id' => 'grl-1', 'grnId' => 'grn-1', 'poLineId' => 'pol-1', 'quantityReceived' => 10, 'quantityAccepted' => 10],
		];

		$tuples = $engine->matchLineItems(
			invoiceLines: $invoiceLines,
			poLines:      $poLines,
			grnLines:     $grnLines
		);

		self::assertCount(2, $tuples);
		self::assertSame('pol-1', $tuples[0]['poLine']['id']);
		self::assertSame('grl-1', $tuples[0]['grnLine']['id']);
		// Line 2 fell back to line-number matching (no product code in invoice).
		self::assertSame('pol-2', $tuples[1]['poLine']['id']);
		self::assertNull($tuples[1]['grnLine']);

	}//end testMatchLineItemsPairsOnProductCodeThenLineNumber()

	/**
	 * Integration test: auto-approve when every line is exact.
	 *
	 * @return void
	 */
	public function testEvaluateMatchAutoApprovesExactMatch(): void {
		$saved = [];
		$invoice = [
			'id' => 'inv-1',
			'invoiceNumber' => 'INV-001',
			'supplierId' => 'vendor-001',
			'totalInclVat' => 2420000,
			'statusCode' => 'received',
			'administrationId' => 'adm-1',
			'matchedPoIds' => ['po-1'],
			'lines' => [
				['lineNumber' => 1, 'productCode' => 'COFFEE-PRO-1', 'quantity' => 10.0, 'unitPrice' => 200000, 'lineExtension' => 2000000, 'vatRate' => 0.21],
			],
		];

		$data = [
			'SupplierInvoice' => [$invoice],
			'PurchaseOrder' => [
				['id' => 'po-1', 'poNumber' => 'PO-001', 'supplierId' => 'vendor-001', 'costCenter' => 'CC-1', 'projectCode' => 'PRJ-1', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'pol-1', 'poId' => 'po-1', 'lineNumber' => 1, 'productOrServiceCode' => 'COFFEE-PRO-1', 'quantityOrdered' => 10.0, 'unitPrice' => 200000, 'vatRate' => 2100, 'vatAmount' => 420000, 'glAccount' => '4400', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptNote' => [
				['id' => 'grn-1', 'grnNumber' => 'GRN-001', 'poIds' => ['po-1'], 'statusCode' => 'accepted', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptLine' => [
				['id' => 'grl-1', 'grnId' => 'grn-1', 'poLineId' => 'pol-1', 'quantityReceived' => 10.0, 'quantityAccepted' => 10.0, 'administrationId' => 'adm-1'],
			],
			'ToleranceProfile' => [
				['profileId' => 'TP-GLOBAL', 'scope' => 'global', 'priceToleranceAmount' => 1000, 'priceTolerancePercentage' => 50, 'quantityTolerancePercentage' => 100, 'dateToleranceDays' => 3, 'status' => 'active', 'administrationId' => 'adm-1'],
			],
			'ThreeWayMatch' => [],
		];

		$engine = $this->buildEngine(data: $data, saved: $saved);
		$result = $engine->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		self::assertSame(ThreeWayMatchingEngine::STATUS_AUTO_APPROVED, $result['matchStatus']);
		self::assertSame(['po-1'], $result['matchedPoIds']);
		self::assertSame(['grn-1'], $result['matchedGrnIds']);
		self::assertSame('adm-1', $result['administrationId']);
		self::assertSame('CC-1', $result['costCenter']);

		// SupplierInvoice transitioned received → matching → matched.
		$invoiceSaves = array_values(array_filter($saved, static fn ($r) => $r['schema'] === 'SupplierInvoice'));
		self::assertCount(2, $invoiceSaves);
		self::assertSame('matching', $invoiceSaves[0]['object']['statusCode']);
		self::assertSame('matched', $invoiceSaves[1]['object']['statusCode']);

	}//end testEvaluateMatchAutoApprovesExactMatch()

	/**
	 * REQ-PO3W-004 example: €18,547 invoice vs €18,500 PO is a 0.25 %
	 * delta — within the €10-absolute-OR-0.5%-percentage tolerance
	 * (whichever is more permissive). Lands as within_tolerance.
	 *
	 * @return void
	 */
	public function testEvaluateMatchAutoApproves025PercentDelta(): void {
		$saved = [];
		$invoice = [
			'id' => 'inv-1',
			'invoiceNumber' => 'INV-001',
			'supplierId' => 'vendor-001',
			'totalInclVat' => 1854700,
			'statusCode' => 'received',
			'administrationId' => 'adm-1',
			'matchedPoIds' => ['po-1'],
			'lines' => [
				// 180 × €103.04 = €18,547 — slightly higher unit price than PO.
				['lineNumber' => 1, 'productCode' => 'WIDGET', 'quantity' => 180.0, 'unitPrice' => 10304, 'lineExtension' => 1854720, 'vatRate' => 0.21],
			],
		];

		$data = [
			'SupplierInvoice' => [$invoice],
			'PurchaseOrder' => [
				['id' => 'po-1', 'poNumber' => 'PO-001', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				// PO: 180 × €102.78 = €18,500.40
				['id' => 'pol-1', 'poId' => 'po-1', 'lineNumber' => 1, 'productOrServiceCode' => 'WIDGET', 'quantityOrdered' => 180.0, 'unitPrice' => 10278, 'vatRate' => 2100, 'vatAmount' => 388508, 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptNote' => [
				['id' => 'grn-1', 'grnNumber' => 'GRN-001', 'poIds' => ['po-1'], 'statusCode' => 'accepted', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptLine' => [
				['id' => 'grl-1', 'grnId' => 'grn-1', 'poLineId' => 'pol-1', 'quantityReceived' => 180.0, 'quantityAccepted' => 180.0, 'administrationId' => 'adm-1'],
			],
			'ToleranceProfile' => [
				// €10 absolute OR 0.5% (50 bps) — the canonical global default.
				['profileId' => 'TP-GLOBAL', 'scope' => 'global', 'priceToleranceAmount' => 1000, 'priceTolerancePercentage' => 50, 'quantityTolerancePercentage' => 100, 'dateToleranceDays' => 3, 'status' => 'active', 'administrationId' => 'adm-1'],
			],
			'ThreeWayMatch' => [],
		];

		$engine = $this->buildEngine(data: $data, saved: $saved);
		$result = $engine->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		// unit price delta 26 cents = 0.25% — within tolerance via the
		// percentage threshold (50 bps × €102.78 ≈ 51 cents).
		self::assertSame(ThreeWayMatchingEngine::STATUS_WITHIN_TOLERANCE, $result['matchStatus']);

	}//end testEvaluateMatchAutoApproves025PercentDelta()

	/**
	 * Route to exception_price when the per-unit price delta exceeds both
	 * thresholds.
	 *
	 * @return void
	 */
	public function testEvaluateMatchRoutesToExceptionPriceWhenDeltaExceedsTolerance(): void {
		$saved = [];
		$invoice = [
			'id' => 'inv-1',
			'invoiceNumber' => 'INV-001',
			'supplierId' => 'vendor-001',
			'totalInclVat' => 5000000,
			'statusCode' => 'received',
			'administrationId' => 'adm-1',
			'matchedPoIds' => ['po-1'],
			'lines' => [
				['lineNumber' => 1, 'productCode' => 'WIDGET', 'quantity' => 10.0, 'unitPrice' => 200000, 'lineExtension' => 2000000, 'vatRate' => 0.21],
			],
		];

		$data = [
			'SupplierInvoice' => [$invoice],
			'PurchaseOrder' => [
				['id' => 'po-1', 'poNumber' => 'PO-001', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				// PO unit price €1,000 vs invoice €2,000 — 100% delta.
				['id' => 'pol-1', 'poId' => 'po-1', 'lineNumber' => 1, 'productOrServiceCode' => 'WIDGET', 'quantityOrdered' => 10.0, 'unitPrice' => 100000, 'vatRate' => 2100, 'vatAmount' => 210000, 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptNote' => [
				['id' => 'grn-1', 'grnNumber' => 'GRN-001', 'poIds' => ['po-1'], 'statusCode' => 'accepted', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptLine' => [
				['id' => 'grl-1', 'grnId' => 'grn-1', 'poLineId' => 'pol-1', 'quantityReceived' => 10.0, 'quantityAccepted' => 10.0, 'administrationId' => 'adm-1'],
			],
			'ToleranceProfile' => [
				['profileId' => 'TP-GLOBAL', 'scope' => 'global', 'priceToleranceAmount' => 1000, 'priceTolerancePercentage' => 50, 'quantityTolerancePercentage' => 100, 'dateToleranceDays' => 3, 'status' => 'active', 'administrationId' => 'adm-1'],
			],
			'ThreeWayMatch' => [],
		];

		$engine = $this->buildEngine(data: $data, saved: $saved);
		$result = $engine->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		self::assertSame(ThreeWayMatchingEngine::STATUS_EXCEPTION_PRICE, $result['matchStatus']);

		// Invoice transitioned received → matching → exception.
		$invoiceSaves = array_values(array_filter($saved, static fn ($r) => $r['schema'] === 'SupplierInvoice'));
		self::assertSame('matching', $invoiceSaves[0]['object']['statusCode']);
		self::assertSame('exception', $invoiceSaves[1]['object']['statusCode']);

	}//end testEvaluateMatchRoutesToExceptionPriceWhenDeltaExceedsTolerance()

	/**
	 * Route to exception_missing_grn when no accepted GRN exists.
	 *
	 * @return void
	 */
	public function testEvaluateMatchRoutesToExceptionMissingGrnWhenNoAcceptedReceipt(): void {
		$saved = [];
		$invoice = [
			'id' => 'inv-1',
			'invoiceNumber' => 'INV-001',
			'supplierId' => 'vendor-001',
			'statusCode' => 'received',
			'administrationId' => 'adm-1',
			'matchedPoIds' => ['po-1'],
			'lines' => [
				['lineNumber' => 1, 'productCode' => 'WIDGET', 'quantity' => 10.0, 'unitPrice' => 100000, 'lineExtension' => 1000000, 'vatRate' => 0.21],
			],
		];

		$data = [
			'SupplierInvoice' => [$invoice],
			'PurchaseOrder' => [
				['id' => 'po-1', 'poNumber' => 'PO-001', 'supplierId' => 'vendor-001', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'pol-1', 'poId' => 'po-1', 'lineNumber' => 1, 'productOrServiceCode' => 'WIDGET', 'quantityOrdered' => 10.0, 'unitPrice' => 100000, 'vatRate' => 2100, 'vatAmount' => 210000, 'administrationId' => 'adm-1'],
			],
			// No GRN at all — services invoice or pre-receipt billing.
			'GoodsReceiptNote' => [],
			'GoodsReceiptLine' => [],
			'ToleranceProfile' => [],
			'ThreeWayMatch' => [],
		];

		$engine = $this->buildEngine(data: $data, saved: $saved);
		$result = $engine->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		self::assertSame(ThreeWayMatchingEngine::STATUS_EXCEPTION_MISSING_GRN, $result['matchStatus']);

	}//end testEvaluateMatchRoutesToExceptionMissingGrnWhenNoAcceptedReceipt()

	/**
	 * Route to exception_missing_po when the invoice carries no resolvable
	 * PO id (off-contract spend / member-07 hand-off).
	 *
	 * @return void
	 */
	public function testEvaluateMatchRoutesToExceptionMissingPoWhenNoPoMatches(): void {
		$saved = [];
		$invoice = [
			'id' => 'inv-1',
			'invoiceNumber' => 'INV-001',
			'supplierId' => 'vendor-001',
			'statusCode' => 'received',
			'administrationId' => 'adm-1',
			// No matchedPoIds — engine cannot resolve a PO.
			'lines' => [
				['lineNumber' => 1, 'productCode' => 'WIDGET', 'quantity' => 10.0, 'unitPrice' => 100000, 'lineExtension' => 1000000, 'vatRate' => 0.21],
			],
		];

		$data = [
			'SupplierInvoice' => [$invoice],
			'PurchaseOrder' => [],
			'PurchaseOrderLine' => [],
			'GoodsReceiptNote' => [],
			'GoodsReceiptLine' => [],
			'ToleranceProfile' => [],
			'ThreeWayMatch' => [],
		];

		$engine = $this->buildEngine(data: $data, saved: $saved);
		$result = $engine->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		self::assertSame(ThreeWayMatchingEngine::STATUS_EXCEPTION_MISSING_PO, $result['matchStatus']);

	}//end testEvaluateMatchRoutesToExceptionMissingPoWhenNoPoMatches()

	/**
	 * REQ-PO3W-011 proof: a service PO — which will NEVER have a
	 * GoodsReceiptNote — previously had exactly one possible outcome:
	 * exception_missing_grn forever (see
	 * testEvaluateMatchRoutesToExceptionMissingGrnWhenNoAcceptedReceipt
	 * above, which is the goods-only-engine behaviour this same fixture
	 * shape would have hit before this change). With an accepted SvcReceipt
	 * confirming delivery, the same invoice now reaches auto_approved —
	 * a state that was structurally unreachable for any service PO prior
	 * to this change.
	 *
	 * @return void
	 */
	public function testEvaluateMatchAutoApprovesServicePoWithAcceptedServiceReceipt(): void {
		$saved = [];
		$invoice = [
			'id' => 'inv-svc-1',
			'invoiceNumber' => 'INV-CONSULT-001',
			'supplierId' => 'vendor-consult',
			'totalInclVat' => 605000,
			'statusCode' => 'received',
			'administrationId' => 'adm-1',
			'matchedPoIds' => ['po-svc-1'],
			'lines' => [
				[
					'lineNumber' => 1,
					'productCode' => 'SVC-CONSULTANCY-JUL',
					'quantity' => 1.0,
					'unitPrice' => 500000,
					'lineExtension' => 500000,
					'vatRate' => 0.21,
				],
			],
		];

		$data = [
			'SupplierInvoice' => [$invoice],
			'PurchaseOrder' => [
				[
					'id' => 'po-svc-1',
					'poNumber' => 'PO-SVC-001',
					'supplierId' => 'vendor-consult',
					'costCenter' => 'CC-CONSULT',
					'administrationId' => 'adm-1',
				],
			],
			'PurchaseOrderLine' => [
				[
					'id' => 'pol-svc-1',
					'poId' => 'po-svc-1',
					'lineNumber' => 1,
					'productOrServiceCode' => 'SVC-CONSULTANCY-JUL',
					'quantityOrdered' => 1.0,
					'unitPrice' => 500000,
					'vatRate' => 2100,
					'vatAmount' => 105000,
					'administrationId' => 'adm-1',
				],
			],
			// No GoodsReceiptNote at all — this PO line is a service and
			// will never physically receive goods.
			'GoodsReceiptNote' => [],
			'GoodsReceiptLine' => [],
			// The prestatieverklaring / service-entry-sheet third leg —
			// an approver confirmed 100% delivery for the July period.
			'SvcReceipt' => [
				[
					'id' => 'svr-1',
					'receiptNumber' => 'SVR-2026-adm-1-000001',
					'poIds' => ['po-svc-1'],
					'statusCode' => 'accepted',
					'administrationId' => 'adm-1',
				],
			],
			'SvcReceiptLine' => [
				[
					'id' => 'svrl-1',
					'serviceReceiptId' => 'svr-1',
					'poLineId' => 'pol-svc-1',
					'quantityReceived' => 1.0,
					'quantityAccepted' => 1.0,
					'administrationId' => 'adm-1',
				],
			],
			'ToleranceProfile' => [
				[
					'profileId' => 'TP-GLOBAL',
					'scope' => 'global',
					'priceToleranceAmount' => 1000,
					'priceTolerancePercentage' => 50,
					'quantityTolerancePercentage' => 100,
					'dateToleranceDays' => 3,
					'status' => 'active',
					'administrationId' => 'adm-1',
				],
			],
			'ThreeWayMatch' => [],
		];

		$engine = $this->buildEngine(data: $data, saved: $saved);
		$result = $engine->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-svc-1');

		self::assertSame(ThreeWayMatchingEngine::STATUS_AUTO_APPROVED, $result['matchStatus']);
		self::assertSame(['po-svc-1'], $result['matchedPoIds']);
		self::assertSame(['svr-1'], $result['matchedGrnIds']);
		self::assertSame('adm-1', $result['administrationId']);

		// SupplierInvoice transitioned received → matching → matched — a
		// state a service PO could never reach before this change (the
		// only possible outcome was exception_missing_grn, see the
		// sibling test above using the same fixture shape minus the
		// SvcReceipt).
		$invoiceSaves = array_values(array_filter($saved, static fn ($r) => $r['schema'] === 'SupplierInvoice'));
		self::assertCount(2, $invoiceSaves);
		self::assertSame('matching', $invoiceSaves[0]['object']['statusCode']);
		self::assertSame('matched', $invoiceSaves[1]['object']['statusCode']);

	}//end testEvaluateMatchAutoApprovesServicePoWithAcceptedServiceReceipt()

	/**
	 * Unknown invoice id is masked as not-found (no leak of cross-tenant ids).
	 *
	 * @return void
	 */
	public function testEvaluateMatchMasksUnknownInvoiceAsNotFound(): void {
		$saved = [];
		$engine = $this->buildEngine(data: ['SupplierInvoice' => []], saved: $saved);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Supplier invoice not found');

		$engine->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-missing');

	}//end testEvaluateMatchMasksUnknownInvoiceAsNotFound()
}//end class
