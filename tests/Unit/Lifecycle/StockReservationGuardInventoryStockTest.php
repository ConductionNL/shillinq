<?php

/**
 * Unit tests for StockReservationGuard::checkReservationDoesNotExceedOnHand().
 *
 * Covers the inventory-stock-tracking REQ-IST-013 precondition: a
 * proposed InventoryStock row whose quantityReserved exceeds its
 * quantityOnHand MUST be rejected at the lifecycle validation seam so
 * the operator gets a clear "insufficient available quantity" error
 * rather than a silently-corrupted snapshot. Separate from the
 * StockMove-side reserve / commit / release tests under
 * StockReservationGuardTest.php (inventory-stock-movement-ledger) —
 * shares the class because both paths share the over-allocation
 * invariant.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-stock-tracking/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Lifecycle\StockReservationGuard;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests REQ-IST-013 — quantityReserved must not exceed quantityOnHand
 * on InventoryStock create / update.
 */
class StockReservationGuardInventoryStockTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var StockReservationGuard
	 */
	private StockReservationGuard $guard;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$container = $this->createStub(ContainerInterface::class);
		$appConfig = $this->createStub(IAppConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->guard = new StockReservationGuard(
			appConfig: $appConfig,
			logger: $this->logger,
			objectService: $this->createMock(ObjectServiceInterface::class),
		);

	}//end setUp()

	/**
	 * Reservation equal to on-hand is permitted (REQ-IST-013 boundary).
	 *
	 * @return void
	 */
	public function testReservationEqualToOnHandPermitsCreate(): void {
		$stock = [
			'productSku' => 'LAPTOP-DELL-XPS13',
			'locationCode' => 'WH-AMS-001',
			'quantityOnHand' => 10.0,
			'quantityReserved' => 10.0,
		];

		$this->assertTrue(
			$this->guard->checkReservationDoesNotExceedOnHand(stock: $stock)
		);

	}//end testReservationEqualToOnHandPermitsCreate()

	/**
	 * Reservation below on-hand is permitted (the normal case).
	 *
	 * @return void
	 */
	public function testReservationBelowOnHandPermitsCreate(): void {
		$stock = [
			'productSku' => 'TONER-HP-CF283A',
			'locationCode' => 'WH-AMS-001',
			'quantityOnHand' => 150.0,
			'quantityReserved' => 20.0,
		];

		$this->assertTrue(
			$this->guard->checkReservationDoesNotExceedOnHand(stock: $stock)
		);

	}//end testReservationBelowOnHandPermitsCreate()

	/**
	 * Reservation exceeding on-hand is denied (REQ-IST-013 scenario).
	 *
	 * @return void
	 */
	public function testReservationExceedingOnHandDeniesUpdate(): void {
		$stock = [
			'productSku' => 'LAPTOP-DELL-XPS13',
			'locationCode' => 'WH-AMS-001',
			'quantityOnHand' => 50.0,
			'quantityReserved' => 75.0,
		];

		$this->logger->expects($this->once())->method('info');

		$this->assertFalse(
			$this->guard->checkReservationDoesNotExceedOnHand(stock: $stock)
		);

	}//end testReservationExceedingOnHandDeniesUpdate()

	/**
	 * Missing quantity fields default to zero — both zero is permitted
	 * (no reservation, no on-hand: nothing to block).
	 *
	 * @return void
	 */
	public function testMissingQuantitiesPermitCreate(): void {
		$stock = [
			'productSku' => 'BOX-CARDBOARD-S',
			'locationCode' => 'WH-AMS-001',
		];

		$this->assertTrue(
			$this->guard->checkReservationDoesNotExceedOnHand(stock: $stock)
		);

	}//end testMissingQuantitiesPermitCreate()

	/**
	 * Reservation > 0 with zero on-hand is denied — REQ-IST-013 catches
	 * the on-hand decrease that leaves a reservation under-collateralised.
	 *
	 * @return void
	 */
	public function testReservationWithZeroOnHandDeniesUpdate(): void {
		$stock = [
			'productSku' => 'NOTEBOOK-A4-100',
			'locationCode' => 'ST-UTR-001',
			'quantityOnHand' => 0.0,
			'quantityReserved' => 5.0,
		];

		$this->logger->expects($this->once())->method('info');

		$this->assertFalse(
			$this->guard->checkReservationDoesNotExceedOnHand(stock: $stock)
		);

	}//end testReservationWithZeroOnHandDeniesUpdate()

	/**
	 * Fractional reservation just over on-hand (0.01 over) is denied —
	 * the rule is strict, not tolerant of small floats.
	 *
	 * @return void
	 */
	public function testFractionalOverflowDeniesUpdate(): void {
		$stock = [
			'productSku' => 'USB-DRIVE-32GB',
			'locationCode' => 'WH-RTM-001',
			'quantityOnHand' => 30.0,
			'quantityReserved' => 30.01,
		];

		$this->logger->expects($this->once())->method('info');

		$this->assertFalse(
			$this->guard->checkReservationDoesNotExceedOnHand(stock: $stock)
		);

	}//end testFractionalOverflowDeniesUpdate()

}//end class
