<?php

/**
 * What a cycle count's post is checked against.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * The count id, the administration's variance thresholds and its active
 * reason codes, shared by the per-line steps of one post check. Built and
 * used only by VarianceGate.
 *
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
 */
final class VariancePostCheck {
	/**
	 * Constructor.
	 *
	 * @param string            $countId           The count identifier.
	 * @param float             $qtyThresholdPct   Quantity threshold %.
	 * @param float             $valueThresholdAbs Absolute value threshold.
	 * @param array<int,string> $activeReasons     The administration's active reason codes.
	 */
	public function __construct(
		public readonly string $countId,
		public readonly float $qtyThresholdPct,
		public readonly float $valueThresholdAbs,
		public readonly array $activeReasons,
	) {

	}//end __construct()
}//end class
