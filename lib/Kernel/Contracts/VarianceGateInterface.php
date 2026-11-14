<?php

/**
 * Contract of VarianceGate
 *
 * The public surface of VarianceGate that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of VarianceGate.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VarianceGateInterface {

	/**
	 * Pure helper: recompute the derived fields on a single line per
	 * REQ-ICC-003 + REQ-ICC-004. Returned array overlays the input — caller
	 * decides whether to persist.
	 *
	 * @param array<string,mixed> $line The InventoryCycleCountLine input.
	 * @param float|null $qtyThresholdPct Quantity threshold % (null = default 5%).
	 * @param float|null $valueThresholdAbs Absolute value threshold (null = default EUR 500).
	 *
	 * @return array<string,mixed> The line with countedValue, quantityVariance, valueVariance, requiresReason refreshed.
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-9
	 */
	public function recalculateLine(
		array $line,
		?float $qtyThresholdPct = null,
		?float $valueThresholdAbs = null,
	): array;
}//end interface
