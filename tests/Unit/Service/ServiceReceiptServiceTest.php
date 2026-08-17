<?php

/**
 * Unit tests for ServiceReceiptService.
 *
 * Covers member 12 of bookkeeping-purchase-order-3way (REQ-PO3W-011) at the
 * line-confirmation level:
 *  - createServiceReceipt materialises a per-administration receiptNumber
 *    and a server-derived approver from the session;
 *  - addServiceReceiptLine derives quantityAccepted/quantityReceived from
 *    whichever of the three confirmation modes (percentageComplete /
 *    quantityConfirmed / amountConfirmedCents) the caller supplied;
 *  - multi-PO matching: a receipt line whose poLineId belongs to a PO
 *    outside the receipt's poIds[] is rejected;
 *  - acceptServiceReceipt recomputes the originating PO's receipt
 *    lifecycle across multiple periodic receipts (partial_received →
 *    fully_received), mirroring GoodsReceiptNoteServiceTest's coverage of
 *    the equivalent goods-side accumulation.
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
 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\ServiceReceiptService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the line-confirmation + lifecycle logic of ServiceReceiptService.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class ServiceReceiptServiceTest extends TestCase {
	/**
	 * Build a service + in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param string $userId Authenticated uid.
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 *
	 * @return ServiceReceiptService
	 */
	private function buildService(
		array $data,
		array &$saved,
		string $userId,
		array $accessibleAdministrations,
	): ServiceReceiptService {
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

		return new ServiceReceiptService(
			appConfig: $appConfig,
			administrationContext: $administrationContext,
			logger: $logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Build a mutable in-memory ObjectService stub (mirrors
	 * GoodsReceiptNoteServiceTest's harness).
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
	 * CreateServiceReceipt stamps a per-administration receiptNumber, the
	 * server-derived approver, and the 'draft' lifecycle state.
	 *
	 * @return void
	 */
	public function testCreateServiceReceiptSeedsHeaderFields(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1', 'poNumber' => 'PO-2026-adm-1-000001'],
			],
			'SvcReceipt' => [],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'controller-01',
			accessibleAdministrations: ['adm-1']
		);

		$receipt = $service->createServiceReceipt(
			administrationId: 'adm-1',
			payload: [
				'poIds' => ['po-1'],
				'periodStart' => '2026-07-01',
				'periodEnd' => '2026-07-31',
			]
		);

		self::assertSame('draft', $receipt['statusCode']);
		self::assertSame('controller-01', $receipt['approver']);
		self::assertSame(['po-1'], $receipt['poIds']);
		self::assertStringStartsWith('SVR-', $receipt['receiptNumber']);
		self::assertStringContainsString('adm-1', $receipt['receiptNumber']);

	}//end testCreateServiceReceiptSeedsHeaderFields()

	/**
	 * CreateServiceReceipt refuses a cross-tenant caller (IDOR).
	 *
	 * @return void
	 */
	public function testCreateServiceReceiptRejectsCrossTenant(): void {
		$saved = [];
		$service = $this->buildService(
			data: [],
			saved: $saved,
			userId: 'controller-01',
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Administration not found');

		$service->createServiceReceipt(
			administrationId: 'adm-OTHER',
			payload: ['poIds' => ['po-1']]
		);

	}//end testCreateServiceReceiptRejectsCrossTenant()

	/**
	 * Confirmation mode 1/3: percentageComplete derives quantityAccepted as
	 * a proportion of the PO line's quantityOrdered.
	 *
	 * @return void
	 */
	public function testAddLineDerivesQuantityFromPercentageComplete(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 1.0, 'unitPrice' => 500000],
			],
			'SvcReceipt' => [
				['id' => 'svr-1', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'draft'],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'controller-01',
			accessibleAdministrations: ['adm-1']
		);

		$line = $service->addServiceReceiptLine(
			administrationId: 'adm-1',
			receiptId: 'svr-1',
			payload: [
				'poLineId' => 'poline-1',
				'percentageComplete' => 10000,
			]
		);

		self::assertSame(1.0, $line['quantityAccepted']);
		self::assertSame(1.0, $line['quantityReceived']);
		self::assertSame('controller-01', $line['approver']);

	}//end testAddLineDerivesQuantityFromPercentageComplete()

	/**
	 * Confirmation mode 1/3, partial: 50% complete on a 3-unit PO line
	 * derives 1.5.
	 *
	 * @return void
	 */
	public function testAddLineDerivesPartialQuantityFromPercentageComplete(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 3.0, 'unitPrice' => 500000],
			],
			'SvcReceipt' => [
				['id' => 'svr-1', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'draft'],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'controller-01',
			accessibleAdministrations: ['adm-1']
		);

		$line = $service->addServiceReceiptLine(
			administrationId: 'adm-1',
			receiptId: 'svr-1',
			payload: [
				'poLineId' => 'poline-1',
				'percentageComplete' => 5000,
			]
		);

		self::assertSame(1.5, $line['quantityAccepted']);

	}//end testAddLineDerivesPartialQuantityFromPercentageComplete()

	/**
	 * Confirmation mode 2/3: quantityConfirmed is used directly (mirrors
	 * GoodsReceiptLine.quantityAccepted semantics for hours-based billing).
	 *
	 * @return void
	 */
	public function testAddLineDerivesQuantityFromQuantityConfirmed(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 40.0, 'unitPrice' => 10000],
			],
			'SvcReceipt' => [
				['id' => 'svr-1', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'draft'],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'controller-01',
			accessibleAdministrations: ['adm-1']
		);

		$line = $service->addServiceReceiptLine(
			administrationId: 'adm-1',
			receiptId: 'svr-1',
			payload: [
				'poLineId' => 'poline-1',
				'quantityConfirmed' => 8.0,
			]
		);

		self::assertSame(8.0, $line['quantityAccepted']);

	}//end testAddLineDerivesQuantityFromQuantityConfirmed()

	/**
	 * Confirmation mode 3/3: amountConfirmedCents converts to a
	 * quantity-equivalent via the PO line's unitPrice (milestone billing).
	 *
	 * @return void
	 */
	public function testAddLineDerivesQuantityFromAmountConfirmedCents(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 1.0, 'unitPrice' => 500000],
			],
			'SvcReceipt' => [
				['id' => 'svr-1', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'draft'],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'controller-01',
			accessibleAdministrations: ['adm-1']
		);

		$line = $service->addServiceReceiptLine(
			administrationId: 'adm-1',
			receiptId: 'svr-1',
			payload: [
				'poLineId' => 'poline-1',
				'amountConfirmedCents' => 250000,
			]
		);

		self::assertSame(0.5, $line['quantityAccepted']);

	}//end testAddLineDerivesQuantityFromAmountConfirmedCents()

	/**
	 * At least one confirmation mode is required.
	 *
	 * @return void
	 */
	public function testAddLineRequiresAConfirmationMode(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 1.0, 'unitPrice' => 500000],
			],
			'SvcReceipt' => [
				['id' => 'svr-1', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'draft'],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'controller-01',
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('One of quantityConfirmed, percentageComplete or amountConfirmedCents is required');

		$service->addServiceReceiptLine(
			administrationId: 'adm-1',
			receiptId: 'svr-1',
			payload: ['poLineId' => 'poline-1']
		);

	}//end testAddLineRequiresAConfirmationMode()

	/**
	 * Multi-PO allocator: a poLineId that belongs to a PO NOT in the
	 * receipt's poIds[] is refused.
	 *
	 * @return void
	 */
	public function testAddLineRejectsMismatchedPo(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1'],
				['id' => 'po-2', 'administrationId' => 'adm-1'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-99', 'poId' => 'po-2', 'administrationId' => 'adm-1', 'quantityOrdered' => 5.0, 'unitPrice' => 100000],
			],
			'SvcReceipt' => [
				['id' => 'svr-1', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'draft'],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'controller-01',
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Purchase order line does not belong to this service receipt');

		$service->addServiceReceiptLine(
			administrationId: 'adm-1',
			receiptId: 'svr-1',
			payload: ['poLineId' => 'poline-99', 'quantityConfirmed' => 5.0]
		);

	}//end testAddLineRejectsMismatchedPo()

	/**
	 * ConfirmServiceReceipt is refused from a non-'draft' source state.
	 *
	 * @return void
	 */
	public function testConfirmRequiresDraftState(): void {
		$saved = [];
		$data = [
			'SvcReceipt' => [
				['id' => 'svr-1', 'administrationId' => 'adm-1', 'statusCode' => 'confirmed'],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'controller-01',
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Confirmation requires statusCode=draft');

		$service->confirmServiceReceipt(administrationId: 'adm-1', receiptId: 'svr-1');

	}//end testConfirmRequiresDraftState()

	/**
	 * AcceptServiceReceipt is refused from a non-'confirmed' source state.
	 *
	 * @return void
	 */
	public function testAcceptRequiresConfirmedState(): void {
		$saved = [];
		$data = [
			'SvcReceipt' => [
				['id' => 'svr-1', 'administrationId' => 'adm-1', 'statusCode' => 'draft', 'poIds' => []],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'controller-01',
			accessibleAdministrations: ['adm-1']
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Acceptance requires statusCode=confirmed');

		$service->acceptServiceReceipt(administrationId: 'adm-1', receiptId: 'svr-1');

	}//end testAcceptRequiresConfirmedState()

	/**
	 * AcceptServiceReceipt recomputes the PO lifecycle across TWO periodic
	 * receipts on a 3-unit PO line: after the first accepted receipt
	 * (1 of 3 units) the PO is partial_received; after a second accepted
	 * receipt reaching the full 3 units, fully_received. Mirrors
	 * GoodsReceiptNoteService's partial → full accumulation for the
	 * service side (design.md D4).
	 *
	 * @return void
	 */
	public function testAcceptAccumulatesAcrossPeriodicReceipts(): void {
		$saved = [];
		$data = [
			'PurchaseOrder' => [
				['id' => 'po-1', 'administrationId' => 'adm-1', 'lifecycleState' => 'sent'],
			],
			'PurchaseOrderLine' => [
				['id' => 'poline-1', 'poId' => 'po-1', 'administrationId' => 'adm-1', 'quantityOrdered' => 3.0, 'unitPrice' => 500000],
			],
			'SvcReceipt' => [
				['id' => 'svr-1', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'confirmed'],
				['id' => 'svr-2', 'administrationId' => 'adm-1', 'poIds' => ['po-1'], 'statusCode' => 'confirmed'],
			],
			'SvcReceiptLine' => [
				['id' => 'svrl-1', 'serviceReceiptId' => 'svr-1', 'poLineId' => 'poline-1', 'quantityAccepted' => 1.0, 'administrationId' => 'adm-1'],
				['id' => 'svrl-2', 'serviceReceiptId' => 'svr-2', 'poLineId' => 'poline-1', 'quantityAccepted' => 2.0, 'administrationId' => 'adm-1'],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'controller-01',
			accessibleAdministrations: ['adm-1']
		);

		// Accept the first receipt (1 of 3 units confirmed) — PO should
		// move to partial_received.
		$service->acceptServiceReceipt(administrationId: 'adm-1', receiptId: 'svr-1');

		$poSavesAfterFirst = array_values(array_filter($saved, static fn ($r) => $r['schema'] === 'PurchaseOrder'));
		self::assertNotEmpty($poSavesAfterFirst);
		self::assertSame('partial_received', end($poSavesAfterFirst)['object']['lifecycleState']);

		// Accept the second receipt (2 more of 3 units => 3/3 total) — PO
		// should move to fully_received.
		$service->acceptServiceReceipt(administrationId: 'adm-1', receiptId: 'svr-2');

		$poSavesAfterSecond = array_values(array_filter($saved, static fn ($r) => $r['schema'] === 'PurchaseOrder'));
		self::assertSame('fully_received', end($poSavesAfterSecond)['object']['lifecycleState']);

	}//end testAcceptAccumulatesAcrossPeriodicReceipts()
}//end class
