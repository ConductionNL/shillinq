<?php

/**
 * Unit tests for InventoryMobileScannerService sync: delta download and mutation upload.
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
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\InventoryMobileScannerService;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Drives downloadDeltas() and uploadOperations() against an in-memory register.
 *
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 */
#[CoversClass(InventoryMobileScannerService::class)]
#[UsesClass(RegisterLocation::class)]
final class InventoryMobileScannerServiceSyncTest extends TestCase {

	/**
	 * Fixed server clock.
	 */
	private const NOW = 1780000000;

	/**
	 * Fixed server clock as ISO 8601 UTC.
	 */
	private const NOW_ISO = '2026-05-28T20:26:40Z';

	/**
	 * Container mock.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * App manager mock.
	 *
	 * @var IAppManager&MockObject
	 */
	private IAppManager&MockObject $appManager;

	/**
	 * Logger mock.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Service under test.
	 *
	 * @var InventoryMobileScannerService
	 */
	private InventoryMobileScannerService $service;

	/**
	 * Build the service with a real RegisterLocation and a pinned clock.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturn('stock');

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);

		$this->service = new InventoryMobileScannerService(
			registerLocation: new RegisterLocation(appConfig: $appConfig, appManager: $this->appManager),
			container: $this->container,
			time: $time,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Install an object service behind the container with OpenRegister available.
	 *
	 * @param ObjectServiceInterface $objectService The store.
	 *
	 * @return void
	 */
	private function useStore(ObjectServiceInterface $objectService): void {
		$this->appManager->method('isInstalled')->with('openregister')->willReturn(true);
		$this->container->method('get')
			->with('OCA\\OpenRegister\\Service\\ObjectService')
			->willReturn($objectService);
	}//end useStore()

	/**
	 * Saved rows for one schema.
	 *
	 * @param InMemoryObjectServiceStub $store  The store.
	 * @param string                    $schema Schema slug.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function savedFor(InMemoryObjectServiceStub $store, string $schema): array {
		return array_values(
			array_map(
				static fn (array $s): array => $s['object'],
				array_filter($store->saved, static fn (array $s): bool => $s['schema'] === $schema)
			)
		);
	}//end savedFor()

	/**
	 * Upload one operation as a user with the given roles.
	 *
	 * @param array<string,mixed> $op    The operation.
	 * @param list<string>        $roles The roles.
	 *
	 * @return array<string,mixed> The single ACK.
	 */
	private function upload(array $op, array $roles = ['admin']): array {
		$result = $this->service->uploadOperations(operations: [$op], userId: 'u1', roles: $roles, administrationId: 'adm');
		self::assertSame(self::NOW_ISO, $result['serverTimestamp']);
		self::assertCount(1, $result['results']);
		return $result['results'][0];
	}//end upload()

	/**
	 * downloadDeltas() refuses an empty administrationId with a warning.
	 *
	 * @return void
	 */
	public function testDownloadRequiresAdministrationId(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->container->expects(self::never())->method('get');
		$this->logger->expects(self::once())
			->method('warning')
			->with('Shillinq mobile scanner: empty administrationId on download; returning empty deltas');

		self::assertSame(
			['deltas' => [], 'serverTimestamp' => self::NOW_ISO],
			$this->service->downloadDeltas(since: null, administrationId: '')
		);

	}//end testDownloadRequiresAdministrationId()

	/**
	 * downloadDeltas() keeps rows modified after `since` (and unparseable ones) for the tenant only.
	 *
	 * @return void
	 */
	public function testDownloadFiltersByTenantAndSince(): void {
		$store = new InMemoryObjectServiceStub(
			data: [
				'InventoryStock' => [
					['administrationId' => 'adm', 'sku' => 'A', 'location' => 'L1', 'quantity' => 5, 'lastModified' => '2026-05-01T00:00:00Z'],
					['administrationId' => 'adm', 'sku' => 'B', 'location' => 'L1', 'quantity' => '2.5', 'lastModified' => '2026-05-20T00:00:00Z', 'status' => 'archived'],
					['administrationId' => 'adm', 'sku' => 'C', 'location' => 'L2', 'lastModified' => '2026-05-10T00:00:00Z'],
					['administrationId' => 'adm', 'sku' => 'D', 'location' => 'L2', 'quantity' => 1],
					['administrationId' => 'other', 'sku' => 'X', 'location' => 'L1', 'quantity' => 9, 'lastModified' => '2026-05-25T00:00:00Z'],
				],
			],
			findAllRendersEntities: true
		);
		$this->useStore(objectService: $store);

		$result = $this->service->downloadDeltas(since: '2026-05-10T00:00:00Z', administrationId: 'adm');

		self::assertSame(self::NOW_ISO, $result['serverTimestamp']);
		self::assertSame(
			[
				['sku' => 'B', 'location' => 'L1', 'quantity' => 2.5, 'lastModified' => '2026-05-20T00:00:00Z', 'status' => 'archived'],
				['sku' => 'D', 'location' => 'L2', 'quantity' => 1.0, 'lastModified' => '', 'status' => 'active'],
			],
			$result['deltas']
		);

		self::assertCount(4, $this->service->downloadDeltas(since: '', administrationId: 'adm')['deltas']);
		self::assertCount(2, $this->service->downloadDeltas(since: null, administrationId: 'adm', limit: 2)['deltas']);

	}//end testDownloadFiltersByTenantAndSince()

