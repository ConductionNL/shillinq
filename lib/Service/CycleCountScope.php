<?php

/**
 * Cycle count scope
 *
 * The fields of an InventoryCycleCount that decide which stock its snapshot
 * covers: the count itself, its administration, full or partial, and the
 * optional location and category filters of a partial count (REQ-ICC-008).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Which InventoryStock rows one cycle count snapshots.
 *
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
 */
final class CycleCountScope {
	/**
	 * The count identifier ('' when missing).
	 *
	 * @var string
	 */
	public readonly string $countId;

	/**
	 * The administration scope ('' when missing).
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * 'full' or 'partial' ('' when missing).
	 *
	 * @var string
	 */
	public readonly string $countType;

	/**
	 * Location id a partial count is limited to ('' for none).
	 *
	 * @var string
	 */
	public readonly string $locationFilter;

	/**
	 * Product category a partial count is limited to ('' for none).
	 *
	 * @var string
	 */
	public readonly string $categoryFilter;

	/**
	 * Read the scope from an InventoryCycleCount payload; missing fields become ''.
	 *
	 * @param array<string,mixed> $count The InventoryCycleCount payload.
	 */
	public function __construct(array $count) {
		$this->countId = (string)($count['countId'] ?? '');
		$this->administrationId = (string)($count['administrationId'] ?? '');
		$this->countType = (string)($count['countType'] ?? '');
		$this->locationFilter = (string)($count['locationFilter'] ?? '');
		$this->categoryFilter = (string)($count['categoryFilter'] ?? '');
	}//end __construct()

	/**
	 * Whether the count id, administration and count type are all present.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function isComplete(): bool {
		return $this->countId !== '' && $this->administrationId !== '' && $this->countType !== '';
	}//end isComplete()

	/**
	 * The InventoryStock query filters: the administration, plus the location
	 * for a partial count that names one.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function stockFilters(): array {
		$filters = ['administrationId' => $this->administrationId];
		if ($this->countType === 'partial' && $this->locationFilter !== '') {
			$filters['locationId'] = $this->locationFilter;
		}

		return $filters;
	}//end stockFilters()

	/**
	 * Whether the stock rows still need the client-side category filter: a
	 * partial count by category that names no location.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function filtersByCategoryOnly(): bool {
		return $this->countType === 'partial' && $this->categoryFilter !== '' && $this->locationFilter === '';
	}//end filtersByCategoryOnly()
}//end class
