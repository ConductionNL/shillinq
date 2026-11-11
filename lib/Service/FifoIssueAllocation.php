<?php

/**
 * FIFO issue allocation
 *
 * Walks the available inbound lots of an item oldest-first to cover one
 * outbound quantity: what each lot contributes to the issue's cost of goods
 * sold (the COGS basis and its integer-cent total) and the lots, or parts of
 * lots, that remain.
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
 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-003-the-system-shall-compute-fifo-valuation-on-each-stock-movement
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The oldest-first allocation of one outbound quantity over FIFO lots.
 *
 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-003-the-system-shall-compute-fifo-valuation-on-each-stock-movement
 */
final class FifoIssueAllocation {
	/**
	 * The quantity the available lots hold together.
	 *
	 * @var float
	 */
	public float $totalAvailable = 0.0;

	/**
	 * COGS in integer cents.
	 *
	 * @var int
	 */
	public int $cogsCents = 0;

	/**
	 * Per consumed lot: its unit cost and the quantity taken.
	 *
	 * @var array<int,array{lotCost:float,quantity:float}>
	 */
	public array $cogsBasis = [];

	/**
	 * The lots (or remainders) left after the issue.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $residualLots = [];

	/**
	 * The quantity still to take.
	 *
	 * @var float
	 */
	private float $remaining = 0.0;

	/**
	 * Start allocating an outbound quantity.
	 *
	 * @param float $outboundQty The issued quantity.
	 */
	public function __construct(
		public readonly float $outboundQty,
	) {
	}//end __construct()

	/**
	 * Walk the lots oldest-first, deduct, build the COGS basis.
	 *
	 * @param array<int,array<string,mixed>> $availableLots The available lots, oldest first.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-003-the-system-shall-compute-fifo-valuation-on-each-stock-movement
	 */
	public function allocate(array $availableLots): void {
		foreach ($availableLots as $lot) {
			$this->totalAvailable += (float)$lot['quantity'];
		}

		$this->remaining = $this->outboundQty;
		foreach ($availableLots as $lot) {
			$this->takeFrom(lot: $lot);
		}
	}//end allocate()

	/**
	 * True when the available lots hold less than the outbound quantity.
	 *
	 * @return bool Whether the allocation is partial.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-003-the-system-shall-compute-fifo-valuation-on-each-stock-movement
	 */
	public function isShort(): bool {
		return $this->totalAvailable + 1e-9 < $this->outboundQty;
	}//end isShort()

	/**
	 * Take what is still needed from one lot; keep the rest of it.
	 *
	 * @param array<string,mixed> $lot The lot (quantity, unitCost, postedAt).
	 *
	 * @return void
	 */
	private function takeFrom(array $lot): void {
		$lotQty = (float)$lot['quantity'];
		$lotCost = (float)$lot['unitCost'];
		if ($this->remaining <= 0) {
			$this->residualLots[] = $lot;
			return;
		}

		$take = min($lotQty, $this->remaining);
		$this->cogsCents += (int)round($take * $lotCost * 100);
		$this->cogsBasis[] = [
			'lotCost' => $lotCost,
			'quantity' => $take,
		];
		$this->remaining -= $take;

		$left = ($lotQty - $take);
		if ($left > 0) {
			$this->residualLots[] = [
				'quantity' => $left,
				'unitCost' => $lotCost,
				'postedAt' => $lot['postedAt'],
			];
		}
	}//end takeFrom()
}//end class
