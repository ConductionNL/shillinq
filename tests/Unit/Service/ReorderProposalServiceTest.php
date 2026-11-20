<?php

/**
 * Unit tests for ReorderProposalService.
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
 * @spec openspec/changes/purchasing-call-offs-and-reorder-proposals/specs/inventory-reorder-automation/spec.md (REQ-IRA-011)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderService;
use OCA\Shillinq\Service\ReorderProposalService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The daily reorder pass: low stock raises one proposal and one draft order per
 * rule, inside the rule's own administration, and never twice.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Shillinq\Service\ReorderProposalService
 * @covers \OCA\Shillinq\Service\PurchaseOrderService
 */
final class ReorderProposalServiceTest extends TestCase {

	/**
	 * The in-memory store behind the service.
	 *
	 * @var object
	 */
	private object $store;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Build the service over an in-memory store.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 *
	 * @return ReorderProposalService
	 */
	private function service(array $data): ReorderProposalService {
		$this->store = new class($data) {

			/**
			 * Rows per schema.
			 *
			 * @var array<string,array<int,array<string,mixed>>>
			 */
			public array $data;

			/**
			 * Saves in order, as [schema, object].
			 *
			 * @var array<int,array{0:string,1:array<string,mixed>}>
			 */
			public array $saved = [];

			/**
			 * Reads in order, as [schema, filters, rbac, multitenancy].
			 *
			 * @var array<int,array{0:string,1:array<string,mixed>,2:bool,3:bool}>
			 */
			public array $reads = [];

			/**
			 * Schemas whose next save throws.
			 *
			 * @var array<string,bool>
			 */
			public array $failSave = [];

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
			private int $counter = 0;

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $data Rows.
			 */
			public function __construct(array $data) {
				$this->data = $data;
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
			 * Rows of the active schema matching every equality filter, paged.
			 *
			 * @param array<string,mixed> $config        Query.
			 * @param bool                $_rbac         RBAC flag.
			 * @param bool                $_multitenancy Multitenancy flag.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$filters       = ($config['filters'] ?? []);
				$this->reads[] = [$this->schema, $filters, $_rbac, $_multitenancy];
				$rows          = array_values(
					array_filter(
						($this->data[$this->schema] ?? []),
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

				return array_slice($rows, (int)($config['offset'] ?? 0), (int)($config['limit'] ?? 1000));
			}//end findAll()

			/**
			 * Save (insert or replace by id).
			 *
			 * @param array<string,mixed> $object Object.
			 *
			 * @return array<string,mixed>
			 */
			public function saveObject(array $object): array {
				if (($this->failSave[$this->schema] ?? false) === true) {
					unset($this->failSave[$this->schema]);
					throw new \RuntimeException('store refused');
				}

				if ((string)($object['id'] ?? '') === '') {
					$this->counter++;
					$object['id'] = strtolower($this->schema) . '-' . $this->counter;
				}

				$rows = ($this->data[$this->schema] ?? []);
				foreach ($rows as $index => $row) {
					if (($row['id'] ?? null) === $object['id']) {
						unset($rows[$index]);
					}
				}

				$rows[] = $object;
				$this->data[$this->schema] = array_values($rows);
				$this->saved[] = [$this->schema, $object];
				return $object;
			}//end saveObject()
		};

		$objectService = new DuckObjectServiceAdapter($this->store);
		$appConfig     = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');
		$this->logger = $this->createMock(LoggerInterface::class);

		$purchaseOrders = new PurchaseOrderService(
			appConfig: $appConfig,
			administrationContext: $this->createMock(AdministrationContextService::class),
			notificationManager: $this->createMock(INotificationManager::class),
			logger: $this->logger,
			objectService: $objectService,
		);

		return new ReorderProposalService(
			objectService: $objectService,
			purchaseOrders: $purchaseOrders,
			appConfig: $appConfig,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * The REQ-IRA-011 rule: reorder point 10, reorder quantity 50.
	 *
	 * @param array<string,mixed> $overrides Fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private function rule(array $overrides=[]): array {
		return $overrides + [
			'id' => 'rule-1',
			'inventoryStockId' => 'stock-1',
			'supplierId' => 'sup-1',
			'reorderPoint' => 10,
			'reorderQuantity' => 50,
			'minimumLevel' => 5,
			'maximumLevel' => 100,
			'autoPurchaseOrder' => false,
			'lifecycleState' => 'active',
			'administrationId' => 'adm-1',
		];
	}//end rule()

	/**
	 * A stock record of paper in the main warehouse.
	 *
	 * @param array<string,mixed> $overrides Fields to change.
	 *
	 * @return array<string,mixed>
	 */
	private function stock(array $overrides=[]): array {
		return $overrides + [
			'id' => 'stock-1',
			'productId' => 'PAPER',
			'locationCode' => 'WH-1',
			'quantityOnHand' => 8,
			'unitCost' => 3.5,
			'status' => 'active',
			'administrationId' => 'adm-1',
		];
	}//end stock()

	/**
	 * The run moment.
	 *
	 * @return DateTimeImmutable
	 */
	private function now(): DateTimeImmutable {
		return new DateTimeImmutable('2026-11-13T06:00:00+00:00');
	}//end now()

	/**
	 * 8 units against a reorder point of 10 raise one open proposal and one
	 * draft order for 50 units with the preferred supplier.
	 *
	 * @return void
	 */
	public function testLowStockRaisesTheAlertAndADraftOrder(): void {
		$service = $this->service(['InventoryReorderRule' => [$this->rule()], 'InventoryStock' => [$this->stock()]]);

		$report = $service->proposeAll(now: $this->now());

		self::assertSame(['rules' => 1, 'proposed' => 1, 'resolved' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0], $report);
		self::assertSame(['PurchaseOrder', 'ReorderProposal'], array_column($this->store->saved, 0));

		$order = $this->store->saved[0][1];
		self::assertSame(
			[sprintf('PO-%d-adm-1-000001', (int)date('Y')), 'adm-1', 'sup-1', '', '', 'draft', [], 175.0, 'EUR', '2026-11-13T06:00:00+00:00'],
			[$order['poNumber'], $order['administrationId'], $order['supplierId'], $order['requesterId'], $order['costCenter'], $order['statusCode'], $order['approvalChain'], $order['totalAmount'], $order['currency'], $order['createdAt']]
		);
		self::assertSame(
			[['lineNumber' => 1, 'productCode' => 'PAPER', 'quantity' => 50.0, 'unitPrice' => 3.5, 'lineTotal' => 175.0, 'vatRate' => 0.0, 'vatAmount' => 0.0, 'glAccount' => '']],
			$order['lines']
		);
		self::assertSame('Proposed by reorder rule rule-1: 8 on hand at WH-1, reorder point 10.', $order['notes']);

		self::assertSame(
			[
				'reorderRuleId' => 'rule-1',
				'inventoryStockId' => 'stock-1',
				'productId' => 'PAPER',
				'locationCode' => 'WH-1',
				'quantityOnHand' => 8.0,
				'reorderPoint' => 10.0,
				'reorderQuantity' => 50.0,
				'supplierId' => 'sup-1',
				'purchaseOrderId' => 'purchaseorder-1',
				'raisedAt' => '2026-11-13T06:00:00+00:00',
				'statusCode' => 'open',
				'administrationId' => 'adm-1',
				'id' => 'reorderproposal-2',
			],
			$this->store->saved[1][1]
		);
	}//end testLowStockRaisesTheAlertAndADraftOrder()

	/**
	 * The whole pass runs inside ObjectService::runAsSystem(): nothing is read
	 * or written outside it, and the pass answers what the system run answers.
	 *
	 * @return void
	 */
	public function testThePassRunsAsTheSystem(): void {
		$report        = ['rules' => 0, 'proposed' => 0, 'resolved' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0];
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects($this->once())->method('runAsSystem')->with($this->isInstanceOf(\Closure::class))->willReturn($report);
		$objectService->expects($this->never())->method('findAll');
		$objectService->expects($this->never())->method('saveObject');
		$appConfig = $this->createMock(IAppConfig::class);
		$logger    = $this->createMock(LoggerInterface::class);

		$service = new ReorderProposalService(
			objectService: $objectService,
			purchaseOrders: new PurchaseOrderService(
				appConfig: $appConfig,
				administrationContext: $this->createMock(AdministrationContextService::class),
				notificationManager: $this->createMock(INotificationManager::class),
				logger: $logger,
				objectService: $objectService,
			),
			appConfig: $appConfig,
			logger: $logger,
		);

		self::assertSame($report, $service->proposeAll(now: $this->now()));
	}//end testThePassRunsAsTheSystem()

	/**
	 * A second run while the proposal is open writes nothing: no second alert
	 * and no second draft order.
	 *
	 * @return void
	 */
	public function testASecondRunDoesNotDuplicateAnOpenProposal(): void {
		$service = $this->service(['InventoryReorderRule' => [$this->rule()], 'InventoryStock' => [$this->stock()]]);
		$service->proposeAll(now: $this->now());
		$this->store->saved = [];

		$report = $service->proposeAll(now: $this->now());

		self::assertSame(['rules' => 1, 'proposed' => 0, 'resolved' => 0, 'unchanged' => 1, 'skipped' => 0, 'failed' => 0], $report);
		self::assertSame([], $this->store->saved);
		self::assertCount(1, $this->store->data['PurchaseOrder']);
	}//end testASecondRunDoesNotDuplicateAnOpenProposal()

	/**
	 * Stock back above the point resolves the open proposal, so the next dip
	 * proposes again; stock above the point without a proposal writes nothing.
	 *
	 * @return void
	 */
	public function testStockAboveThePointResolvesTheOpenProposal(): void {
		$open    = ['id' => 'rp-1', 'reorderRuleId' => 'rule-1', 'statusCode' => 'open', 'administrationId' => 'adm-1'];
		$service = $this->service(
			[
				'InventoryReorderRule' => [$this->rule(), $this->rule(['id' => 'rule-2', 'inventoryStockId' => 'stock-2'])],
				'InventoryStock' => [$this->stock(['quantityOnHand' => 60]), $this->stock(['id' => 'stock-2', 'quantityOnHand' => 11])],
				'ReorderProposal' => [$open],
			]
		);

		$report = $service->proposeAll(now: $this->now());

		self::assertSame(['rules' => 2, 'proposed' => 0, 'resolved' => 1, 'unchanged' => 1, 'skipped' => 0, 'failed' => 0], $report);
		self::assertSame([['ReorderProposal', array_merge($open, ['statusCode' => 'resolved', 'resolvedAt' => '2026-11-13T06:00:00+00:00'])]], $this->store->saved);
	}//end testStockAboveThePointResolvesTheOpenProposal()

	/**
	 * The pass reads every active rule as the system across administrations,
	 * then reads stock and open proposals filtered on each rule's own
	 * administration: a stock record of another administration is never used,
	 * even under the same id.
	 *
	 * @return void
	 */
	public function testEachRuleStaysInsideItsAdministration(): void {
		$service = $this->service(
			[
				'InventoryReorderRule' => [$this->rule(), $this->rule(['id' => 'rule-2', 'administrationId' => 'adm-2'])],
				'InventoryStock' => [$this->stock(['quantityOnHand' => 50]), $this->stock(['administrationId' => 'adm-OTHER', 'quantityOnHand' => 0])],
				'ReorderProposal' => [['id' => 'rp-x', 'reorderRuleId' => 'rule-2', 'statusCode' => 'open', 'administrationId' => 'adm-OTHER']],
			]
		);

		$report = $service->proposeAll(now: $this->now());

		self::assertSame(['rules' => 2, 'proposed' => 0, 'resolved' => 0, 'unchanged' => 1, 'skipped' => 1, 'failed' => 0], $report);
		self::assertSame([], $this->store->saved);
		self::assertSame(
			[
				['InventoryReorderRule', ['lifecycleState' => 'active'], false, false],
				['InventoryStock', ['administrationId' => 'adm-1'], false, false],
				['ReorderProposal', ['administrationId' => 'adm-1', 'statusCode' => 'open'], false, false],
				['InventoryStock', ['administrationId' => 'adm-2'], false, false],
				['ReorderProposal', ['administrationId' => 'adm-2', 'statusCode' => 'open'], false, false],
			],
			$this->store->reads
		);
	}//end testEachRuleStaysInsideItsAdministration()

	/**
	 * A low rule without a preferred supplier raises the alert alone; a rule
	 * without administration or id, without stock, snoozed, or with no reorder
	 * quantity is skipped.
	 *
	 * @return void
	 */
	public function testAlertWithoutSupplierAndTheSkippedRules(): void {
		$service = $this->service(
			[
				'InventoryReorderRule' => [
					$this->rule(['supplierId' => null]),
					$this->rule(['id' => 'rule-adm', 'administrationId' => '']),
					$this->rule(['id' => '']),
					$this->rule(['id' => 'rule-nostock', 'inventoryStockId' => 'stock-404']),
					$this->rule(['id' => 'rule-snoozed', 'snoozeUntil' => '2026-11-20T00:00:00+00:00']),
					$this->rule(['id' => 'rule-zero', 'reorderQuantity' => 0]),
					$this->rule(['id' => 'rule-woke', 'snoozeUntil' => '2026-11-01T00:00:00+00:00', 'supplierId' => '']),
				],
				'InventoryStock' => [$this->stock()],
			]
		);

		$report = $service->proposeAll(now: $this->now());

		self::assertSame(['rules' => 7, 'proposed' => 2, 'resolved' => 0, 'unchanged' => 0, 'skipped' => 5, 'failed' => 0], $report);
		self::assertSame(['ReorderProposal', 'ReorderProposal'], array_column($this->store->saved, 0));
		self::assertSame([null, null], array_column(array_column($this->store->saved, 1), 'purchaseOrderId'));
		self::assertSame(['rule-1', 'rule-woke'], array_column(array_column($this->store->saved, 1), 'reorderRuleId'));
	}//end testAlertWithoutSupplierAndTheSkippedRules()

	/**
	 * A rule that fails is logged and counted; the pass goes on with the next,
	 * and the failed rule leaves no proposal so the next run tries again.
	 *
	 * @return void
	 */
	public function testAFailingRuleDoesNotStopThePass(): void {
		$service = $this->service(
			[
				'InventoryReorderRule' => [$this->rule(), $this->rule(['id' => 'rule-2', 'inventoryStockId' => 'stock-2'])],
				'InventoryStock' => [$this->stock(), $this->stock(['id' => 'stock-2'])],
			]
		);
		$this->store->failSave['PurchaseOrder'] = true;
		$this->logger->expects($this->once())->method('warning')->with(
			'ReorderProposalService: reorder rule failed',
			['reorderRuleId' => 'rule-1', 'exception' => 'store refused']
		);

		$report = $service->proposeAll(now: $this->now());

		self::assertSame(['rules' => 2, 'proposed' => 1, 'resolved' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 1], $report);
		self::assertSame(['rule-2'], array_column(($this->store->data['ReorderProposal'] ?? []), 'reorderRuleId'));
	}//end testAFailingRuleDoesNotStopThePass()
}//end class