	/**
	 * downloadDeltas() projects plain-object rows and an unparseable `since` keeps everything.
	 *
	 * @return void
	 */
	public function testDownloadProjectsPlainObjectsAndKeepsOnUnparseableSince(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->expects(self::once())->method('setRegister')->with('stock')->willReturnSelf();
		$objectService->expects(self::once())->method('setSchema')->with('InventoryStock')->willReturnSelf();
		$objectService->expects(self::once())
			->method('findAll')
			->with(['filters' => ['administrationId' => 'adm'], 'limit' => 500])
			->willReturn([(object)['sku' => 'P', 'location' => 'L9', 'quantity' => 3, 'lastModified' => '2020-01-01T00:00:00Z'], 42]);
		$this->useStore(objectService: $objectService);

		$result = $this->service->downloadDeltas(since: 'not-a-date', administrationId: 'adm');

		self::assertSame(
			[
				['sku' => 'P', 'location' => 'L9', 'quantity' => 3.0, 'lastModified' => '2020-01-01T00:00:00Z', 'status' => 'active'],
				['sku' => '', 'location' => '', 'quantity' => 0.0, 'lastModified' => '', 'status' => 'active'],
			],
			$result['deltas']
		);

	}//end testDownloadProjectsPlainObjectsAndKeepsOnUnparseableSince()

	/**
	 * downloadDeltas() logs a lookup failure and returns no deltas.
	 *
	 * @return void
	 */
	public function testDownloadFailureIsLogged(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->container->method('get')->willThrowException(new RuntimeException('no OR'));
		$this->logger->expects(self::once())
			->method('error')
			->with('Shillinq mobile scanner: failed to download deltas', ['exception' => 'no OR']);

		self::assertSame(
			['deltas' => [], 'serverTimestamp' => self::NOW_ISO],
			$this->service->downloadDeltas(since: null, administrationId: 'adm')
		);

	}//end testDownloadFailureIsLogged()

	/**
	 * uploadOperations() rejects every operation when OpenRegister is absent.
	 *
	 * @return void
	 */
	public function testUploadRejectsAllWithoutOpenRegister(): void {
		$this->appManager->method('isInstalled')->willReturn(false);
		$this->container->expects(self::never())->method('get');

		$result = $this->service->uploadOperations(
			operations: [['transactionId' => 't1'], []],
			userId: 'u1',
			roles: ['admin'],
			administrationId: 'adm'
		);

		self::assertSame(
			[
				['transactionId' => 't1', 'status' => 'rejected_validation', 'reason' => 'OpenRegister unavailable'],
				['transactionId' => '', 'status' => 'rejected_validation', 'reason' => 'OpenRegister unavailable'],
			],
			$result['results']
		);

	}//end testUploadRejectsAllWithoutOpenRegister()

	/**
	 * uploadOperations() rejects every operation when the ObjectService cannot be resolved.
	 *
	 * @return void
	 */
	public function testUploadRejectsAllWhenBackendUnavailable(): void {
		$this->appManager->method('isInstalled')->willReturn(true);
		$this->container->method('get')->willThrowException(new RuntimeException('boom'));
		$this->logger->expects(self::once())
			->method('error')
			->with('Shillinq mobile scanner: failed to acquire ObjectService', ['exception' => 'boom']);

		self::assertSame(
			['transactionId' => 't1', 'status' => 'rejected_validation', 'reason' => 'sync backend unavailable'],
			$this->upload(op: ['transactionId' => 't1'])
		);

	}//end testUploadRejectsAllWhenBackendUnavailable()

