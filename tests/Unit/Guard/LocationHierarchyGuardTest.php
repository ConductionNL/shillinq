<?php

/**
 * Unit tests for LocationHierarchyGuard.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Guard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-multi-warehouse/tasks.md#task-7
 * @spec openspec/changes/inventory-multi-warehouse/tasks.md#task-18
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use OCA\Shillinq\Guard\LocationHierarchyGuard;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for LocationHierarchyGuard.
 *
 * Covers REQ-LOC-003 (max depth 4), REQ-LOC-004 (in-transit virtual),
 * REQ-LOC-018 (circular reference), REQ-LOC-009 (stock badge).
 */
class LocationHierarchyGuardTest extends TestCase {

	/**
	 * Mock LoggerInterface.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Guard under test.
	 *
	 * @var LocationHierarchyGuard
	 */
	private LocationHierarchyGuard $guard;

	/**
	 * Reusable location fixtures: warehouse → zone → bin hierarchy.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $locations;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->logger = $this->createMock(originalClassName: LoggerInterface::class);
		$this->guard = new LocationHierarchyGuard(logger: $this->logger);

		$this->locations = [
			'w01' => ['id' => 'w01', 'locationCode' => 'W-01', 'locationType' => 'warehouse', 'parentLocationId' => null],
			'z01' => ['id' => 'z01', 'locationCode' => 'Z-01', 'locationType' => 'zone', 'parentLocationId' => 'w01'],
			'z02' => ['id' => 'z02', 'locationCode' => 'Z-02', 'locationType' => 'zone', 'parentLocationId' => 'w01'],
			'b01' => ['id' => 'b01', 'locationCode' => 'B-01', 'locationType' => 'bin', 'parentLocationId' => 'z01'],
			'b02' => ['id' => 'b02', 'locationCode' => 'B-02', 'locationType' => 'bin', 'parentLocationId' => 'z01'],
			'b03' => ['id' => 'b03', 'locationCode' => 'B-03', 'locationType' => 'bin', 'parentLocationId' => 'z02'],
		];

	}//end setUp()

	// -------------------------------------------------------------------------
	// validateDepth.
	// -------------------------------------------------------------------------

	/**
	 * REQ-LOC-003: null parent (top-level warehouse) passes depth validation.
	 *
	 * @return void
	 */
	public function testValidateDepthPassesForTopLevel(): void {
		$this->guard->validateDepth(parentLocationId: null, allLocations: $this->locations);
		$this->addToAssertionCount(count: 1);

	}//end testValidateDepthPassesForTopLevel()

	/**
	 * REQ-LOC-003: depth 1 (zone under warehouse) passes.
	 *
	 * @return void
	 */
	public function testValidateDepthPassesForZone(): void {
		$this->guard->validateDepth(parentLocationId: 'w01', allLocations: $this->locations);
		$this->addToAssertionCount(count: 1);

	}//end testValidateDepthPassesForZone()

	/**
	 * REQ-LOC-003: depth 4 (5th level) throws InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testValidateDepthFailsAtDepthFour(): void {
		$deep = $this->locations;
		$deep['sub01'] = [
			'id' => 'sub01',
			'locationCode' => 'SUB-01',
			'locationType' => 'bin',
			'parentLocationId' => 'b01',
		];

		$this->expectException(exception: \InvalidArgumentException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/maximum depth of 4/');

		$this->guard->validateDepth(parentLocationId: 'sub01', allLocations: $deep);

	}//end testValidateDepthFailsAtDepthFour()

	// -------------------------------------------------------------------------
	// validateNoCircle.
	// -------------------------------------------------------------------------

	/**
	 * REQ-LOC-018: no cycle for a normal parent assignment passes.
	 *
	 * @return void
	 */
	public function testValidateNoCirclePassesForNormalAssignment(): void {
		$this->guard->validateNoCircle(locationId: 'z01', parentLocationId: 'w01', allLocations: $this->locations);
		$this->addToAssertionCount(count: 1);

	}//end testValidateNoCirclePassesForNormalAssignment()

	/**
	 * REQ-LOC-018: null parent always passes circle check.
	 *
	 * @return void
	 */
	public function testValidateNoCirclePassesForNullParent(): void {
		$this->guard->validateNoCircle(locationId: 'w01', parentLocationId: null, allLocations: $this->locations);
		$this->addToAssertionCount(count: 1);

	}//end testValidateNoCirclePassesForNullParent()

	/**
	 * REQ-LOC-018: setting warehouse parent to its own child zone throws.
	 *
	 * @return void
	 */
	public function testValidateNoCircleDetectsCycle(): void {
		$this->expectException(exception: \InvalidArgumentException::class);
		$this->expectExceptionMessageMatches(regularExpression: '/[Cc]ircular reference/');

		$this->guard->validateNoCircle(locationId: 'w01', parentLocationId: 'z01', allLocations: $this->locations);

	}//end testValidateNoCircleDetectsCycle()

	// -------------------------------------------------------------------------
	// countDescendants.
	// -------------------------------------------------------------------------

