<?php

/**
 * Unit tests for InventoryMobileScannerService.
 *
 * Cover the sync core's three load-bearing behaviours per
 * REQ-OFFLINE-002 / REQ-SYNC-001 / REQ-PERM-001:
 *
 *   1. downloadDeltas() filters InventoryStock rows by lastModified > since
 *      and stamps a server timestamp on every response.
 *   2. uploadOperations() validates schema, deduplicates by transactionId,
 *      gates by role, and reports each ACK independently.
 *   3. The role gate accepts the configured admin role even without the
 *      operation-specific role (cluster admin / break-glass).
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
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\InventoryMobileScannerService;
use OCA\Shillinq\Service\SettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit coverage for the sync core.
 */
final class InventoryMobileScannerServiceTest extends TestCase {

	/**
	 * Mock SettingsService.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Mock container.
	 *
	 * @var ContainerInterface&MockObject
	 */
	private ContainerInterface&MockObject $container;

	/**
	 * Mock time factory.
	 *
	 * @var ITimeFactory&MockObject
	 */
	private ITimeFactory&MockObject $time;

	/**
	 * Mock logger.
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
	 * Fixed UTC epoch used by the time mock so server timestamps stay stable.
	 *
	 * @var int
	 */
	private int $fixedEpoch = 1748880000;

	/**
	 * Set up the mocks + service under test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->time->method('getTime')->willReturn($this->fixedEpoch);

		$this->service = new InventoryMobileScannerService(
			settings: $this->settings,
			container: $this->container,
			time: $this->time,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * downloadDeltas returns an empty list when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testDownloadDeltasReturnsEmptyWhenOpenRegisterUnavailable(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);

		$out = $this->service->downloadDeltas(since: null, administrationId: 'adm-1');

		self::assertSame([], $out['deltas']);
		self::assertNotEmpty($out['serverTimestamp']);

	}//end testDownloadDeltasReturnsEmptyWhenOpenRegisterUnavailable()

	/**
	 * uploadOperations rejects every row when the supplied tenant or user is empty.
	 *
	 * @return void
	 */
	public function testUploadOperationsRejectsWhenTenantMissing(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);

		$out = $this->service->uploadOperations(
			operations: [
				['transactionId' => 't1', 'type' => 'receive', 'sku' => 'A', 'location' => 'L1', 'quantity' => 1],
			],
			userId: 'alice',
			roles: ['warehouse_manager'],
			administrationId: '',
		);

