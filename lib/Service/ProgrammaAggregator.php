<?php

/**
 * Programma aggregator.
 *
 * ADR-031 exception-path calculator for the Programma roll-up (REQ-002,
 * design D1). The Taakveld is the canonical brondata; the Programma's
 * batenTotaal / lastenTotaal / saldoVoorMutaties / saldoNaMutaties are sums of
 * its child Taakvelden. Computed in integer euro-cents so the programma-view
 * exactly equals the taakveld-view (no rounding drift between the political and
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
	 * Computes batenTotaal = Σ(Taakveld.baten); lastenTotaal = Σ(Taakveld.lasten);
	 * saldoVoorMutaties = batenTotaal - lastenTotaal; saldoNaMutaties =
	 * saldoVoorMutaties + mutatiesReserves. All sums are accumulated in integer
	 * cents to guarantee the programma-view equals the taakveld-view exactly.
	 *
	 * @param array<int,array<string,mixed>> $taakvelden The child Taakveld rows.
	 * @param float $mutatiesReserves The reserve mutation (positive = toevoeging).
	 *
	 * @return array{revenueTotal:float,expensesTotal:float,balanceBeforeMovements:float,balanceAfterMovements:float}
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-24
	 */
	public function aggregate(array $taakvelden, float $mutatiesReserves = 0.0): array {
		$batenCents = 0;
		$lastenCents = 0;
		foreach ($taakvelden as $taakveld) {
			$batenCents += (int)round(((float)($taakveld['revenue'] ?? 0)) * 100);
			$lastenCents += (int)round(((float)($taakveld['expenses'] ?? 0)) * 100);
		}

		$saldoVoorCents = ($batenCents - $lastenCents);
		$mutatiesCents = (int)round($mutatiesReserves * 100);
		$saldoNaCents = ($saldoVoorCents + $mutatiesCents);

		return [
			'revenueTotal' => (float)($batenCents / 100),
			'expensesTotal' => (float)($lastenCents / 100),
			'balanceBeforeMovements' => (float)($saldoVoorCents / 100),
			'balanceAfterMovements' => (float)($saldoNaCents / 100),
		];

	}//end aggregate()
}//end class
