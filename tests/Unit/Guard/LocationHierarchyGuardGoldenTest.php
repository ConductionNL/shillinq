<?php

/**
 * Golden test for LocationHierarchyGuard cycle check, depth and stock badge.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Guard;

use InvalidArgumentException;
use OCA\Shillinq\Guard\LocationHierarchyGuard;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins which parent links are refused as circular, the depth of every shape, and every stock badge.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
class LocationHierarchyGuardGoldenTest extends TestCase {

	/**
	 * A small tree with a dangling link and an existing loop.
	 *
	 * @return array<string|int,array<string,mixed>>
	 */
	private static function locations(): array {
		return [
			'root' => ['name' => 'Root'],
			'a' => ['parentLocationId' => 'root'],
			'b' => ['parentLocationId' => 'a'],
			'c' => ['parentLocationId' => 'b'],
			'orphan' => ['parentLocationId' => 'gone'],
			'x' => ['parentLocationId' => 'y'],
			'y' => ['parentLocationId' => 'x'],
			7 => ['parentLocationId' => 'root'],
			'n' => ['parentLocationId' => null],
		];
	}//end locations()

	/**
	 * Whether linking the location under the parent is refused as circular.
	 *
	 * @param string      $locationId The location.
	 * @param string|null $parentId   Its new parent.
	 *
	 * @return bool
	 */
	private static function refused(string $locationId, string|null $parentId): bool {
		try {
			(new LocationHierarchyGuard(new \Psr\Log\NullLogger()))->validateNoCircle($locationId, $parentId, self::locations());
		} catch (InvalidArgumentException $e) {
			self::assertSame(expected: 'Circular reference detected in location hierarchy.', actual: $e->getMessage());
			return true;
		}

		return false;
	}//end refused()

	/**
	 * A link is circular when walking up from the parent reaches the location or loops.
	 *
	 * @return void
	 */
	public function testCircles(): void {
		$cases = [
			['a', null, false],
			['new', 'c', false],
			['a', 'a', true],
			['root', 'c', true],
			['b', 'c', true],
			['new', 'orphan', false],
			['new', 'unknown', false],
			['new', 'x', true],
			['new', '7', false],
			['root', '7', true],
			['new', 'n', false],
		];
		foreach ($cases as [$location, $parent, $expected]) {
			self::assertSame(expected: $expected, actual: self::refused(locationId: $location, parentId: $parent), message: $location . ' under ' . $parent);
		}
	}//end testCircles()

	/**
	 * Depth counts the parent links, a dangling one included.
	 *
	 * @return void
	 */
	public function testDepth(): void {
		$guard = new LocationHierarchyGuard($this->createMock(LoggerInterface::class));
		$all   = self::locations();
		$cases = [[[], 0], [['parentLocationId' => 'c'], 4], [$all['orphan'], 1], [['parentLocationId' => 'unknown'], 1], [$all['n'], 0], [$all[7], 1]];
		foreach ($cases as [$location, $depth]) {
			self::assertSame(expected: $depth, actual: $guard->computeDepth(location: $location, allLocations: $all));
		}
	}//end testDepth()

	/**
	 * The badge: empty at or below zero; over or low only against a positive capacity.
	 *
	 * @return void
	 */
	public function testStockBadge(): void {
		$guard = new LocationHierarchyGuard($this->createMock(LoggerInterface::class));
		$cases = [
			[0.0, 10.0, 'Empty'],
			[-1.0, null, 'Empty'],
			[11.0, 10.0, 'Over Capacity'],
			[10.0, 10.0, 'In Stock'],
			[0.99, 10.0, 'Low Stock'],
			[1.0, 10.0, 'In Stock'],
			[5.0, null, 'In Stock'],
			[5.0, 0.0, 'In Stock'],
			[5.0, -3.0, 'In Stock'],
		];
		foreach ($cases as [$stock, $capacity, $badge]) {
			self::assertSame(expected: $badge, actual: $guard->stockBadge(stockQuantity: $stock, capacity: $capacity));
		}
	}//end testStockBadge()
}//end class
