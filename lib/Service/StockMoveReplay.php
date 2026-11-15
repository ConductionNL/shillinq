<?php

/**
 * Replays stock moves into a valuation snapshot.
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The pure valuation arithmetic of InventoryValuationReportService: replay a
 * move list under FIFO or moving average, and bucket FIFO lots by age.
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
final class StockMoveReplay {

	/**
	 * When a move happened: postedAt, else draftedAt, else ''.
	 *
	 * @param array<string,mixed> $move One stock move.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function postedAtOf(array $move): string {
		return (string)($move['postedAt'] ?? ($move['draftedAt'] ?? ''));
	}//end postedAtOf()

	/**
	 * Replay a move list, sorted by postedAt, into a valuation snapshot.
	 *
	 * @param array<int,array<string,mixed>> $moves  Posted moves.
	 * @param string                         $method 'FIFO' or 'average'.
	 *
	 * @return array{quantity: float, totalValueCents: int, lots: array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function replay(array $moves, string $method): array {
		usort(
			$moves,
			fn (array $a, array $b): int => strcmp($this->postedAtOf(move: $a), $this->postedAtOf(move: $b))
		);

		if ($method === 'average') {
			return $this->replayAverage(moves: $moves);
		}

		return $this->replayFifo(moves: $moves);
	}//end replay()

	/**
	 * FIFO lots bucketed by age at the cut-off (0-30, 31-60, 61-90, 90+), in
	 * euros.
	 *
	 * @param array<int,array<string,mixed>> $lots   Residual FIFO lots.
	 * @param string                         $cutoff The normalised cut-off.
	 *
	 * @return array<string,float>
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function ageBuckets(array $lots, string $cutoff): array {
		$cutoffTs = strtotime($cutoff);
		$buckets  = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
		foreach ($lots as $lot) {
			$lotTs   = strtotime((string)($lot['postedAt'] ?? ''));
			$ageDays = 0;
			if ($cutoffTs !== false && $lotTs !== false) {
				$ageDays = (int)floor((($cutoffTs - $lotTs) / 86400));
			}

			$buckets[$this->bucketFor(ageDays: $ageDays)] += $this->lotCents(lot: $lot);
		}

		return array_map(static fn (int $cents): float => round(($cents / 100), 2), $buckets);
	}//end ageBuckets()

	/**
	 * The age bucket of a lot.
	 *
	 * @param int $ageDays The lot's age in days.
	 *
	 * @return string
	 */
	private function bucketFor(int $ageDays): string {
		if ($ageDays <= 30) {
			return '0-30';
		}

		if ($ageDays <= 60) {
			return '31-60';
		}

		if ($ageDays <= 90) {
			return '61-90';
		}

		return '90+';
	}//end bucketFor()

	/**
	 * FIFO replay: build open lots, consume oldest-first on issues.
	 *
	 * @param array<int,array<string,mixed>> $moves Chronologically sorted moves.
	 *
	 * @return array{quantity: float, totalValueCents: int, lots: array<int,array<string,mixed>>}
	 */
	private function replayFifo(array $moves): array {
		$lots = [];
		foreach ($moves as $move) {
			$type = (string)($move['movementType'] ?? '');
			$qty  = (float)($move['quantity'] ?? 0);
			if ($qty > 0 && $type === 'receipt') {
				$lots[] = ['quantity' => $qty, 'unitCost' => (float)($move['unitCost'] ?? 0), 'postedAt' => $this->postedAtOf(move: $move)];
			}

			if ($qty > 0 && $type === 'issue') {
				$lots = $this->consumeLots(lots: $lots, quantity: $qty);
			}
		}

		$qtyTotal   = 0.0;
		$valueCents = 0;
		foreach ($lots as $lot) {
			$qtyTotal   += (float)$lot['quantity'];
			$valueCents += $this->lotCents(lot: $lot);
		}

		return ['quantity' => $qtyTotal, 'totalValueCents' => $valueCents, 'lots' => $lots];
	}//end replayFifo()

	/**
	 * The open lots after an issue took its quantity oldest-first; emptied
	 * lots are dropped.
	 *
	 * @param array<int,array<string,mixed>> $lots     The open lots.
	 * @param float                          $quantity The issued quantity.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function consumeLots(array $lots, float $quantity): array {
		$remaining = $quantity;
		foreach ($lots as $i => $lot) {
			if ($remaining <= 0) {
				break;
			}

			$take = min((float)$lot['quantity'], $remaining);
			$lots[$i]['quantity'] = ((float)$lot['quantity'] - $take);
			$remaining -= $take;
		}

		return array_values(
			array_filter($lots, static fn (array $lot): bool => ((float)$lot['quantity']) > 1e-9)
		);
	}//end consumeLots()

	/**
	 * Moving-average replay: running weighted average.
	 *
	 * @param array<int,array<string,mixed>> $moves Chronologically sorted moves.
	 *
	 * @return array{quantity: float, totalValueCents: int, lots: array<int,array<string,mixed>>}
	 */
	private function replayAverage(array $moves): array {
		$state = ['quantity' => 0.0, 'averageCost' => 0.0];
		foreach ($moves as $move) {
			$state = $this->averagedOver(state: $state, move: $move);
		}

		return [
			'quantity' => $state['quantity'],
			'totalValueCents' => (int)round(($state['quantity'] * $state['averageCost']) * 100),
			'lots' => [],
		];
	}//end replayAverage()

	/**
	 * The running quantity and average cost after one move: a receipt
	 * re-weights the average, an issue lowers the quantity (never below zero).
	 *
	 * @param array{quantity: float, averageCost: float} $state The running state.
	 * @param array<string,mixed>                       $move  One stock move.
	 *
	 * @return array{quantity: float, averageCost: float}
	 */
	private function averagedOver(array $state, array $move): array {
		$type    = (string)($move['movementType'] ?? '');
		$moveQty = (float)($move['quantity'] ?? 0);
		if ($moveQty <= 0) {
			return $state;
		}

		if ($type === 'receipt') {
			$newQty = ($state['quantity'] + $moveQty);
			$state['averageCost'] = round(((($state['quantity'] * $state['averageCost']) + ($moveQty * (float)($move['unitCost'] ?? 0))) / $newQty), 4);
			$state['quantity']    = $newQty;
			return $state;
		}

		if ($type === 'issue') {
			$state['quantity'] = max(0.0, ($state['quantity'] - $moveQty));
		}

		return $state;
	}//end averagedOver()

	/**
	 * A lot's value in cents.
	 *
	 * @param array<string,mixed> $lot One lot.
	 *
	 * @return int
	 */
	private function lotCents(array $lot): int {
		return (int)round(((float)$lot['quantity']) * ((float)$lot['unitCost']) * 100);
	}//end lotCents()
}//end class
