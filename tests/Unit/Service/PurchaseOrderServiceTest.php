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
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests the OpenRegister-backed purchase-order service.
 *
 * Covers REQ-PO3W-001 across the three core service surfaces:
 *  - determineApprovalChain at €5k / €10k / €50k thresholds (pure logic);
 *  - createPurchaseOrder end-to-end (chain materialised, ApprovalTask records
 *    written, notifications dispatched);
 *  - blockSendUntilApproved (rejected while pending, allowed once every approver
 *    has signed with a timestamp).
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
	 * Construct a notification manager mock that captures every notify() call.
	 *
	 * Uses PHPUnit's createMock(INotification::class) so every interface method
	 * is stubbed without us re-declaring 30+ methods (and we don't drift when
	 * the interface adds new methods like setPriorityNotification).
	 *
	 * @param array<int,array{user:string,subject:string,object:string}> $captured Captured calls.
	 *
	 * @return INotificationManager
	 */
	private function notificationManagerCapturing(array &$captured): INotificationManager {
		$manager = $this->createMock(INotificationManager::class);

		$manager->method('createNotification')->willReturnCallback(
			function () use (&$captured): INotification {
				$state = (object)['user' => '', 'subject' => '', 'object' => ''];

				$notification = $this->createMock(INotification::class);
				$notification->method('setApp')->willReturnSelf();
				$notification->method('setDateTime')->willReturnSelf();
				$notification->method('setUser')->willReturnCallback(
					function (string $user) use ($notification, $state): INotification {
						$state->user = $user;
						return $notification;
					}
				);
				$notification->method('setObject')->willReturnCallback(
					function (string $type, string $id) use ($notification, $state): INotification {
						$state->object = $type . ':' . $id;
						return $notification;
					}
				);
				$notification->method('setSubject')->willReturnCallback(
					function (string $subject) use ($notification, $state): INotification {
						$state->subject = $subject;
						return $notification;
					}
				);
				// Stash the state-bag on the mock via a side-channel array so
				// notify() can retrieve it deterministically by identity.
				$this->notificationState[spl_object_id($notification)] = $state;

				return $notification;
			}
		);

		$manager->method('notify')->willReturnCallback(
			function (INotification $notification) use (&$captured): void {
				$state = ($this->notificationState[spl_object_id($notification)] ?? null);
				if ($state !== null) {
					$captured[] = [
						'user' => $state->user,
						'subject' => $state->subject,
						'object' => $state->object,
					];
				}
			}
		);

		return $manager;
	}//end notificationManagerCapturing()

	/**
	 * Per-notification mock state-bag (spl_object_id => stdClass).
	 *
	 * @var array<int,object>
	 */
	private array $notificationState = [];

	/**
	 * Build the service over an in-memory ObjectService stub.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $data Schema => rows.
	 * @param array<int,array<string,mixed>> $saved Captured saves (by reference).
	 * @param string $userId Authenticated uid.
	 * @param array<int,string> $accessibleAdministrations Tenants canAccess returns true for.
	 * @param array<int,array{user:string,subject:string,object:string}> $notifications Captured notifications (by reference).
	 *
	 * @return PurchaseOrderService
	 */
	private function buildService(
		array $data,
		array &$saved,
		string $userId,
		array $accessibleAdministrations,
		array &$notifications,
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

		$notificationManager = $this->notificationManagerCapturing($notifications);

		return new PurchaseOrderService(
			appConfig: $this->appConfig,
			administrationContext: $administrationContext,
			notificationManager: $notificationManager,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($stub),
		);

	}//end buildService()

	/**
	 * A 5k PO needs only one approver (Teamleider).
	 *
	 * @return void
	 */
	public function testDetermineApprovalChainFiveThousand(): void {
		$saved = [];
		$notifications = [];
		$service = $this->buildService(
			data: [],
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
			notifications: $notifications
		);

		$chain = $service->determineApprovalChain(amount: 5000.00);

		self::assertSame(
			[
				['role' => 'teamleider', 'order' => 1],
			],
			$chain
		);

	}//end testDetermineApprovalChainFiveThousand()

	/**
	 * A 10k PO needs Teamleider + Facility Manager.
	 *
	 * @return void
	 */
	public function testDetermineApprovalChainTenThousand(): void {
		$saved = [];
		$notifications = [];
		$service = $this->buildService(
			data: [],
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
			notifications: $notifications
		);

		$chain = $service->determineApprovalChain(amount: 10000.00);

		self::assertSame(
			[
				['role' => 'teamleider', 'order' => 1],
				['role' => 'facility_manager', 'order' => 2],
			],
			$chain
		);

	}//end testDetermineApprovalChainTenThousand()

	/**
	 * The €18,500 fraud-prevention scenario falls into the double-approver tier
	 * (Teamleider + Facility Manager).
	 *
	 * @return void
	 */
	public function testDetermineApprovalChainEighteenThousandFiveHundred(): void {
		$saved = [];
		$notifications = [];
		$service = $this->buildService(
			data: [],
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
			notifications: $notifications
		);

		$chain = $service->determineApprovalChain(amount: 18500.00);

		self::assertCount(2, $chain);
		self::assertSame('teamleider', $chain[0]['role']);
		self::assertSame('facility_manager', $chain[1]['role']);

	}//end testDetermineApprovalChainEighteenThousandFiveHundred()

	/**
	 * A 50k PO escalates to the procurement manager (three-approver tier).
	 *
	 * @return void
	 */
	public function testDetermineApprovalChainFiftyThousand(): void {
		$saved = [];
		$notifications = [];
		$service = $this->buildService(
			data: [],
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
			notifications: $notifications
		);

		$chain = $service->determineApprovalChain(amount: 50000.00);

		self::assertSame(
			[
				['role' => 'teamleider', 'order' => 1],
				['role' => 'facility_manager', 'order' => 2],
				['role' => 'procurement_manager', 'order' => 3],
			],
			$chain
		);

	}//end testDetermineApprovalChainFiftyThousand()

	/**
	 * Exercises createPurchaseOrder: materialises the chain, persists tasks and
	 * dispatches notifications (integration of service surfaces).
	 *
	 * @return void
	 */
	public function testCreatePurchaseOrderMaterialisesChain(): void {
		$saved = [];
		$notifications = [];
		$data = [
			'AdministrationMembership' => [
				['administrationId' => 'adm-1', 'role' => 'teamleider', 'userId' => 'teamleider-1'],
				['administrationId' => 'adm-1', 'role' => 'facility_manager', 'userId' => 'facility-1'],
				['administrationId' => 'adm-1', 'role' => 'procurement_manager', 'userId' => 'procurement-1'],
			],
			'PurchaseOrder' => [],
			'ApprovalTask' => [],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
			notifications: $notifications
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
		self::assertSame('pending_approval', $po['lifecycleState']);
		self::assertCount(2, $po['approvalChain']);
		self::assertSame('inkoper-1', $po['requesterId']);
		self::assertNotEmpty($po['poNumber']);

		// Two ApprovalTask + 1 PurchaseOrder = three saves; two notifications
		// (one per approver).
		$tasks = array_values(array_filter($saved, static fn (array $row): bool => $row['schema'] === 'ApprovalTask'));
		self::assertCount(2, $tasks);

		self::assertCount(2, $notifications);
		$users = array_map(static fn (array $n): string => $n['user'], $notifications);
		self::assertContains('teamleider-1', $users);
		self::assertContains('facility-1', $users);

	}//end testCreatePurchaseOrderMaterialisesChain()

	/**
	 * Exercises createPurchaseOrder: rejects a cross-tenant caller (IDOR).
	 *
	 * @return void
	 */
	public function testCreatePurchaseOrderRejectsCrossTenant(): void {
		$saved = [];
		$notifications = [];
		$service = $this->buildService(
			data: [],
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
			notifications: $notifications
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
	 * Exercises blockSendUntilApproved: refuses to advance while any approver is
	 * pending, succeeds once every approver has signed with a timestamp.
	 *
	 * @return void
	 */
	public function testBlockSendUntilApprovedGuard(): void {
		$saved = [];
		$notifications = [];

		$data = [
			'PurchaseOrder' => [
				[
					'id' => 'po-1',
					'administrationId' => 'adm-1',
					'poNumber' => 'PO-2026-adm-1-000001',
					'lifecycleState' => 'pending_approval',
					'approvalChain' => [
						[
							'role' => 'teamleider',
							'order' => 1,
							'status' => 'approved',
							'signedAt' => '2026-06-01T12:00:00+00:00',
							'signedBy' => 'teamleider-1',
						],
						[
							'role' => 'facility_manager',
							'order' => 2,
							'status' => 'pending',
							'signedAt' => '',
							'signedBy' => '',
						],
					],
				],
			],
		];

		$service = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
			notifications: $notifications
		);

		// First attempt: only one of two approvals signed.
		try {
			$service->blockSendUntilApproved(administrationId: 'adm-1', purchaseOrderId: 'po-1');
			self::fail('Expected blockSendUntilApproved to refuse incomplete chain');
		} catch (\RuntimeException $e) {
			self::assertSame('Purchase order cannot be sent: approval chain incomplete', $e->getMessage());
		}

		// Second attempt: both signed.
		$data['PurchaseOrder'][0]['approvalChain'][1]['status'] = 'approved';
		$data['PurchaseOrder'][0]['approvalChain'][1]['signedAt'] = '2026-06-02T09:00:00+00:00';
		$data['PurchaseOrder'][0]['approvalChain'][1]['signedBy'] = 'facility-1';

		$service2 = $this->buildService(
			data: $data,
			saved: $saved,
			userId: 'inkoper-1',
			accessibleAdministrations: ['adm-1'],
			notifications: $notifications
		);

		$po = $service2->blockSendUntilApproved(administrationId: 'adm-1', purchaseOrderId: 'po-1');
		self::assertSame('sent', $po['lifecycleState']);
		self::assertNotEmpty($po['sentAt']);

	}//end testBlockSendUntilApprovedGuard()
}//end class
