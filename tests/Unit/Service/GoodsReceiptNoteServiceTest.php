<?php

/**
 * Unit tests for GoodsReceiptNoteService.
 *
 * Covers slice 04 of bookkeeping-purchase-order-3way (REQ-GRN-001 /
 * REQ-PO3W-003) at the line-allocation level:
 *  - createGRN materialises a per-administration grn_number and a
 *    server-derived receivedBy from the session;
 *  - addGRNLine validates that quantityAccepted + quantityRejected do not
 *    exceed quantityReceived (partial-receipt allocator) and demands a
 *    rejectionReason when rejected > 0;
 *  - multi-PO matching: a GRN line whose poLineId belongs to a PO outside
 *    the GRN's po_ids[] is rejected with "does not belong to this GRN".
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\GoodsReceiptNoteService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the line-allocation logic of the GRN service.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class GoodsReceiptNoteServiceTest extends TestCase {

	/**
	 * Build a service + in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param string $userId Authenticated uid.
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 *
	 * @return GoodsReceiptNoteService
	 */
	private function buildService(
		array $data,
		array &$saved,
		string $userId,
		array $accessibleAdministrations,
	): GoodsReceiptNoteService {
		$stub = $this->buildObjectServiceStub(data: $data, saved: $saved);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$logger = $this->createMock(LoggerInterface::class);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('currentUserId')->willReturn($userId);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		return new GoodsReceiptNoteService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build a mutable in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 *
	 * @return object
	 */
	private function buildObjectServiceStub(array $data, array &$saved): object {
		return new class($data, $saved) {
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
			 * Auto-increment id counter.
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
			 * Resolve all rows for the active schema, applying equality filters.
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
			 * Save the object into the active schema bucket. Auto-stamps id.
			 *
			 * @param array<string,mixed> $object Object payload.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (isset($object['id']) === false || $object['id'] === '') {
					$this->idCounter++;
					$object['id'] = 'obj-' . $this->idCounter;
				} else {
					$this->data[$this->schema] = array_values(
						array_filter(
							($this->data[$this->schema] ?? []),
							static fn (array $row): bool => (($row['id'] ?? null) !== $object['id'])
						)
					);
				}

				$this->data[$this->schema][] = $object;
				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

	}//end buildObjectServiceStub()

	/**
	 * createGRN stamps a per-administration grn_number, the server-derived
	 * receivedBy, and the 'received' lifecycle state.
	 *
	 * @return void
	 */
	public function testCreateGrnSeedsHeaderFields(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1', 'poNumber' => 'PO-2026-adm-1-000001'],
			],
			'GoodsReceiptNote' => [],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'warehouse-01',
			accessibleAdministrations: ['adm-1']
		);

		$grn = $service->createGRN(
			administrationId: 'adm-1',
			payload: [
				'poIds' => ['po-1'],
				'carrier' => 'PostNL',
				'deliveryNoteReference' => 'DN-NW-2026-7791',
			]
		);

		self::assertSame('received', $grn['statusCode']);
		self::assertSame('warehouse-01', $grn['receivedBy']);
		self::assertSame(['po-1'], $grn['poIds']);
		self::assertSame('PostNL', $grn['carrier']);
		self::assertStringStartsWith('GRN-', $grn['grnNumber']);
		self::assertStringContainsString('adm-1', $grn['grnNumber']);

	}//end testCreateGrnSeedsHeaderFields()

	/**
	 * createGRN refuses a cross-tenant caller (IDOR).
	 *
	 * @return void
	 */
	public function testCreateGrnRejectsCrossTenant(): void {
		$saved = [];
		$service = $this->buildService(
			data: [],
			saved: $saved,
			userId: 'warehouse-01',
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Administration not found');

		$service->createGRN(
			administrationId: 'adm-OTHER',
			payload: [
				'poIds' => ['po-1'],
			]
		);

	}//end testCreateGrnRejectsCrossTenant()

	/**
	 * createGRN refuses when a referenced PurchaseOrder belongs to a different
	 * tenant (mask as 'Purchase order not found').
	 *
	 * @return void
	 */
	public function testCreateGrnRejectsCrossTenantPo(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-OTHER'],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'warehouse-01',
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Purchase order not found');

		$service->createGRN(
			administrationId: 'adm-1',
			payload: [
				'poIds' => ['po-1'],
			]
		);

	}//end testCreateGrnRejectsCrossTenantPo()

	/**
	 * Partial receipt (180 of 200) — quantityAccepted=180, quantityRejected=20
	 * with a rejection reason validates and persists.
	 *
	 * @return void
	 */
	public function testAddGrnLineAllowsPartialReceipt(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 200.0],
			],
			'GoodsReceiptNote' => [
				[
					'id' => 'grn-1',
					'administrationId' => 'adm-1',
					'poIds' => ['po-1'],
					'statusCode' => 'received',
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'warehouse-01',
			accessibleAdministrations: ['adm-1']
		);

		$line = $service->addGRNLine(
			administrationId: 'adm-1',
			grnId: 'grn-1',
			payload: [
				'poLineId' => 'poline-1',
				'quantityReceived' => 200.0,
				'quantityAccepted' => 180.0,
				'quantityRejected' => 20.0,
				'rejectionReason' => 'short_shipped',
				'batchReference' => 'BATCH-NW-2026-072',
			]
		);

		self::assertSame('poline-1', $line['poLineId']);
		self::assertSame(200.0, $line['quantityReceived']);
		self::assertSame(180.0, $line['quantityAccepted']);
		self::assertSame(20.0, $line['quantityRejected']);
		self::assertSame('short_shipped', $line['rejectionReason']);
		self::assertSame('warehouse-01', $line['inspector']);

	}//end testAddGrnLineAllowsPartialReceipt()

	/**
	 * The allocator refuses accepted+rejected > received.
	 *
	 * @return void
	 */
	public function testAddGrnLineRejectsOverAllocation(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 200.0],
			],
			'GoodsReceiptNote' => [
				[
					'id' => 'grn-1',
					'administrationId' => 'adm-1',
					'poIds' => ['po-1'],
					'statusCode' => 'received',
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'warehouse-01',
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('quantityAccepted + quantityRejected may not exceed quantityReceived');

		$service->addGRNLine(
			administrationId: 'adm-1',
			grnId: 'grn-1',
			payload: [
				'poLineId' => 'poline-1',
				'quantityReceived' => 100.0,
				'quantityAccepted' => 80.0,
				'quantityRejected' => 30.0,
				'rejectionReason' => 'schade',
			]
		);

	}//end testAddGrnLineRejectsOverAllocation()

	/**
	 * Rejection without a rejection_reason is refused (REQ-PO3W-003).
	 *
	 * @return void
	 */
	public function testAddGrnLineRequiresRejectionReason(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 200.0],
			],
			'GoodsReceiptNote' => [
				[
					'id' => 'grn-1',
					'administrationId' => 'adm-1',
					'poIds' => ['po-1'],
					'statusCode' => 'received',
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'warehouse-01',
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('rejectionReason is required when quantityRejected > 0');

		$service->addGRNLine(
			administrationId: 'adm-1',
			grnId: 'grn-1',
			payload: [
				'poLineId' => 'poline-1',
				'quantityReceived' => 50.0,
				'quantityAccepted' => 40.0,
				'quantityRejected' => 10.0,
			]
		);

	}//end testAddGrnLineRequiresRejectionReason()

	/**
	 * Multi-PO allocator: a poLineId that belongs to a PO NOT in the GRN's
	 * po_ids[] is refused — keeps the multi-PO matching strict so a
	 * downstream invoice's 3-way-match cannot accidentally route through the
	 * wrong GRN.
	 *
	 * @return void
	 */
	public function testAddGrnLineRejectsMismatchedPo(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1'],
				['id' => 'po-2', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-99', 'poId' => 'po-2', 'administrationId' => 'adm-1', 'quantityOrdered' => 5.0],
			],
			'GoodsReceiptNote' => [
				[
					'id' => 'grn-1',
					'administrationId' => 'adm-1',
					'poIds' => ['po-1'],
					'statusCode' => 'received',
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'warehouse-01',
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Purchase order line does not belong to this GRN');

		$service->addGRNLine(
			administrationId: 'adm-1',
			grnId: 'grn-1',
			payload: [
				'poLineId' => 'poline-99',
				'quantityReceived' => 5.0,
				'quantityAccepted' => 5.0,
			]
		);

	}//end testAddGrnLineRejectsMismatchedPo()

	/**
	 * Multi-PO allocator (positive path): a GRN that lists two POs accepts
	 * lines for either PO.
	 *
	 * @return void
	 */
	public function testAddGrnLineAcceptsMultiPo(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1'],
				['id' => 'po-2', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 10.0],
				['id' => 'poline-2', 'poId' => 'po-2', 'administrationId' => 'adm-1', 'quantityOrdered' => 5.0],
			],
			'GoodsReceiptNote' => [
				[
					'id' => 'grn-1',
					'administrationId' => 'adm-1',
					'poIds' => ['po-1', 'po-2'],
					'statusCode' => 'received',
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'warehouse-01',
			accessibleAdministrations: ['adm-1']
		);

		$first = $service->addGRNLine(
			administrationId: 'adm-1',
			grnId: 'grn-1',
			payload: [
				'poLineId' => 'poline-1',
				'quantityReceived' => 10.0,
				'quantityAccepted' => 10.0,
			]
		);
		$second = $service->addGRNLine(
			administrationId: 'adm-1',
			grnId: 'grn-1',
			payload: [
				'poLineId' => 'poline-2',
				'quantityReceived' => 5.0,
				'quantityAccepted' => 5.0,
			]
		);

		self::assertSame('poline-1', $first['poLineId']);
		self::assertSame('poline-2', $second['poLineId']);

	}//end testAddGrnLineAcceptsMultiPo()

	/**
	 * Quality-check is refused from a non-'received' source state.
	 *
	 * @return void
	 */
	public function testQualityCheckRequiresReceivedState(): void {
		$saved = [];
		$data = [
			'GoodsReceiptNote' => [
				[
					'id' => 'grn-1',
					'administrationId' => 'adm-1',
					'statusCode' => 'draft',
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'warehouse-01',
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Quality check requires statusCode=received');

		$service->qualityCheckPass(administrationId: 'adm-1', grnId: 'grn-1');

	}//end testQualityCheckRequiresReceivedState()

	/**
	 * uploadPhotos appends to the GRN's photos[] array and de-duplicates.
	 *
	 * @return void
	 */
	public function testUploadPhotosAppendsAndDeduplicates(): void {
		$saved = [];
		$data = [
			'GoodsReceiptNote' => [
				[
					'id' => 'grn-1',
					'administrationId' => 'adm-1',
					'statusCode' => 'received',
					'photos' => ['file-1'],
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'warehouse-01',
			accessibleAdministrations: ['adm-1']
		);

		$updated = $service->uploadPhotos(
			administrationId: 'adm-1',
			grnId: 'grn-1',
			photoFileIds: ['file-1', 'file-2', 'file-3']
		);

		self::assertSame(['file-1', 'file-2', 'file-3'], $updated['photos']);

	}//end testUploadPhotosAppendsAndDeduplicates()
}//end class
