<?php

/**
 * Unit tests for RequisitionConversionService.
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
 * @spec openspec/specs/purchase-requisition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Lifecycle\RequisitionConversionGuard;
use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderService;
use OCA\Shillinq\Service\RequisitionConversionService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests RequisitionConversionService against a REAL (unmodified)
 * PurchaseOrderService and RequisitionConversionGuard (ADR-009) so the
 * "approved requisition converts to a purchase order with the link intact"
 * and "unapproved requisition cannot convert" correctness proofs exercise the
 * actual production call chain, not a mocked stand-in.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class RequisitionConversionServiceTest extends TestCase {

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
	 * Per-notification mock state-bag (spl_object_id => stdClass), mirrors
	 * PurchaseOrderServiceTest's notificationManagerCapturing() precedent.
	 *
	 * @var array<int,object>
	 */
	private array $notificationState = [];

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
	 * A lenient notification manager: createNotification() returns a mock
	 * whose fluent setters return itself; notify() is a no-op. Notifications
	 * are not the focus of these tests — only that PurchaseOrderService's
	 * real dispatch path does not throw.
	 *
	 * @return INotificationManager
	 */
	private function lenientNotificationManager(): INotificationManager {
		$manager = $this->createMock(INotificationManager::class);

		$manager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setDateTime', 'setUser', 'setObject', 'setSubject'] as $method) {
					$notification->method($method)->willReturnSelf();
				}

				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification): void {
			}
		);

		return $manager;
	}//end lenientNotificationManager()

	/**
	 * Build an in-memory ObjectService stub honouring equality filters and
	 * update-in-place saves (mirrors RequisitionServiceTest's stub so both
	 * RequisitionConversionService and the real PurchaseOrderService share
	 * one consistent in-memory store).
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
			 * Capture a saved object; stamp an id when absent, update in
			 * place when the id already exists in the store.
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
					foreach (($this->data[$this->schema] ?? []) as $index => $row) {
						if (($row['id'] ?? null) === $object['id']) {
							$this->data[$this->schema][$index] = $object;
							$this->saved[] = ['schema' => $this->schema, 'object' => $object];
							return $object;
						}
					}
				}

				$this->data[$this->schema][] = $object;
				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

	}//end buildObjectServiceStub()

	/**
	 * Build a RequisitionConversionService wired to a REAL
	 * RequisitionConversionGuard and a REAL PurchaseOrderService, sharing one
	 * in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param string $userId Authenticated uid.
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 *
	 * @return RequisitionConversionService
	 */
	private function buildService(
		array $data,
		array &$saved,
		string $userId,
		array $accessibleAdministrations,
	): RequisitionConversionService {
		$stub = $this->buildObjectServiceStub($data, $saved);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('currentUserId')->willReturn($userId);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		$guard = new RequisitionConversionGuard(container: $container, appConfig: $this->appConfig, logger: $this->logger);

		$purchaseOrderService = new PurchaseOrderService(
			appConfig: $this->appConfig,
			administrationContext: $administrationContext,
			notificationManager: $this->lenientNotificationManager(),
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

		return new RequisitionConversionService(
			appConfig: $this->appConfig,
			administrationContext: $administrationContext,
			guard: $guard,
			purchaseOrderService: $purchaseOrderService,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Correctness proof: an approved requisition converts to a PurchaseOrder
	 * with the link intact both ways — Requisition.convertedPurchaseOrderId
	 * points at the new PO, and PurchaseOrder.requisitionId points back
	 * (REQ-REQ-005).
	 *
	 * @return void
	 */
	public function testApprovedRequisitionConvertsToLinkedPurchaseOrder(): void {
		$saved = [];
		$data = [
			'Requisition' => [
				[
					'id' => 'req-1',
					'administrationId' => 'adm-1',
					'requisitionNumber' => 'REQ-2026-adm-1-000001',
					'statusCode' => 'approved',
					'programme' => '5.1',
					'financialYear' => 2026,
					'kind' => 'inkoop',
					'preferredSupplierId' => 'vendor-001',
					'total_amount_excl_vat' => 240000,
				],
			],
			'RequisitionLine' => [
				[
					'id' => 'line-1',
					'requisitionId' => 'req-1',
					'administrationId' => 'adm-1',
					'lineNumber' => 1,
					'description' => 'Design suite annual licence',
					'quantity' => 10.0,
					'unitPrice' => 24000,
					'lineTotal' => 240000,
					'glAccountSuggestion' => '4720',
				],
			],
		];
		$service = $this->buildService(data: $data, saved: $saved, userId: 'controller-1', accessibleAdministrations: ['adm-1']);

		$result = $service->convertToPurchaseOrder(administrationId: 'adm-1', requisitionId: 'req-1');

		self::assertSame('converted', $result['requisition']['statusCode']);
		self::assertNotEmpty($result['requisition']['convertedPurchaseOrderId']);
		self::assertNotEmpty($result['requisition']['convertedAt']);

		self::assertSame($result['requisition']['convertedPurchaseOrderId'], $result['purchaseOrder']['id']);
		self::assertSame('req-1', $result['purchaseOrder']['requisitionId']);
		self::assertSame('vendor-001', $result['purchaseOrder']['supplierId']);
		self::assertSame('5.1', $result['purchaseOrder']['costCenter']);

		// The line's integer-cents unitPrice (24000 = EUR 240.00) is converted
		// to the euro float PurchaseOrderService::normaliseLines() expects.
		self::assertCount(1, $result['purchaseOrder']['lines']);
		self::assertSame(240.0, $result['purchaseOrder']['lines'][0]['unitPrice']);
		self::assertSame('4720', $result['purchaseOrder']['lines'][0]['glAccount']);

	}//end testApprovedRequisitionConvertsToLinkedPurchaseOrder()

	/**
	 * Correctness proof: an unapproved requisition CANNOT convert — draft,
	 * submitted, rejected and already-converted requisitions are all refused
	 * by the same fail-closed guard (REQ-REQ-005).
	 *
	 * @return void
	 */
	public function testUnapprovedRequisitionCannotConvert(): void {
		foreach (['draft', 'submitted', 'rejected', 'converted'] as $status) {
			$saved = [];
			$data = [
				'Requisition' => [
					[
						'id' => 'req-1',
						'administrationId' => 'adm-1',
						'requisitionNumber' => 'REQ-2026-adm-1-000001',
						'statusCode' => $status,
						'programme' => '5.1',
						'financialYear' => 2026,
						'kind' => 'inkoop',
						'preferredSupplierId' => 'vendor-001',
						'total_amount_excl_vat' => 240000,
					],
				],
				'RequisitionLine' => [
					[
						'id' => 'line-1',
						'requisitionId' => 'req-1',
						'administrationId' => 'adm-1',
						'lineNumber' => 1,
						'description' => 'Design suite annual licence',
						'quantity' => 10.0,
						'unitPrice' => 24000,
						'lineTotal' => 240000,
						'glAccountSuggestion' => '4720',
					],
				],
			];
			$service = $this->buildService(data: $data, saved: $saved, userId: 'controller-1', accessibleAdministrations: ['adm-1']);

			try {
				$service->convertToPurchaseOrder(administrationId: 'adm-1', requisitionId: 'req-1');
				self::fail("convertToPurchaseOrder() must refuse a requisition in status '$status'");
			} catch (\RuntimeException $e) {
				self::assertSame(
					'Requisition must be approved before it can be converted to a purchase order',
					$e->getMessage(),
					"unexpected message for status '$status'"
				);
			}

			// No PurchaseOrder was ever created for a refused conversion.
			$poSaves = array_filter($saved, static fn ($s) => $s['schema'] === 'PurchaseOrder');
			self::assertCount(0, $poSaves, "status '$status' must not create a PurchaseOrder");
		}//end foreach

	}//end testUnapprovedRequisitionCannotConvert()

	/**
	 * An approved requisition with no preferred supplier is refused —
	 * a PurchaseOrder always needs a supplier.
	 *
	 * @return void
	 */
	public function testConvertRefusesWhenNoPreferredSupplier(): void {
		$saved = [];
		$data = [
			'Requisition' => [
				[
					'id' => 'req-1',
					'administrationId' => 'adm-1',
					'requisitionNumber' => 'REQ-2026-adm-1-000001',
					'statusCode' => 'approved',
					'programme' => '5.1',
					'financialYear' => 2026,
					'kind' => 'inkoop',
					'total_amount_excl_vat' => 240000,
				],
			],
		];
		$service = $this->buildService(data: $data, saved: $saved, userId: 'controller-1', accessibleAdministrations: ['adm-1']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Requisition has no preferred supplier; assign one before converting to a purchase order');

		$service->convertToPurchaseOrder(administrationId: 'adm-1', requisitionId: 'req-1');

	}//end testConvertRefusesWhenNoPreferredSupplier()

	/**
	 * Cross-tenant access is masked as not-found (ADR-005).
	 *
	 * @return void
	 */
	public function testConvertDeniesCrossTenantAccess(): void {
		$saved = [];
		$service = $this->buildService(data: [], saved: $saved, userId: 'controller-1', accessibleAdministrations: ['adm-2']);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Requisition not found');

		$service->convertToPurchaseOrder(administrationId: 'adm-1', requisitionId: 'req-1');

	}//end testConvertDeniesCrossTenantAccess()
}//end class