	/**
	 * Each shape defect is reported with its own reason and nothing is written.
	 *
	 * @return void
	 */
	public function testValidationReasons(): void {
		$store = new InMemoryObjectServiceStub();
		$this->useStore(objectService: $store);

		$base = ['transactionId' => 't', 'type' => 'receive', 'sku' => 'A', 'location' => 'L1', 'quantity' => 1];
		$cases = [
			'missing transactionId' => ['transactionId' => ''],
			'unknown operation type' => ['type' => 'steal'],
			'missing sku' => ['sku' => ''],
			'missing location' => ['location' => ''],
			'transfer requires toLocation' => ['type' => 'transfer'],
			'transfer source and destination must differ' => ['type' => 'transfer', 'toLocation' => 'L1'],
			'quantity must be numeric' => ['quantity' => 'lots'],
			'quantity must be strictly positive' => ['type' => 'pick', 'quantity' => 0],
			'physicalQuantity must be a non-negative number' => ['type' => 'count', 'physicalQuantity' => -1],
		];

		foreach ($cases as $reason => $override) {
			$ack = $this->upload(op: array_replace($base, $override));
			self::assertSame('rejected_validation', $ack['status'], $reason);
			self::assertSame($reason, $ack['reason']);
		}

		self::assertSame([], $store->saved);

	}//end testValidationReasons()

	/**
	 * An accepted receive creates the stock row, a GoodsReceipt and an accepted sync batch.
	 *
	 * @return void
	 */
	public function testReceiveCreatesStockReceiptAndBatch(): void {
		$store = new InMemoryObjectServiceStub();
		$this->useStore(objectService: $store);

		$ack = $this->upload(
			op: ['transactionId' => 't1', 'type' => 'receive', 'sku' => 'A', 'location' => 'L1', 'quantity' => '4', 'timestamp' => '2026-05-28T20:00:00Z'],
			roles: ['warehouse_manager']
		);

		self::assertSame(['transactionId' => 't1', 'status' => 'accepted', 'serverAckedAt' => self::NOW_ISO], $ack);
		self::assertSame(
			[['administrationId' => 'adm', 'sku' => 'A', 'location' => 'L1', 'quantity' => 4.0, 'lastModified' => self::NOW_ISO, 'status' => 'active', 'id' => 'obj-1']],
			$this->savedFor(store: $store, schema: 'InventoryStock')
		);
		self::assertSame(
			[['administrationId' => 'adm', 'sku' => 'A', 'location' => 'L1', 'quantity' => 4.0, 'userId' => 'u1', 'occurredAt' => self::NOW_ISO, 'transactionId' => 't1', 'id' => 'obj-2']],
			$this->savedFor(store: $store, schema: 'GoodsReceipt')
		);
		self::assertSame(
			[
				[
					'administrationId' => 'adm',
					'transactionId' => 't1',
					'operationType' => 'receive',
					'sku' => 'A',
					'location' => 'L1',
					'toLocation' => null,
					'quantityDelta' => 4.0,
					'userId' => 'u1',
					'occurredAt' => '2026-05-28T20:00:00Z',
					'ackedAt' => self::NOW_ISO,
					'status' => 'accepted',
					'rejectionReason' => null,
					'id' => 'obj-3',
				],
			],
			$this->savedFor(store: $store, schema: 'MobileScannerSyncBatch')
		);

	}//end testReceiveCreatesStockReceiptAndBatch()

	/**
	 * A transfer debits the source (floored at zero) and credits a new destination row.
	 *
	 * @return void
	 */
	public function testTransferMovesStockBetweenLocations(): void {
		$store = new InMemoryObjectServiceStub(
			data: ['InventoryStock' => [['id' => 's1', 'administrationId' => 'adm', 'sku' => 'A', 'location' => 'L1', 'quantity' => 2]]],
			findAllRendersEntities: true
		);
		$this->useStore(objectService: $store);

		$ack = $this->upload(
			op: ['transactionId' => 't2', 'type' => 'transfer', 'sku' => 'A', 'location' => 'L1', 'toLocation' => 'L2', 'quantity' => 3],
			roles: ['inventory_operator']
		);

		self::assertSame('accepted', $ack['status']);
		$stock = $this->savedFor(store: $store, schema: 'InventoryStock');
		self::assertSame('s1', $stock[0]['id']);
		self::assertSame(0.0, $stock[0]['quantity']);
		self::assertSame(self::NOW_ISO, $stock[0]['lastModified']);
		self::assertSame('L2', $stock[1]['location']);
		self::assertSame(3.0, $stock[1]['quantity']);

		$transfer = $this->savedFor(store: $store, schema: 'InventoryTransfer');
		self::assertSame(['L1', 'L2', 3.0], [$transfer[0]['fromLocation'], $transfer[0]['toLocation'], $transfer[0]['quantity']]);
		$batch = $this->savedFor(store: $store, schema: 'MobileScannerSyncBatch');
		self::assertSame('L2', $batch[0]['toLocation']);
		self::assertSame(self::NOW_ISO, $batch[0]['occurredAt']);

	}//end testTransferMovesStockBetweenLocations()

