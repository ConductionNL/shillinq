<?php

/**
 * Stock Ledger Replay
 *
 * Replays the posted receipts and issues of one product at one location, in
 * posting order, at FIFO or moving-average cost: the stock left, its value,
 * and what each issue costs from the layers the receipts before it added.
 * No reads, no writes; the caller hands in the moves.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Inventory
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Inventory;

/**
 * Replays one product's stock at one location.
 *
 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
 */
final class StockLedgerReplay {

	/**
	 * The open FIFO lots, oldest first: [quantity, unit cost].
	 *
	 * @var array<int, array{0: float, 1: float}>
	 */
	private array $lots = [];

	/**
	 * The quantity on hand.
	 *
	 * @var float
	 */
	private float $quantity = 0.0;

	/**
	 * The moving-average unit cost.
	 *
	 * @var float
	 */
	private float $averageCost = 0.0;

	/**
	 * The cost of each issue in cents, by the move's uuid.
	 *
	 * @var array<string, int>
	 */
	private array $issueCosts = [];

	/**
	 * Constructor.
	 *
	 * @param string $method `average`, or anything else for FIFO, as the valuation listener reads it.
	 */
	public function __construct(private readonly string $method) {
	}//end __construct()

	/**
	 * Replay the moves.
	 *
	 * @param array<int, array<string, mixed>> $moves The posted receipts and issues.
	 *
	 * @return array{quantity: float, unitCost: float, totalValue: float, issueCosts: array<string, int>, lastMove: array<string, mixed>|null}
	 *
	 * @spec openspec/changes/inventory-receipt-cost-layers/specs/inventory-valuation-fifo-avg/spec.md#requirement-past-receipts-get-their-layers-once-req-ircl-003
	 */
	public function replay(array $moves): array {
		$ordered = self::inPostingOrder(moves: $moves);
		foreach ($ordered as $move) {
			if ((string)($move['movementType'] ?? '') === 'receipt') {
				$this->receive(quantity: (float)($move['quantity'] ?? 0), unitCost: (float)($move['unitCost'] ?? 0));
				continue;
			}

			$this->issueCosts[self::uuidOf(move: $move)] = $this->issue(quantity: (float)($move['quantity'] ?? 0));
		}

		$valueCents = $this->valueCents();
		$unitCost   = 0.0;
		if ($this->quantity > 0) {
			$unitCost = round(($valueCents / 100) / $this->quantity, 4);
		}

		return [
			'quantity'   => round($this->quantity, 2),
			'unitCost'   => $unitCost,
			'totalValue' => round($valueCents / 100, 2),
			'issueCosts' => $this->issueCosts,
			'lastMove'   => $this->lastOf(moves: $ordered),
		];
	}//end replay()

	/**
	 * The uuid a move is known by, as StockMoveValuationKey reads it.
	 *
	 * @param array<string, mixed> $move The move.
	 *
	 * @return string
	 */
	private static function uuidOf(array $move): string {
		return (string)($move['uuid'] ?? ($move['@self']['uuid'] ?? ($move['id'] ?? '')));
	}//end uuidOf()

	/**
	 * The last move replayed, or null when there was none.
	 *
	 * @param array<int, array<string, mixed>> $moves The moves in posting order.
	 *
	 * @return array<string, mixed>|null
	 */
	private function lastOf(array $moves): ?array {
		if ($moves === []) {
			return null;
		}

		return $moves[array_key_last($moves)];
	}//end lastOf()

	/**
	 * The moves by `postedAt`; on the same moment a receipt before an issue.
	 *
	 * @param array<int, array<string, mixed>> $moves The moves.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function inPostingOrder(array $moves): array {
		$moves = array_values($moves);
		usort(
			$moves,
			static function (array $a, array $b): int {
				$byTime = strcmp((string)($a['postedAt'] ?? ''), (string)($b['postedAt'] ?? ''));
				if ($byTime !== 0) {
					return $byTime;
				}

				return (int)(($a['movementType'] ?? '') !== 'receipt') <=> (int)(($b['movementType'] ?? '') !== 'receipt');
			}
		);

		return $moves;
	}//end inPostingOrder()

	/**
	 * Add a receipt: a new lot, or a new moving average.
	 *
	 * @param float $quantity The quantity received.
	 * @param float $unitCost The unit cost.
	 *
	 * @return void
	 */
	private function receive(float $quantity, float $unitCost): void {
		if ($quantity <= 0) {
			return;
		}

		if ($this->method === 'average') {
			$this->averageCost = round((($this->quantity * $this->averageCost) + ($quantity * $unitCost)) / ($this->quantity + $quantity), 4);
		}

		if ($this->method !== 'average') {
			$this->lots[] = [$quantity, $unitCost];
		}

		$this->quantity += $quantity;
	}//end receive()

	/**
	 * Take an issue out of stock.
	 *
	 * @param float $quantity The quantity issued.
	 *
	 * @return int What it costs from the layers, in cents.
	 */
	private function issue(float $quantity): int {
		if ($quantity <= 0) {
			return 0;
		}

		$this->quantity = max(0.0, ($this->quantity - $quantity));
		if ($this->method === 'average') {
			return (int)round($quantity * $this->averageCost * 100);
		}

		$cents = 0;
		$left  = $quantity;
		while ($left > 0 && $this->lots !== []) {
			[$lotQuantity, $lotCost] = $this->lots[0];
			$taken  = min($left, $lotQuantity);
			$cents += (int)round($taken * $lotCost * 100);
			$left  -= $taken;
			if ($taken < $lotQuantity) {
				$this->lots[0] = [($lotQuantity - $taken), $lotCost];
				continue;
			}

			array_shift($this->lots);
		}

		return $cents;
	}//end issue()

	/**
	 * The value of the stock on hand, in cents.
	 *
	 * @return int
	 */
	private function valueCents(): int {
		if ($this->method === 'average') {
			return (int)round($this->quantity * $this->averageCost * 100);
		}

		$cents = 0;
		foreach ($this->lots as [$lotQuantity, $lotCost]) {
			$cents += (int)round($lotQuantity * $lotCost * 100);
		}

		return $cents;
	}//end valueCents()
}//end class
