<?php

/**
 * Location hierarchy validation and computation — ADR-031 exception-path guard.
 *
 * Invoked by x-openregister-lifecycle (validateDepth, noCircularReference),
 * x-openregister-aggregations (descendantCount), and x-openregister-calculations
 * (hierarchyPath, hierarchyDepthValue, stockAvailabilityBadge) engine when the
 * declarative engine cannot express the recursive parent-child FK traversal natively.
 * Thin PHP seams per ADR-031: single public method per entry-point, no persistence.
 *
 * Exception documented in
 * openspec/changes/inventory-multi-warehouse/design.md §"Declarative-vs-imperative
 * decision": recursive hierarchy traversal (depth, path, cycle detection) requires
 * PHP iteration; the OR declarative engine supports only flat parent-child lookups.
 *
 * @category Guard
 * @package  OCA\Shillinq\Guard
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

namespace OCA\Shillinq\Guard;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * ADR-031 exception guard for location hierarchy operations.
 *
 * Provides depth validation (≤ 4 per REQ-LOC-003), circular-reference prevention
 * (REQ-LOC-018), descendant counting for rollup queries (REQ-LOC-005), path building
 * for display (D5), and stock availability badge computation (REQ-LOC-009).
 *
 * All methods receive pre-fetched location arrays from the OR engine; no direct DB
 * access occurs here.
 *
 * @spec openspec/changes/inventory-multi-warehouse/tasks.md#task-7
 * @spec openspec/changes/inventory-multi-warehouse/tasks.md#task-18
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class LocationHierarchyGuard {
	/**
	 * Maximum allowed hierarchy depth per REQ-LOC-003.
	 */
	public const MAX_DEPTH = 4;

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Nextcloud logger for hierarchy diagnostics.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Validate that the new location does not exceed MAX_DEPTH in the hierarchy.
	 *
	 * Called by x-openregister-lifecycle `validations.onCreate.maxDepth` guard clause.
	 * Throws InvalidArgumentException when depth ≥ MAX_DEPTH.
	 *
	 * @param string|null $parentLocationId The proposed parent location id (null = top-level).
	 * @param array<string,mixed>[] $allLocations All locations in the administration keyed by id.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When hierarchy depth would exceed MAX_DEPTH.
	 *
	 * @spec openspec/changes/inventory-multi-warehouse/tasks.md#task-7
	 */
	public function validateDepth(?string $parentLocationId, array $allLocations): void {
		if ($parentLocationId === null) {
			return;
		}

		$depth = $this->computeDepthFromParent(parentId: $parentLocationId, allLocations: $allLocations);

		$this->logger->debug(
			'LocationHierarchyGuard: validateDepth',
			['parentLocationId' => $parentLocationId, 'computedDepth' => $depth]
		);

		if ($depth >= self::MAX_DEPTH) {
			throw new InvalidArgumentException(
				'Location hierarchy exceeds maximum depth of ' . self::MAX_DEPTH . '.'
			);
		}

	}//end validateDepth()

	/**
	 * Validate that setting parentLocationId does not create a circular reference.
	 *
	 * Called by x-openregister-lifecycle `validations.onCreate.noCircularReference` guard.
	 * Throws InvalidArgumentException when a cycle is detected.
	 *
	 * @param string $locationId The location being saved (new or updated).
	 * @param string|null $parentLocationId The proposed parent location id.
	 * @param array<string,mixed>[] $allLocations All locations in the administration keyed by id.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When circular reference would be created.
	 *
	 * @spec openspec/changes/inventory-multi-warehouse/tasks.md#task-18
	 */
	public function validateNoCircle(string $locationId, ?string $parentLocationId, array $allLocations): void {
		if ($parentLocationId === null) {
			return;
		}

		$visited = [$locationId => true];
		$current = $parentLocationId;

		while ($current !== null) {
			if (isset($visited[$current]) === true) {
				throw new InvalidArgumentException(
					'Circular reference detected in location hierarchy.'
				);
			}

			$visited[$current] = true;
			$parent = $allLocations[$current] ?? null;
			if ($parent === null) {
				$current = null;
			} else {
				$current = $parent['parentLocationId'] ?? null;
			}
		}

	}//end validateNoCircle()

	/**
	 * Count all descendants of a location (recursive, all depths).
	 *
	 * Called by x-openregister-aggregations `descendantCount` guard clause.
	 *
	 * @param string $locationId The root location whose descendants are counted.
	 * @param array<string,mixed>[] $allLocations All locations in the administration keyed by id.
	 *
	 * @return int Total number of descendant locations at all depth levels.
	 *
	 * @spec openspec/changes/inventory-multi-warehouse/tasks.md#task-7
	 */
	public function countDescendants(string $locationId, array $allLocations): int {
		$count = 0;
		$children = $this->directChildIds(parentId: $locationId, allLocations: $allLocations);

		foreach ($children as $childId) {
			$count++;
			$count += $this->countDescendants(locationId: $childId, allLocations: $allLocations);
		}

		return $count;
	}//end countDescendants()

	/**
	 * Build the full human-readable path from warehouse root to this location.
	 *
	 * Example: "W-01 / Z-01 / B-100" per design.md D5.
	 * Called by x-openregister-calculations `hierarchyPath` guard clause.
	 *
	 * @param array<string,mixed> $location The location to build the path for.
	 * @param array<string,mixed>[] $allLocations All locations in the administration keyed by id.
	 *
	 * @return string Full slash-separated path from warehouse root (e.g. "W-01 / Z-01 / B-100").
	 *
	 * @spec openspec/changes/inventory-multi-warehouse/tasks.md#task-7
	 */
	public function buildPath(array $location, array $allLocations): string {
		$segments = [$location['locationCode'] ?? $location['name'] ?? ''];
		$current = $location['parentLocationId'] ?? null;

		while ($current !== null) {
			$parent = $allLocations[$current] ?? null;
			if ($parent === null) {
				break;
			}

			array_unshift($segments, $parent['locationCode'] ?? $parent['name'] ?? '');
			$current = $parent['parentLocationId'] ?? null;
		}

		return implode(separator: ' / ', array: $segments);
	}//end buildPath()

	/**
	 * Compute the numeric depth of a location in the hierarchy.
	 *
	 * 0 = warehouse (no parent), 1 = zone, 2 = aisle/section, 3 = bin.
	 * Called by x-openregister-calculations `hierarchyDepthValue` guard clause.
	 *
	 * @param array<string,mixed> $location The location to compute depth for.
	 * @param array<string,mixed>[] $allLocations All locations in the administration keyed by id.
	 *
	 * @return int Depth in hierarchy (0 = warehouse, max 3 = bin per REQ-LOC-003).
	 *
	 * @spec openspec/changes/inventory-multi-warehouse/tasks.md#task-7
	 */
	public function computeDepth(array $location, array $allLocations): int {
		$depth = 0;
		$current = $location['parentLocationId'] ?? null;

		while ($current !== null) {
			$depth++;
			$parent = $allLocations[$current] ?? null;
			if ($parent === null) {
				$current = null;
			} else {
				$current = $parent['parentLocationId'] ?? null;
			}
		}

		return $depth;
	}//end computeDepth()

	/**
	 * Return a stock availability badge label for display per REQ-LOC-009.
	 *
	 * Badge values: 'In Stock', 'Low Stock', 'Empty', 'Over Capacity'.
	 * Called by x-openregister-calculations `stockAvailabilityBadge` guard clause.
	 *
	 * @param float $stockQuantity Aggregated on-hand quantity from stockRollup.
	 * @param float|null $capacity Optional capacity limit (null = no limit check).
	 *
	 * @return string One of: 'In Stock', 'Low Stock', 'Empty', 'Over Capacity'.
	 *
	 * @spec openspec/changes/inventory-multi-warehouse/tasks.md#task-19
	 */
	public function stockBadge(float $stockQuantity, ?float $capacity): string {
		if ($stockQuantity <= 0.0) {
			return 'Empty';
		}

		if ($capacity !== null && $capacity > 0.0 && $stockQuantity > $capacity) {
			return 'Over Capacity';
		}

		if ($capacity !== null && $capacity > 0.0 && $stockQuantity < ($capacity * 0.1)) {
			return 'Low Stock';
		}

		return 'In Stock';
	}//end stockBadge()

	/**
	 * Compute depth from a parent id (used in validateDepth).
	 *
	 * @param string $parentId The parent location id.
	 * @param array<string,mixed>[] $allLocations All locations keyed by id.
	 *
	 * @return int Depth of the parent + 1 (= depth the new child would have).
	 */
	private function computeDepthFromParent(string $parentId, array $allLocations): int {
		$parent = $allLocations[$parentId] ?? null;
		if ($parent === null) {
			return 1;
		}

		return 1 + $this->computeDepth(location: $parent, allLocations: $allLocations);
	}//end computeDepthFromParent()

	/**
	 * Return direct child location ids for a given parent.
	 *
	 * @param string $parentId The parent location id.
	 * @param array<string,mixed>[] $allLocations All locations keyed by id.
	 *
	 * @return string[] Array of direct child location ids.
	 */
	private function directChildIds(string $parentId, array $allLocations): array {
		$ids = [];
		foreach ($allLocations as $id => $loc) {
			if (($loc['parentLocationId'] ?? null) === $parentId) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end directChildIds()
}//end class
