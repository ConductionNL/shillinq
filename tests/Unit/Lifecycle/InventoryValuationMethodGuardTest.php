<?php

/**
 * Unit tests for InventoryValuationMethodGuard.
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Lifecycle;

use OCA\Shillinq\Lifecycle\InventoryValuationMethodGuard;
use OCA\Shillinq\Tests\Unit\Service\Support\DuckObjectServiceAdapter;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../Service/InMemoryObjectService.php';

/**
 * Tests for InventoryValuationMethodGuard.
 *
 * Covers REQ-INV-006 / REQ-INV-009 zero-stock precondition:
 *  - quantity == 0  => guard returns true (transition permitted)
 *  - quantity > 0   => guard returns false (transition denied)
 *  - missing quantity treated as zero => true
 *  - guard is fail-closed (returns false on any exception path)
 */
class InventoryValuationMethodGuardTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The guard under test.
	 *
	 * @var InventoryValuationMethodGuard
	 */
	private InventoryValuationMethodGuard $guard;

	/**
	 * In-memory ObjectService stub for uniqueness tests.
	 *
	 * @var \OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService
	 */
	private \OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService $os;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->os = new \OCA\Shillinq\Tests\Unit\Service\InMemoryObjectService();
		$this->logger = $this->createMock(LoggerInterface::class);

		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($this->os);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('shillinq');

		$this->guard = new InventoryValuationMethodGuard(
			appConfig: $appConfig,
			logger: $this->logger,
			objectService: new DuckObjectServiceAdapter($this->os),
		);

	}//end setUp()

	/**
	 * Zero on-hand quantity permits the transition (REQ-INV-006).
	 *
	 * @return void
	 */
	public function testZeroQuantityPermitsTransition(): void {
		$valuation = [
			'productId' => 'GT-10-2026',
			'warehouse' => 'Magazijn Noord',
			'quantity' => 0.0,
			'valuationMethod' => 'FIFO',
		];

		$this->assertTrue($this->guard->checkZeroStock(valuation: $valuation));

	}//end testZeroQuantityPermitsTransition()

	/**
	 * Non-zero on-hand quantity denies the transition (REQ-INV-006).
	 *
	 * @return void
	 */
	public function testNonZeroQuantityDeniesTransition(): void {
		$valuation = [
			'productId' => 'GT-10-2026',
			'warehouse' => 'Magazijn Noord',
			'quantity' => 50.0,
			'valuationMethod' => 'FIFO',
		];

		$this->logger->expects($this->once())->method('info');

		$this->assertFalse($this->guard->checkZeroStock(valuation: $valuation));

	}//end testNonZeroQuantityDeniesTransition()

	/**
	 * Missing quantity field is treated as zero (defensive default).
	 *
	 * @return void
	 */
	public function testMissingQuantityPermitsTransition(): void {
		$valuation = [
			'productId' => 'GT-10-2026',
			'valuationMethod' => 'FIFO',
		];

		$this->assertTrue($this->guard->checkZeroStock(valuation: $valuation));

	}//end testMissingQuantityPermitsTransition()

	/**
	 * Fractional non-zero quantity denies the transition (cost distortion
	 * is still possible even with partial stock).
	 *
	 * @return void
	 */
	public function testFractionalQuantityDeniesTransition(): void {
		$valuation = [
			'quantity' => 0.01,
			'valuationMethod' => 'average',
		];

		$this->logger->expects($this->once())->method('info');

		$this->assertFalse($this->guard->checkZeroStock(valuation: $valuation));

	}//end testFractionalQuantityDeniesTransition()

	/**
	 * REQ-INV-005: empty store permits the creation.
	 *
	 * @return void
	 */
	public function testUniqueActiveSnapshotPermitsFirst(): void {
		$proposed = [
			'productId' => 'GT-10-2026',
			'warehouse' => 'Magazijn Noord',
			'administrationId' => 'adm-1',
			'status' => 'active',
		];

		$this->assertTrue($this->guard->checkUniqueActiveSnapshot(proposed: $proposed));

	}//end testUniqueActiveSnapshotPermitsFirst()

	/**
	 * REQ-INV-005: pre-existing active snapshot blocks the second create.
	 *
	 * @return void
	 */
	public function testUniqueActiveSnapshotBlocksDuplicate(): void {
		$this->os->seed(schema: 'InventoryValuation', rows: [
			[
				'id' => 'iv-existing',
				'productId' => 'GT-10-2026',
				'warehouse' => 'Magazijn Noord',
				'status' => 'active',
				'administrationId' => 'adm-1',
			],
		]);

		$proposed = [
			'productId' => 'GT-10-2026',
			'warehouse' => 'Magazijn Noord',
			'administrationId' => 'adm-1',
			'status' => 'active',
		];

		$this->logger->expects($this->once())->method('info');

		$this->assertFalse($this->guard->checkUniqueActiveSnapshot(proposed: $proposed));

	}//end testUniqueActiveSnapshotBlocksDuplicate()

	/**
	 * REQ-INV-005: self-update with same id is allowed (own-row match).
	 *
	 * @return void
	 */
	public function testUniqueActiveSnapshotAllowsSelfMatch(): void {
		$this->os->seed(schema: 'InventoryValuation', rows: [
			[
				'id' => 'iv-1',
				'productId' => 'GT-10-2026',
				'warehouse' => 'Magazijn Noord',
				'status' => 'active',
				'administrationId' => 'adm-1',
			],
		]);

		$proposed = [
			'id' => 'iv-1',
			'productId' => 'GT-10-2026',
			'warehouse' => 'Magazijn Noord',
			'administrationId' => 'adm-1',
			'status' => 'active',
		];

		$this->assertTrue($this->guard->checkUniqueActiveSnapshot(proposed: $proposed));

	}//end testUniqueActiveSnapshotAllowsSelfMatch()

}//end class