		self::assertCount(1, $out['results']);
		self::assertSame('rejected_validation', $out['results'][0]['status']);

	}//end testUploadOperationsRejectsWhenTenantMissing()

	/**
	 * Validate() rejects operations missing transactionId, sku, or quantity.
	 *
	 * @return void
	 */
	public function testUploadOperationsValidatesShape(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

		$objectService = $this->createMock(InventoryMobileScannerStubObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn([]);

		$this->container->method('get')->willReturn($objectService);

		$out = $this->service->uploadOperations(
			operations: [
				['type' => 'receive', 'sku' => 'A', 'location' => 'L1', 'quantity' => 1],
				['transactionId' => 't2', 'type' => 'banana', 'sku' => 'A', 'location' => 'L1', 'quantity' => 1],
				['transactionId' => 't3', 'type' => 'pick', 'sku' => 'A', 'location' => 'L1', 'quantity' => 0],
				['transactionId' => 't4', 'type' => 'transfer', 'sku' => 'A', 'location' => 'L1', 'toLocation' => 'L1', 'quantity' => 1],
			],
			userId: 'alice',
			roles: ['warehouse_manager', 'inventory_operator'],
			administrationId: 'adm-1',
		);

		$statuses = array_column($out['results'], 'status');
		self::assertSame(
			['rejected_validation', 'rejected_validation', 'rejected_validation', 'rejected_validation'],
			$statuses,
		);

	}//end testUploadOperationsValidatesShape()

	/**
	 * Role gate denies the operation when the user lacks the required role,
	 * and records a rejected_permission audit row.
	 *
	 * @return void
	 */
	public function testRoleGateRejectsMismatchedRole(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

		$objectService = $this->createMock(InventoryMobileScannerStubObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn([]);

		$captured = [];
		$objectService->method('saveObject')->willReturnCallback(function (...$args) use (&$captured) {
			$named = self::namedArgs($args);
			$captured[] = $named;
			return null;
		});

		$this->container->method('get')->willReturn($objectService);

		$out = $this->service->uploadOperations(
			operations: [
				['transactionId' => 't1', 'type' => 'receive', 'sku' => 'A', 'location' => 'L1', 'quantity' => 1],
			],
			userId: 'alice',
			roles: ['inventory_operator'],
			administrationId: 'adm-1',
		);

		self::assertSame('rejected_permission', $out['results'][0]['status']);
		self::assertNotEmpty($captured, 'A MobileScannerSyncBatch row must be written on rejection');

	}//end testRoleGateRejectsMismatchedRole()

	/**
	 * Admin role is unconditionally permitted (cluster admin / break-glass).
	 *
	 * @return void
	 */
	public function testAdminRolePassesAnyOperation(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

		$objectService = $this->createMock(InventoryMobileScannerStubObjectService::class);
		$objectService->method('setRegister')->willReturnSelf();
		$objectService->method('setSchema')->willReturnSelf();
		$objectService->method('findAll')->willReturn([]);
		$objectService->method('saveObject')->willReturn(null);

		$this->container->method('get')->willReturn($objectService);

		$out = $this->service->uploadOperations(
			operations: [
				['transactionId' => 't1', 'type' => 'receive', 'sku' => 'A', 'location' => 'L1', 'quantity' => 1.5],
			],
			userId: 'root',
			roles: ['admin'],
			administrationId: 'adm-1',
		);

		self::assertSame('accepted', $out['results'][0]['status']);

	}//end testAdminRolePassesAnyOperation()

	/**
	 * A second upload of the same transactionId within the dedup window
	 * returns status=duplicate without re-applying the mutation.
	 *
	 * @return void
	 */
	public function testDuplicateTransactionIdReturnsCachedAck(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(true);
		$this->settings->method('getRegisterSlug')->willReturn('shillinq');

		$existing = [
			[
				'transactionId' => 't-dup',
				'occurredAt' => gmdate('Y-m-d\TH:i:s\Z', ($this->fixedEpoch - 60)),
				'ackedAt' => gmdate('Y-m-d\TH:i:s\Z', ($this->fixedEpoch - 60)),
				'status' => 'accepted',
			],
		];

		$stub = $this->createMock(InventoryMobileScannerStubObjectService::class);
		$stub->method('setRegister')->willReturnSelf();
		$stub->method('setSchema')->willReturnSelf();
		// First findAll() returns the existing dedup row; subsequent calls return empty.
		$stub->method('findAll')->willReturnOnConsecutiveCalls($existing, [], []);
		$stub->expects($this->never())->method('saveObject');

		$this->container->method('get')->willReturn($stub);

		$out = $this->service->uploadOperations(
			operations: [
				['transactionId' => 't-dup', 'type' => 'receive', 'sku' => 'A', 'location' => 'L1', 'quantity' => 1],
			],
			userId: 'alice',
			roles: ['warehouse_manager'],
			administrationId: 'adm-1',
		);

		self::assertSame('duplicate', $out['results'][0]['status']);
		self::assertNotEmpty($out['results'][0]['serverAckedAt']);

	}//end testDuplicateTransactionIdReturnsCachedAck()

	/**
	 * downloadDeltas server timestamp is ISO 8601 UTC.
	 *
	 * @return void
	 */
	public function testDownloadServerTimestampIsIso8601Utc(): void {
		$this->settings->method('isOpenRegisterAvailable')->willReturn(false);

		$out = $this->service->downloadDeltas(since: null, administrationId: 'adm-1');

		self::assertSame(
			gmdate('Y-m-d\TH:i:s\Z', $this->fixedEpoch),
			$out['serverTimestamp'],
		);

	}//end testDownloadServerTimestampIsIso8601Utc()

	/**
	 * Normalise named-argument call to a flat array so assertions are stable
	 * regardless of which named-arg ordering PHPUnit captures.
	 *
	 * @param array<int,mixed> $args Captured call args.
	 *
	 * @return array<string,mixed>
	 */
	private static function namedArgs(array $args): array {
		if (count($args) === 0) {
			return [];
		}
		if (is_array($args[0]) === true && array_keys($args) === [0]) {
			return $args[0];
		}
		return $args;
	}//end namedArgs()

}//end class

/**
 * Minimal stub matching the OR ObjectService surface used by the
 * scanner service. Defined here (not as a real OR class) so the
 * unit test never needs the OpenRegister autoloader.
 *
 * @SuppressWarnings(PHPMD)
 */
abstract class InventoryMobileScannerStubObjectService {

	/**
	 * Set the active register.
	 *
	 * @param string $slug Register slug.
	 *
	 * @return self
	 */
	abstract public function setRegister(string $slug): self;

	/**
	 * Set the active schema.
	 *
	 * @param string $slug Schema slug.
	 *
	 * @return self
	 */
	abstract public function setSchema(string $slug): self;

	/**
	 * Find all matching records.
	 *
	 * @param array<string,mixed> $args Find args.
	 *
	 * @return array<int,mixed>
	 */
	abstract public function findAll(array $args): array;

	/**
	 * Save an object.
	 *
	 * @param mixed $object Object payload.
	 * @param string|null $register Optional register.
	 * @param string|null $schema Optional schema.
	 *
	 * @return mixed
	 */
	abstract public function saveObject(mixed $object, ?string $register = null, ?string $schema = null): mixed;

}//end class
