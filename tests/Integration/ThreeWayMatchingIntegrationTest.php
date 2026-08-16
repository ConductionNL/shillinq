<?php

/**
 * 3-way matching engine integration test (slice 06 of the
 * bookkeeping-purchase-order-3way chain).
 *
 * Wires {@see ThreeWayMatchingEngine} on top of
 * {@see ToleranceProfileService} + {@see SupplierInvoiceService} with an
 * in-memory OpenRegister ObjectService stub seeded with a SupplierInvoice,
 * a PurchaseOrder + PurchaseOrderLine, an accepted GoodsReceiptNote +
 * GoodsReceiptLine and a global ToleranceProfile, then walks the engine
 * through:
 *
 *  1. The REQ-PO3W-004 canonical example — €18,547 invoice against an
 *     €18,500 PO with a 180-unit GRN — lands as within_tolerance and
 *     transitions the invoice to matched.
 *  2. A €100/unit price divergence on the same fixture — lands as
 *     exception_price and transitions the invoice to exception.
 *
 * The test asserts the persisted ThreeWayMatch carries divergenceDetails,
 * the matched PO/GRN ids and the inherited cost-centre + project-code
 * dimensions, plus the SupplierInvoice lifecycle hops.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md#tests
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Integration;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\SupplierInvoiceService;
use OCA\Shillinq\Service\ThreeWayMatchingEngine;
use OCA\Shillinq\Service\ToleranceProfileService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Integration: REQ-PO3W-004 auto-approve + exception-routing flows.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ThreeWayMatchingIntegrationTest extends TestCase {

	/**
	 * Captured saves from the ObjectService stub.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $saved = [];

	/**
	 * Build an in-memory OR ObjectService stub seeded with the fixture
	 * `$data` and capturing saves into `$this->saved`.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema rows.
	 *
	 * @return object
	 */
	private function objectServiceStub(array $data): object {
		return new class($data, $this->saved) {
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
			 * Active schema slug.
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
					$object['id'] = 'twm-obj-' . $this->idCounter;
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

	}//end objectServiceStub()

	/**
	 * Build the full collaborator stack pointing at the supplied fixture.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Fixture rows.
	 *
	 * @return ThreeWayMatchingEngine
	 */
	private function buildEngine(array $data): ThreeWayMatchingEngine {
		$stub = $this->objectServiceStub(data: $data);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('canAccess')->willReturn(true);
		$administrationContext->method('currentUserId')->willReturn('matcher-bot');

		$tolerance = new ToleranceProfileService(
			container: $container,
			appConfig: $appConfig,
			logger:    $logger,
		);

		$invoiceService = new SupplierInvoiceService(
			appConfig:             $appConfig,
			administrationContext: $administrationContext,
			logger:                $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

		return new ThreeWayMatchingEngine(
			appConfig:        $appConfig,
			toleranceService: $tolerance,
			invoiceService:   $invoiceService,
			logger:           $logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end buildEngine()

	/**
	 * REQ-PO3W-004 canonical example — €18,547 invoice vs €18,500 PO
	 * with a 180-unit GRN; €47 / 0.25 % is within the
	 * €10-absolute-OR-0.5%-percentage global tolerance.
	 *
	 * @return void
	 */
	public function testCanonicalReqExampleAutoApprovesWithinTolerance(): void {
		$invoice = [
			'id' => 'inv-1',
			'invoiceNumber' => 'INV-ERS-2026-00445',
			'supplierId' => 'vendor-ers-001',
			'totalInclVat' => 1854700,
			'statusCode' => 'received',
			'administrationId' => 'adm-1',
			'matchedPoIds' => ['po-1'],
			'lines' => [
				['lineNumber' => 1, 'productCode' => 'WIDGET-180', 'quantity' => 180.0, 'unitPrice' => 10304, 'lineExtension' => 1854720, 'vatRate' => 0.21],
			],
		];

		$data = [
			'SupplierInvoice' => [$invoice],
			'PurchaseOrder' => [
				['id' => 'po-1', 'poNumber' => 'PO-2026-0001', 'supplierId' => 'vendor-ers-001', 'costCenter' => 'CC-IT-OPERATIONS', 'projectCode' => 'PRJ-OFFICE-REFRESH-2026', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'pol-1', 'poId' => 'po-1', 'lineNumber' => 1, 'productOrServiceCode' => 'WIDGET-180', 'quantityOrdered' => 180.0, 'unitPrice' => 10278, 'vatRate' => 2100, 'vatAmount' => 388508, 'glAccount' => '4400', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptNote' => [
				['id' => 'grn-1', 'grnNumber' => 'GRN-2026-0011', 'poIds' => ['po-1'], 'statusCode' => 'accepted', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptLine' => [
				['id' => 'grl-1', 'grnId' => 'grn-1', 'poLineId' => 'pol-1', 'quantityReceived' => 180.0, 'quantityAccepted' => 180.0, 'administrationId' => 'adm-1'],
			],
			'ToleranceProfile' => [
				['profileId' => 'TP-GLOBAL-DEFAULT', 'scope' => 'global', 'priceToleranceAmount' => 1000, 'priceTolerancePercentage' => 50, 'quantityTolerancePercentage' => 100, 'dateToleranceDays' => 3, 'status' => 'active', 'administrationId' => 'adm-1'],
			],
			'ThreeWayMatch' => [],
		];

		$engine = $this->buildEngine(data: $data);
		$match = $engine->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		self::assertSame(ThreeWayMatchingEngine::STATUS_WITHIN_TOLERANCE, $match['matchStatus']);
		self::assertSame(['po-1'], $match['matchedPoIds']);
		self::assertSame(['grn-1'], $match['matchedGrnIds']);
		self::assertSame('CC-IT-OPERATIONS', $match['costCenter']);
		self::assertSame('PRJ-OFFICE-REFRESH-2026', $match['projectCode']);

		$divergences = $match['divergenceDetails'];
		self::assertNotEmpty($divergences);

		// The divergence for unitPrice carries the resolved
		// ToleranceProfile id.
		$priceDelta = null;
		foreach ($divergences as $entry) {
			if ($entry['field'] === 'unitPrice') {
				$priceDelta = $entry;
				break;
			}
		}

		self::assertNotNull($priceDelta);
		self::assertSame(10278, $priceDelta['expected']);
		self::assertSame(10304, $priceDelta['actual']);
		self::assertSame(26, $priceDelta['deltaCents']);
		self::assertSame('TP-GLOBAL-DEFAULT', $priceDelta['toleranceProfileId']);

		// SupplierInvoice transitioned received → matching → matched.
		$invoiceSaves = array_values(array_filter($this->saved, static fn ($r) => $r['schema'] === 'SupplierInvoice'));
		self::assertCount(2, $invoiceSaves);
		self::assertSame('matching', $invoiceSaves[0]['object']['statusCode']);
		self::assertSame('matched', $invoiceSaves[1]['object']['statusCode']);

	}//end testCanonicalReqExampleAutoApprovesWithinTolerance()

	/**
	 * Same fixture with a €1,000-per-unit price divergence routes to
	 * exception_price and transitions the invoice to exception.
	 *
	 * @return void
	 */
	public function testPriceVarianceAboveToleranceRoutesToException(): void {
		$invoice = [
			'id' => 'inv-1',
			'invoiceNumber' => 'INV-ERS-2026-00446',
			'supplierId' => 'vendor-ers-001',
			'totalInclVat' => 5000000,
			'statusCode' => 'received',
			'administrationId' => 'adm-1',
			'matchedPoIds' => ['po-1'],
			'lines' => [
				['lineNumber' => 1, 'productCode' => 'WIDGET-180', 'quantity' => 180.0, 'unitPrice' => 110000, 'lineExtension' => 19800000, 'vatRate' => 0.21],
			],
		];

		$data = [
			'SupplierInvoice' => [$invoice],
			'PurchaseOrder' => [
				['id' => 'po-1', 'poNumber' => 'PO-2026-0001', 'supplierId' => 'vendor-ers-001', 'costCenter' => 'CC-1', 'projectCode' => 'PRJ-1', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				// €1,000 per unit — invoice claims €1,100 = 10% delta.
				['id' => 'pol-1', 'poId' => 'po-1', 'lineNumber' => 1, 'productOrServiceCode' => 'WIDGET-180', 'quantityOrdered' => 180.0, 'unitPrice' => 100000, 'vatRate' => 2100, 'vatAmount' => 3780000, 'glAccount' => '4400', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptNote' => [
				['id' => 'grn-1', 'grnNumber' => 'GRN-2026-0011', 'poIds' => ['po-1'], 'statusCode' => 'accepted', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptLine' => [
				['id' => 'grl-1', 'grnId' => 'grn-1', 'poLineId' => 'pol-1', 'quantityReceived' => 180.0, 'quantityAccepted' => 180.0, 'administrationId' => 'adm-1'],
			],
			'ToleranceProfile' => [
				['profileId' => 'TP-GLOBAL-DEFAULT', 'scope' => 'global', 'priceToleranceAmount' => 1000, 'priceTolerancePercentage' => 50, 'quantityTolerancePercentage' => 100, 'dateToleranceDays' => 3, 'status' => 'active', 'administrationId' => 'adm-1'],
			],
			'ThreeWayMatch' => [],
		];

		$engine = $this->buildEngine(data: $data);
		$match = $engine->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		self::assertSame(ThreeWayMatchingEngine::STATUS_EXCEPTION_PRICE, $match['matchStatus']);

		// SupplierInvoice transitioned received → matching → exception.
		$invoiceSaves = array_values(array_filter($this->saved, static fn ($r) => $r['schema'] === 'SupplierInvoice'));
		self::assertSame('matching', $invoiceSaves[0]['object']['statusCode']);
		self::assertSame('exception', $invoiceSaves[1]['object']['statusCode']);

	}//end testPriceVarianceAboveToleranceRoutesToException()

	/**
	 * Supplier-scoped zero-tolerance profile overrides the global default
	 * (REQ-PO3W-006 scenario from the spec).
	 *
	 * @return void
	 */
	public function testSupplierScopedZeroToleranceRoutesToExceptionOnAnyDelta(): void {
		$invoice = [
			'id' => 'inv-1',
			'invoiceNumber' => 'INV-ERS-2026-00447',
			'supplierId' => 'vendor-strict',
			'statusCode' => 'received',
			'administrationId' => 'adm-1',
			'matchedPoIds' => ['po-1'],
			'lines' => [
				// 1-cent unit-price delta — well within the global 0.5 %
				// tolerance, but the supplier-scoped profile is zero.
				['lineNumber' => 1, 'productCode' => 'WIDGET-180', 'quantity' => 180.0, 'unitPrice' => 10279, 'lineExtension' => 1850220, 'vatRate' => 0.21],
			],
		];

		$data = [
			'SupplierInvoice' => [$invoice],
			'PurchaseOrder' => [
				['id' => 'po-1', 'poNumber' => 'PO-2026-0001', 'supplierId' => 'vendor-strict', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'pol-1', 'poId' => 'po-1', 'lineNumber' => 1, 'productOrServiceCode' => 'WIDGET-180', 'quantityOrdered' => 180.0, 'unitPrice' => 10278, 'vatRate' => 2100, 'vatAmount' => 388508, 'glAccount' => '4400', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptNote' => [
				['id' => 'grn-1', 'grnNumber' => 'GRN-2026-0011', 'poIds' => ['po-1'], 'statusCode' => 'accepted', 'administrationId' => 'adm-1'],
			],
			'GoodsReceiptLine' => [
				['id' => 'grl-1', 'grnId' => 'grn-1', 'poLineId' => 'pol-1', 'quantityReceived' => 180.0, 'quantityAccepted' => 180.0, 'administrationId' => 'adm-1'],
			],
			'ToleranceProfile' => [
				['profileId' => 'TP-GLOBAL-DEFAULT', 'scope' => 'global', 'priceToleranceAmount' => 1000, 'priceTolerancePercentage' => 50, 'quantityTolerancePercentage' => 100, 'dateToleranceDays' => 3, 'status' => 'active', 'administrationId' => 'adm-1'],
				['profileId' => 'TP-VENDOR-STRICT', 'scope' => 'supplier', 'scopeReference' => 'vendor-strict', 'priceToleranceAmount' => 0, 'priceTolerancePercentage' => 0, 'quantityTolerancePercentage' => 0, 'dateToleranceDays' => 0, 'status' => 'active', 'administrationId' => 'adm-1'],
			],
			'ThreeWayMatch' => [],
		];

		$engine = $this->buildEngine(data: $data);
		$match = $engine->evaluateMatch(administrationId: 'adm-1', invoiceId: 'inv-1');

		self::assertSame(ThreeWayMatchingEngine::STATUS_EXCEPTION_PRICE, $match['matchStatus']);

	}//end testSupplierScopedZeroToleranceRoutesToExceptionOnAnyDelta()
}//end class