	/**
	 * REQ-LOC-007: warehouse W-01 has 5 descendants (2 zones + 3 bins).
	 *
	 * @return void
	 */
	public function testCountDescendantsForWarehouse(): void {
		$count = $this->guard->countDescendants(locationId: 'w01', allLocations: $this->locations);
		self::assertSame(expected: 5, actual: $count);

	}//end testCountDescendantsForWarehouse()

	/**
	 * REQ-LOC-007: zone Z-01 has 2 descendants (B-01, B-02).
	 *
	 * @return void
	 */
	public function testCountDescendantsForZone(): void {
		$count = $this->guard->countDescendants(locationId: 'z01', allLocations: $this->locations);
		self::assertSame(expected: 2, actual: $count);

	}//end testCountDescendantsForZone()

	/**
	 * A leaf bin has 0 descendants.
	 *
	 * @return void
	 */
	public function testCountDescendantsForLeafIsZero(): void {
		$count = $this->guard->countDescendants(locationId: 'b01', allLocations: $this->locations);
		self::assertSame(expected: 0, actual: $count);

	}//end testCountDescendantsForLeafIsZero()

	// -------------------------------------------------------------------------
	// buildPath.
	// -------------------------------------------------------------------------

	/**
	 * D5: path for bin B-01 is "W-01 / Z-01 / B-01".
	 *
	 * @return void
	 */
	public function testBuildPathForBin(): void {
		$path = $this->guard->buildPath(location: $this->locations['b01'], allLocations: $this->locations);
		self::assertSame(expected: 'W-01 / Z-01 / B-01', actual: $path);

	}//end testBuildPathForBin()

	/**
	 * D5: path for warehouse W-01 (no parent) is just its code.
	 *
	 * @return void
	 */
	public function testBuildPathForWarehouseIsCodeOnly(): void {
		$path = $this->guard->buildPath(location: $this->locations['w01'], allLocations: $this->locations);
		self::assertSame(expected: 'W-01', actual: $path);

	}//end testBuildPathForWarehouseIsCodeOnly()

	// -------------------------------------------------------------------------
	// computeDepth.
	// -------------------------------------------------------------------------

	/**
	 * REQ-LOC-003: warehouse depth = 0.
	 *
	 * @return void
	 */
	public function testComputeDepthForWarehouseIsZero(): void {
		$depth = $this->guard->computeDepth(location: $this->locations['w01'], allLocations: $this->locations);
		self::assertSame(expected: 0, actual: $depth);

	}//end testComputeDepthForWarehouseIsZero()

	/**
	 * REQ-LOC-003: zone depth = 1.
	 *
	 * @return void
	 */
	public function testComputeDepthForZoneIsOne(): void {
		$depth = $this->guard->computeDepth(location: $this->locations['z01'], allLocations: $this->locations);
		self::assertSame(expected: 1, actual: $depth);

	}//end testComputeDepthForZoneIsOne()

	/**
	 * REQ-LOC-003: bin depth = 2.
	 *
	 * @return void
	 */
	public function testComputeDepthForBinIsTwo(): void {
		$depth = $this->guard->computeDepth(location: $this->locations['b01'], allLocations: $this->locations);
		self::assertSame(expected: 2, actual: $depth);

	}//end testComputeDepthForBinIsTwo()

	// -------------------------------------------------------------------------
	// stockBadge.
	// -------------------------------------------------------------------------

	/**
	 * REQ-LOC-019: empty stock returns 'Empty'.
	 *
	 * @return void
	 */
	public function testStockBadgeEmpty(): void {
		self::assertSame(expected: 'Empty', actual: $this->guard->stockBadge(stockQuantity: 0.0, capacity: 100.0));

	}//end testStockBadgeEmpty()

	/**
	 * REQ-LOC-019: stock under 10% of capacity returns 'Low Stock'.
	 *
	 * @return void
	 */
	public function testStockBadgeLowStock(): void {
		self::assertSame(expected: 'Low Stock', actual: $this->guard->stockBadge(stockQuantity: 5.0, capacity: 100.0));

	}//end testStockBadgeLowStock()

	/**
	 * REQ-LOC-019: stock exceeding capacity returns 'Over Capacity'.
	 *
	 * @return void
	 */
	public function testStockBadgeOverCapacity(): void {
		self::assertSame(
			expected: 'Over Capacity',
			actual: $this->guard->stockBadge(stockQuantity: 150.0, capacity: 100.0)
		);

	}//end testStockBadgeOverCapacity()

	/**
	 * REQ-LOC-019: stock within range (>= 10% of capacity, <= capacity) returns 'In Stock'.
	 *
	 * @return void
	 */
	public function testStockBadgeInStock(): void {
		self::assertSame(expected: 'In Stock', actual: $this->guard->stockBadge(stockQuantity: 50.0, capacity: 100.0));

	}//end testStockBadgeInStock()

	/**
	 * REQ-LOC-019: null capacity with positive stock returns 'In Stock'.
	 *
	 * @return void
	 */
	public function testStockBadgeInStockNullCapacity(): void {
		self::assertSame(expected: 'In Stock', actual: $this->guard->stockBadge(stockQuantity: 25.0, capacity: null));

	}//end testStockBadgeInStockNullCapacity()
}//end class