	/**
	 * A pick debits stock, writes an OrderPick and records a negative batch delta.
	 *
	 * @return void
	 */
	public function testPickDebitsStockAndRecordsNegativeDelta(): void {
		$store = new InMemoryObjectServiceStub(
			data: ['InventoryStock' => [['id' => 's1', 'administrationId' => 'adm', 'sku' => 'A', 'location' => 'L1', 'quantity' => 10]]]
		);
		$this->useStore(objectService: $store);

		$ack = $this->upload(
			op: ['transactionId' => 't3', 'type' => 'pick', 'sku' => 'A', 'location' => 'L1', 'quantity' => 4, 'orderLineId' => 'ol-7'],
			roles: ['inventory_operator']
		);

		self::assertSame('accepted', $ack['status']);
		self::assertSame(6.0, $this->savedFor(store: $store, schema: 'InventoryStock')[0]['quantity']);
		$pick = $this->savedFor(store: $store, schema: 'OrderPick')[0];
		self::assertSame('ol-7', $pick['orderLineId']);
		self::assertSame(4.0, $pick['quantity']);
		self::assertSame(-4.0, $this->savedFor(store: $store, schema: 'MobileScannerSyncBatch')[0]['quantityDelta']);

	}//end testPickDebitsStockAndRecordsNegativeDelta()

	/**
	 * A reconciling count records the variance and sets stock to the physical quantity.
	 *
	 * @return void
	 */
	public function testReconcilingCountAdjustsStock(): void {
		$store = new InMemoryObjectServiceStub(
			data: ['InventoryStock' => [['id' => 's1', 'administrationId' => 'adm', 'sku' => 'A', 'location' => 'L1', 'quantity' => 10]]],
			findAllRendersEntities: true
		);
		$this->useStore(objectService: $store);

		$ack = $this->upload(
			op: ['transactionId' => 't4', 'type' => 'count', 'sku' => 'A', 'location' => 'L1', 'quantity' => 0, 'physicalQuantity' => 7, 'reconcile' => true],
			roles: ['counter']
		);

		self::assertSame('accepted', $ack['status']);
		$count = $this->savedFor(store: $store, schema: 'InventoryCount')[0];
		self::assertSame([10.0, 7.0, -3.0, true], [$count['systemQuantity'], $count['physicalQuantity'], $count['variance'], $count['reconciled']]);
		self::assertSame(7.0, $this->savedFor(store: $store, schema: 'InventoryStock')[0]['quantity']);

	}//end testReconcilingCountAdjustsStock()

	/**
	 * A non-reconciling count of an unknown location records system quantity 0 and leaves stock alone.
	 *
	 * @return void
	 */
	public function testCountWithoutReconcileLeavesStock(): void {
		$store = new InMemoryObjectServiceStub();
		$this->useStore(objectService: $store);

		$ack = $this->upload(
			op: ['transactionId' => 't5', 'type' => 'count', 'sku' => 'A', 'location' => 'L9', 'quantity' => 0, 'physicalQuantity' => 2],
			roles: ['administrator']
		);

		self::assertSame('accepted', $ack['status']);
		$count = $this->savedFor(store: $store, schema: 'InventoryCount')[0];
		self::assertSame([0.0, 2.0, 2.0, false], [$count['systemQuantity'], $count['physicalQuantity'], $count['variance'], $count['reconciled']]);
		self::assertSame([], $this->savedFor(store: $store, schema: 'InventoryStock'));

	}//end testCountWithoutReconcileLeavesStock()

