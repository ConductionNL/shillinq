<?php

/**
 * Unit tests for PurchaseOrderService.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-02-purchase-order-core/tasks.md
 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\AdministrationContextService;
use OCA\Shillinq\Service\PurchaseOrderService;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the OpenRegister-backed purchase-order service.
 *
 * Covers REQ-PO3W-001 on the service surfaces that remain after approval
 * moved to OpenRegister's declared chain (purchasing-approval-delegation):
 *  - createPurchaseOrder starts in draft, writes totalExclVat in cents, and
 *    writes no in-object chain, no ApprovalTask and no notification;
 *  - markSent refuses an order that is not approved and sends one that is.
 *
 * The OpenRegister ObjectService is stubbed with an in-memory schema-keyed store
 * that honours equality filters so cross-administration data never leaks.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class PurchaseOrderServiceTest extends TestCase {

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
	 * @param string $userId Authenticated uid.
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 *
	 * @return PurchaseOrderService
	 */
	private function buildService(
		array $data,
		array &$saved,
		string $userId,
		array $accessibleAdministrations,
	): PurchaseOrderService {
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
			 * Capture a saved object; stamp an id when absent so the caller can
			 * reference the persisted record.
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

				// Also reflect into $this->data so subsequent findAll sees the row.
				$this->data[$this->schema][] = $object;
				$this->saved[] = ['schema' => $this->schema, 'object' => $object];
				return $object;
			}//end saveObject()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($stub);
		$this->container = $container;

		$administrationContext = $this->createMock(AdministrationContextService::class);
		$administrationContext->method('currentUserId')->willReturn($userId);
		$administrationContext->method('canAccess')->willReturnCallback(
			static function (string $administrationId) use ($accessibleAdministrations): bool {
				return in_array($administrationId, $accessibleAdministrations, true);
			}
		);

		return new PurchaseOrderService(
			appConfig: $this->appConfig,
			administrationContext: $administrationContext,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * Exercises createPurchaseOrder: a draft order with its total in cents and no
	 * approval engine of shillinq's own (REQ-PAD-001).
	 *
	 * @return void
	 */
	public function testCreatePurchaseOrderStartsInDraftWithoutAnInObjectChain(): void {
		$saved = [];
		$data = [
			'AdministrationMembership' => [
				['administrationId' => 'adm-1', 'role' => 'teamleider', 'userId' => 'teamleider-1'],
				['administrationId' => 'adm-1', 'role' => 'facility_manager', 'userId' => 'facility-1'],
				['administrationId' => 'adm-1', 'role' => 'procurement_manager', 'userId' => 'procurement-1'],
			],
			'PurchaseOrder' => [],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
		);

		$po = $service->createPurchaseOrder(
			administrationId: 'adm-1',
			payload: [
				'supplierId' => 'sup-1',
				'costCenter' => 'FAC-2026',
				'projectCode' => 'P-FAC',
				'currency' => 'EUR',
				'lines' => [
					[
						'productCode' => 'COFFEE-PRO-1',
						'quantity' => 1,
						'unitPrice' => 18500.00,
						'vatRate' => 0.21,
						'glAccount' => '4400',
					],
				],
			]
		);

		self::assertSame(18500.00, $po['totalAmount']);
		self::assertSame(1850000, $po['totalExclVat']);
		self::assertSame('draft', $po['statusCode']);
		self::assertArrayNotHasKey('approvalChain', $po);
		self::assertSame('inkoper-1', $po['requesterId']);
		self::assertNotEmpty($po['poNumber']);

		// One save, the order itself: OpenRegister's approval chain opens the
		// steps and notifies the approvers on submit, not shillinq on create.
		self::assertSame(['PurchaseOrder'], array_column($saved, 'schema'));

	}//end testCreatePurchaseOrderStartsInDraftWithoutAnInObjectChain()

	/**
	 * Exercises createPurchaseOrder: rejects a cross-tenant caller (IDOR).
	 *
	 * @return void
	 */
	public function testCreatePurchaseOrderRejectsCrossTenant(): void {
		$saved = [];
		$service = $this->buildService(
			data: [],
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
		);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Administration not found');

		$service->createPurchaseOrder(
			administrationId: 'adm-OTHER',
			payload: [
				'supplierId' => 'sup-1',
				'costCenter' => 'FAC-2026',
				'lines' => [
					['productCode' => 'X', 'quantity' => 1, 'unitPrice' => 100.0, 'vatRate' => 0.21, 'glAccount' => '4400'],
				],
			]
		);

	}//end testCreatePurchaseOrderRejectsCrossTenant()

	/**
	 * Exercises markSent: a draft order is refused even when its old in-object
	 * chain reads fully signed; an approved order is sent.
	 *
	 * @return void
	 */
	public function testMarkSentRequiresAnApprovedOrder(): void {
		$saved = [];
		$order = [
			'id' => 'po-1',
			'administrationId' => 'adm-1',
			'poNumber' => 'PO-2026-adm-1-000001',
			'statusCode' => 'draft',
			'approvalChain' => [
				['role' => 'teamleider', 'order' => 1, 'decision' => 'approved', 'decidedAt' => '2026-06-01T12:00:00+00:00', 'userId' => 'teamleider-1'],
			],
		];

		$service = $this->buildService(
			data: ['PurchaseOrder' => [$order]],
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
		);

		try {
			$service->markSent(administrationId: 'adm-1', purchaseOrderId: 'po-1');
			self::fail('Expected markSent to refuse a draft order');
		} catch (\RuntimeException $e) {
			self::assertSame('Purchase order cannot be sent: it is not approved', $e->getMessage());
		}

		self::assertSame([], $saved);

		$order['statusCode'] = 'approved';
		$approved = $this->buildService(
			data: ['PurchaseOrder' => [$order]],
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
		);

		$po = $approved->markSent(administrationId: 'adm-1', purchaseOrderId: 'po-1');
		self::assertSame('sent', $po['statusCode']);
		self::assertNotEmpty($po['sentAt']);
	}//end testMarkSentRequiresAnApprovedOrder()
}//end class
