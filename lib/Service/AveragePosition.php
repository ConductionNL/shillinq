<?php

/**
 * Average position
 *
 * The quantity and unit cost a moving-average InventoryValuation moves to
 * after a receipt or an issue, applied with the total value rounded to cents.
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
 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-004-the-system-shall-compute-moving-average-valuation-on-each-inbound-receipt
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A moving-average stock position (quantity at a unit cost).
 *
 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-004-the-system-shall-compute-moving-average-valuation-on-each-inbound-receipt
 */
final class AveragePosition {
	/**
	 * The position.
	 *
	 * @param float $quantity The quantity held.
	 * @param float $unitCost The average unit cost.
	 */
	public function __construct(
		public readonly float $quantity,
		public readonly float $unitCost,
	) {
	}//end __construct()

	/**
	 * A valuation set to this position: quantity rounded to 2 dp, the unit
	 * cost, and totalValue = quantity * unitCost rounded to 2 dp via integer
	 * cents.
	 *
	 * @param array<string,mixed> $valuation The snapshot.
	 *
	 * @return array<string,mixed> The updated snapshot.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-004-the-system-shall-compute-moving-average-valuation-on-each-inbound-receipt
	 */
	public function applyTo(array $valuation): array {
		$totalCents = (int)round(($this->quantity * $this->unitCost) * 100);
		$valuation['quantity'] = round($this->quantity, 2);
		$valuation['unitCost'] = $this->unitCost;
		$valuation['totalValue'] = round(($totalCents / 100), 2);
		return $valuation;
	}//end applyTo()
}//end class