	/**
	 * A permission rejection is recorded as a sync batch with the required roles.
	 *
	 * @return void
	 */
	public function testPermissionRejectionIsRecorded(): void {
		$store = new InMemoryObjectServiceStub();
		$this->useStore(objectService: $store);

		$ack = $this->upload(
			op: ['transactionId' => 't6', 'type' => 'count', 'sku' => 'A', 'location' => 'L1', 'quantity' => 'n/a', 'physicalQuantity' => 1],
			roles: ['inventory_operator']
		);

		self::assertSame('quantity must be numeric', $ack['reason']);

		$ack = $this->upload(
			op: ['transactionId' => 't6', 'type' => 'receive', 'sku' => 'A', 'location' => 'L1', 'quantity' => 1],
			roles: ['inventory_operator']
		);

		self::assertSame(['transactionId' => 't6', 'status' => 'rejected_permission', 'reason' => 'role required: warehouse_manager'], $ack);
		$batch = $this->savedFor(store: $store, schema: 'MobileScannerSyncBatch');
		self::assertCount(1, $batch);
		self::assertSame('rejected_permission', $batch[0]['status']);
		self::assertSame('role required: warehouse_manager', $batch[0]['rejectionReason']);
		self::assertSame([], $this->savedFor(store: $store, schema: 'GoodsReceipt'));

	}//end testPermissionRejectionIsRecorded()

	/**
	 * Duplicate detection honours the dedup window and treats undated rows as duplicates.
	 *
	 * @return void
	 */
	public function testDuplicateWindow(): void {
		$store = new InMemoryObjectServiceStub(
			data: [
				'MobileScannerSyncBatch' => [
					['administrationId' => 'adm', 'transactionId' => 'recent', 'occurredAt' => '2026-05-28T10:00:00Z', 'ackedAt' => '2026-05-28T10:00:05Z'],
					['administrationId' => 'adm', 'transactionId' => 'undated', 'occurredAt' => ''],
					['administrationId' => 'adm', 'transactionId' => 'garbled', 'occurredAt' => '#@!', 'ackedAt' => 'G'],
					['administrationId' => 'adm', 'transactionId' => 'stale', 'occurredAt' => '2026-05-26T10:00:00Z'],
					['administrationId' => 'other', 'transactionId' => 'foreign', 'occurredAt' => '2026-05-28T10:00:00Z'],
				],
			],
			findAllRendersEntities: true
		);
		$this->useStore(objectService: $store);

		$op = ['type' => 'receive', 'sku' => 'A', 'location' => 'L1', 'quantity' => 1];
		$status = [];
		foreach (['recent', 'undated', 'garbled', 'stale', 'foreign'] as $transactionId) {
			$status[$transactionId] = $this->upload(op: $op + ['transactionId' => $transactionId]);
		}

		self::assertSame(
			['transactionId' => 'recent', 'status' => 'duplicate', 'serverAckedAt' => '2026-05-28T10:00:05Z', 'reason' => 'transactionId already processed'],
			$status['recent']
		);
		self::assertSame(self::NOW_ISO, $status['undated']['serverAckedAt']);
		self::assertSame('duplicate', $status['undated']['status']);
		self::assertSame(['duplicate', 'G'], [$status['garbled']['status'], $status['garbled']['serverAckedAt']]);
		self::assertSame('accepted', $status['stale']['status']);
		self::assertSame('accepted', $status['foreign']['status']);
		self::assertCount(2, $this->savedFor(store: $store, schema: 'GoodsReceipt'));

	}//end testDuplicateWindow()

	/**
	 * A failing dedup lookup is logged and a failing mutation is reported per operation.
	 *
	 * @return void
	 */
	public function testDedupAndMutationFailures(): void {
		$objectService = $this->createMock(ObjectServiceInterface::class);
		$objectService->method('setRegister')->with('stock')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willThrowException(new RuntimeException('index offline'));
		$objectService->expects(self::never())->method('saveObject');
		$this->useStore(objectService: $objectService);

		$this->logger->expects(self::once())
			->method('warning')
			->with('Shillinq mobile scanner: dedup lookup failed; proceeding as non-duplicate', ['exception' => 'index offline', 'transactionId' => 't7']);
		$this->logger->expects(self::once())
			->method('error')
			->with('Shillinq mobile scanner: mutation failed', ['exception' => 'index offline', 'transactionId' => 't7']);

		self::assertSame(
			['transactionId' => 't7', 'status' => 'rejected_validation', 'reason' => 'mutation failed: index offline'],
			$this->upload(op: ['transactionId' => 't7', 'type' => 'receive', 'sku' => 'A', 'location' => 'L1', 'quantity' => 1])
		);

	}//end testDedupAndMutationFailures()
}//end class
