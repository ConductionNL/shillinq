<?php

/**
 * Cycle count adjustment run
 *
 * The state of one emitAdjustments() pass over the lines of an inventory cycle
 * count: the count it belongs to, the running StockMove sequence number and
 * the variance of the line being posted.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Progress of posting the variance adjustments of one cycle count.
 *
 * @spec openspec/specs/inventory-cycle-count/spec.md#req-icc-007-variance-posting-shall-generate-inventoryadjustment-records-and-update-inventorystock
 */
final class CycleCountAdjustmentRun {
	/**
	 * Sequence number of the last StockMove numbered in this run.
	 *
	 * @var int
	 */
	public int $sequence = 0;

	/**
	 * Variance in cents of the line being posted (positive: found stock).
	 *
	 * @var int
	 */
	public int $varianceCents = 0;

	/**
	 * Start a run for one count.
	 *
	 * @param string $countId          The count identifier.
	 * @param string $administrationId The administration scope.
	 */
	public function __construct(
		public readonly string $countId,
		public readonly string $administrationId,
	) {
	}//end __construct()
}//end class
