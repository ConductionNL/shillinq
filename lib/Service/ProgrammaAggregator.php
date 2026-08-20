<?php

/**
 * Programma aggregator.
 *
 * ADR-031 exception-path calculator for the Programma roll-up (REQ-002,
 * design D1). The Taakveld is the canonical brondata; the Programma's
 * revenueTotal / expensesTotal / balanceBeforeMovements / balanceAfterMovements are sums of
 * its child Taakvelden. Computed in integer euro-cents so the programme-view
 * exactly equals the task_field-view (no rounding drift between the political and
 * the BBV-mandated technical indeling). Documented as the `programmaRollup`
 * aggregation on the Programma schema. No persistence, no I/O.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-24
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure calculator that rolls up a Programma's totals from its Taakvelden.
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-24
 */
class ProgrammaAggregator {
	/**
	 * Aggregate the child Taakvelden into a Programma's totals.
	 *
	 * Computes revenueTotal = Σ(Taakveld.revenue); expensesTotal = Σ(Taakveld.expenses);
	 * balanceBeforeMovements = revenueTotal - expensesTotal; balanceAfterMovements =
	 * balanceBeforeMovements + movementsReserves. All sums are accumulated in integer
	 * cents to guarantee the programme-view equals the task_field-view exactly.
	 *
	 * @param array<int,array<string,mixed>> $taskFields The child Taakveld rows.
	 * @param float $movementsReserves The reserve mutation (positive = toevoeging).
	 *
	 * @return array{revenueTotal:float,expensesTotal:float,balanceBeforeMovements:float,balanceAfterMovements:float}
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-24
	 */
	public function aggregate(array $taskFields, float $movementsReserves = 0.0): array {
		$revenueCents = 0;
		$expensesCents = 0;
		foreach ($taskFields as $taskField) {
			$revenueCents += (int)round(((float)($taskField['revenue'] ?? 0)) * 100);
			$expensesCents += (int)round(((float)($taskField['expenses'] ?? 0)) * 100);
		}

		$balanceForCents = ($revenueCents - $expensesCents);
		$movementsCents = (int)round($movementsReserves * 100);
		$balanceAfterCents = ($balanceForCents + $movementsCents);

		return [
			'revenueTotal' => (float)($revenueCents / 100),
			'expensesTotal' => (float)($expensesCents / 100),
			'balanceBeforeMovements' => (float)($balanceForCents / 100),
			'balanceAfterMovements' => (float)($balanceAfterCents / 100),
		];

	}//end aggregate()
}//end class
