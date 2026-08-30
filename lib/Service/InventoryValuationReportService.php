<?php

/**
 * Inventory Valuation Report Service
 *
 * Read-only reporting views over the existing immutable `StockMove` ledger
 * for the jaarrekening (Titel 9 BW2) and management reporting. Answers the
 * three questions the stock ledger could not surface before:
 *
 *   - **valuationAsOf()** — `voorraadwaarde per <as-of-date>`: the stock
 *     value at any historical cut-off (e.g. 31-12). Because FIFO cost layers
 *     are NOT persisted as objects but reconstructed from the ledger (each
 *     posted `receipt` StockMove IS a lot; `issue` rows consume them), the
 *     value at a date is obtained by REPLAYING every posted, non-cancelled
 *     move with `postedAt <= asOfDate`:
 *       - FIFO: rebuild open lots, consume oldest-first on each issue, value
 *         the residual lots (`Σ lotQty × lotCost`);
 *       - average: replay the running weighted average, value `qty × avgCost`.
 *     No new persistence is required — the ledger already keeps the running
 *     total (verified against HEAD: FifoValuationService derives lots on the
 *     fly, it never writes layer objects).
 *
 *   - **ageing()** — for FIFO items, buckets the residual open lots by age
 *     (`asOfDate − lot.postedAt`) into 0-30 / 31-60 / 61-90 / 90+ days so slow
 *     movers and obsolescence risk are visible (an NRV write-down input).
 *
 *   - **turnover()** — inventory turnover ratio `COGS(window) / average
 *     inventory value` plus days-on-hand, over a `[from, to]` window.
 *
 * All reads via OpenRegister's ObjectService (ADR-022 — no app tables, no
 * SQL). Money discipline is integer cents. This is a pure read/aggregation
 * service: it never posts and never mutates a snapshot.
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

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Ledger-replay valuation reporting (as-of-date / ageing / turnover).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ShortVariable)            Pre-existing debt (issue #506):
 *     not in the project's curated idiomatic-abbreviation allowlist;
 *     deferred pending a dedicated rename pass.
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
class InventoryValuationReportService {
	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for diagnostics; never logs full payloads.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Compute the inventory value as of a cut-off date, per (sku, warehouse).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $asOfDate ISO cut-off (yyyy-mm-dd or full timestamp); inclusive.
	 * @param string|null $sku Optional single-SKU filter.
	 * @param string|null $warehouse Optional single-warehouse filter.
	 *
	 * @return array<string,mixed> { asOfDate, totalValue, totalQuantity, lines:[{sku,warehouse,method,quantity,unitCost,totalValue}] }
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function valuationAsOf(
		string $administrationId,
		string $asOfDate,
		?string $sku = null,
		?string $warehouse = null,
	): array {
		$cutoff = $this->normaliseCutoff(asOfDate: $asOfDate);
		$groups = $this->groupedMoves(administrationId: $administrationId, cutoff: $cutoff, sku: $sku, warehouse: $warehouse);

		$lines = [];
		$totalCents = 0;
		$totalQuantity = 0.0;
		foreach ($groups as $key => $moves) {
			[$groupSku, $groupWarehouse] = $this->splitKey(key: $key);
			$method = $this->methodFor(
				administrationId: $administrationId,
				sku: $groupSku,
				warehouse: $groupWarehouse
			);
			$snapshot = $this->replay(moves: $moves, method: $method);
			if ($snapshot['quantity'] <= 0.0 && $snapshot['totalValueCents'] === 0) {
				continue;
			}

			$unitCost = 0.0;
			if ($snapshot['quantity'] > 0) {
				$unitCost = round(($snapshot['totalValueCents'] / 100) / $snapshot['quantity'], 4);
			}

			$lines[] = [
				'sku' => $groupSku,
				'warehouse' => $groupWarehouse,
				'method' => $method,
				'quantity' => round($snapshot['quantity'], 2),
				'unitCost' => $unitCost,
				'totalValue' => round(($snapshot['totalValueCents'] / 100), 2),
			];
			$totalCents += $snapshot['totalValueCents'];
			$totalQuantity += $snapshot['quantity'];
		}//end foreach

		return [
			'asOfDate' => $cutoff,
			'totalValue' => round(($totalCents / 100), 2),
			'totalQuantity' => round($totalQuantity, 2),
			'lines' => $lines,
		];

	}//end valuationAsOf()

	/**
	 * Bucket FIFO residual open lots by age as of a cut-off date.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $asOfDate ISO cut-off; inclusive.
	 * @param string $sku Product SKU.
	 * @param string $warehouse Warehouse location id.
	 *
	 * @return array<string,mixed> { asOfDate, sku, warehouse, buckets:{0-30,31-60,61-90,90+}, totalValue }
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function ageing(string $administrationId, string $asOfDate, string $sku, string $warehouse): array {
		$cutoff = $this->normaliseCutoff(asOfDate: $asOfDate);
		$moves = $this->movesFor(
			administrationId: $administrationId,
			sku: $sku,
			warehouse: $warehouse,
			cutoff: $cutoff
		);

		$replay = $this->replay(moves: $moves, method: 'FIFO');
		$residualLots = $replay['lots'];
		$cutoffTs = strtotime($cutoff);
		$buckets = [
			'0-30' => 0,
			'31-60' => 0,
			'61-90' => 0,
			'90+' => 0,
		];

		foreach ($residualLots as $lot) {
			$lotCents = (int)round(((float)$lot['quantity']) * ((float)$lot['unitCost']) * 100);
			$lotTs = strtotime((string)($lot['postedAt'] ?? ''));
			$ageDays = 0;
			if ($cutoffTs !== false && $lotTs !== false) {
				$ageDays = (int)floor((($cutoffTs - $lotTs) / 86400));
			}

			$bucket = '90+';
			if ($ageDays <= 30) {
				$bucket = '0-30';
			} elseif ($ageDays <= 60) {
				$bucket = '31-60';
			} elseif ($ageDays <= 90) {
				$bucket = '61-90';
			}

			$buckets[$bucket] += $lotCents;
		}//end foreach

		$out = [
			'asOfDate' => $cutoff,
			'sku' => $sku,
			'warehouse' => $warehouse,
			'totalValue' => round(($replay['totalValueCents'] / 100), 2),
			'buckets' => [],
		];
		foreach ($buckets as $label => $cents) {
			$out['buckets'][$label] = round(($cents / 100), 2);
		}

		return $out;
	}//end ageing()

	/**
	 * Inventory turnover ratio + days-on-hand for a (sku, warehouse) over a window.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $from Window start (ISO, inclusive).
	 * @param string $to Window end (ISO, inclusive).
	 * @param string $sku Product SKU.
	 * @param string $warehouse Warehouse location id.
	 *
	 * @return array<string,mixed> { from, to, cogs, averageInventoryValue, turnoverRatio, daysOnHand }
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function turnover(string $administrationId, string $from, string $to, string $sku, string $warehouse): array {
		$fromCut = $this->normaliseCutoff(asOfDate: $from);
		$toCut = $this->normaliseCutoff(asOfDate: $to);
		$method = $this->methodFor(administrationId: $administrationId, sku: $sku, warehouse: $warehouse);

		$startMoves = $this->movesFor(administrationId: $administrationId, sku: $sku, warehouse: $warehouse, cutoff: $fromCut);
		$endMoves = $this->movesFor(administrationId: $administrationId, sku: $sku, warehouse: $warehouse, cutoff: $toCut);

		$startCents = $this->replay(moves: $startMoves, method: $method)['totalValueCents'];
		$endCents = $this->replay(moves: $endMoves, method: $method)['totalValueCents'];
		$avgCents = (int)round((($startCents + $endCents) / 2));

		// COGS in window = value consumed by issues whose postedAt in (from, to].
		$cogsCents = $this->cogsInWindow(
			administrationId: $administrationId,
			sku: $sku,
			warehouse: $warehouse,
			method: $method,
			fromCut: $fromCut,
			toCut: $toCut
		);

		$turnoverRatio = 0.0;
		$daysOnHand = 0.0;
		if ($avgCents > 0) {
			$turnoverRatio = round(($cogsCents / $avgCents), 4);
			$windowDays = $this->windowDays(fromCut: $fromCut, toCut: $toCut);
			if ($cogsCents > 0) {
				$daysOnHand = round(($avgCents / $cogsCents) * $windowDays, 2);
			}
		}

		return [
			'from' => $fromCut,
			'to' => $toCut,
			'method' => $method,
			'cogs' => round(($cogsCents / 100), 2),
			'averageInventoryValue' => round(($avgCents / 100), 2),
			'turnoverRatio' => $turnoverRatio,
			'daysOnHand' => $daysOnHand,
		];

	}//end turnover()

	/**
	 * Replay a chronological move list into a valuation snapshot.
	 *
	 * @param array<int,array<string,mixed>> $moves Posted moves, will be sorted by postedAt.
	 * @param string $method 'FIFO' or 'average'.
	 *
	 * @return array{quantity: float, totalValueCents: int, lots: array<int,array<string,mixed>>}
	 */
	private function replay(array $moves, string $method): array {
		usort(
			$moves,
			static fn (array $a, array $b): int => strcmp(
				(string)($a['postedAt'] ?? ($a['draftedAt'] ?? '')),
				(string)($b['postedAt'] ?? ($b['draftedAt'] ?? ''))
			)
		);

		if ($method === 'average') {
			return $this->replayAverage(moves: $moves);
		}

		return $this->replayFifo(moves: $moves);
	}//end replay()

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
			$qty = (float)($move['quantity'] ?? 0);
			if ($qty <= 0) {
				continue;
			}

			if ($type === 'receipt') {
				$lots[] = [
					'quantity' => $qty,
					'unitCost' => (float)($move['unitCost'] ?? 0),
					'postedAt' => (string)($move['postedAt'] ?? ($move['draftedAt'] ?? '')),
				];
				continue;
			}

			if ($type !== 'issue') {
				continue;
			}

			$remaining = $qty;
			foreach ($lots as $i => $lot) {
				if ($remaining <= 0) {
					break;
				}

				$take = min((float)$lot['quantity'], $remaining);
				$lots[$i]['quantity'] = ((float)$lot['quantity'] - $take);
				$remaining -= $take;
			}

			$lots = array_values(
				array_filter($lots, static fn (array $lot): bool => ((float)$lot['quantity']) > 1e-9)
			);
		}//end foreach

		$qtyTotal = 0.0;
		$valueCents = 0;
		foreach ($lots as $lot) {
			$qtyTotal += (float)$lot['quantity'];
			$valueCents += (int)round(((float)$lot['quantity']) * ((float)$lot['unitCost']) * 100);
		}

		return [
			'quantity' => $qtyTotal,
			'totalValueCents' => $valueCents,
			'lots' => $lots,
		];

	}//end replayFifo()

	/**
	 * Moving-average replay: running weighted average.
	 *
	 * @param array<int,array<string,mixed>> $moves Chronologically sorted moves.
	 *
	 * @return array{quantity: float, totalValueCents: int, lots: array<int,array<string,mixed>>}
	 */
	private function replayAverage(array $moves): array {
		$qty = 0.0;
		$avgCost = 0.0;
		foreach ($moves as $move) {
			$type = (string)($move['movementType'] ?? '');
			$moveQty = (float)($move['quantity'] ?? 0);
			if ($moveQty <= 0) {
				continue;
			}

			if ($type === 'receipt') {
				$rcvCost = (float)($move['unitCost'] ?? 0);
				$newQty = ($qty + $moveQty);
				if ($newQty > 0) {
					$avgCost = round(((($qty * $avgCost) + ($moveQty * $rcvCost)) / $newQty), 4);
				}

				$qty = $newQty;
				continue;
			}

			if ($type === 'issue') {
				$qty = max(0.0, ($qty - $moveQty));
			}
		}//end foreach

		$valueCents = (int)round(($qty * $avgCost) * 100);
		return [
			'quantity' => $qty,
			'totalValueCents' => $valueCents,
			'lots' => [],
		];

	}//end replayAverage()

	/**
	 * COGS consumed by issues whose postedAt falls within (fromCut, toCut],
	 * valued under the active method by replaying up to each issue.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $sku Product SKU.
	 * @param string $warehouse Warehouse location id.
	 * @param string $method 'FIFO' or 'average'.
	 * @param string $fromCut Window start (exclusive).
	 * @param string $toCut Window end (inclusive).
	 *
	 * @return int COGS in integer cents.
	 */
	private function cogsInWindow(
		string $administrationId,
		string $sku,
		string $warehouse,
		string $method,
		string $fromCut,
		string $toCut,
	): int {
		$startCents = $this->replay(
			moves: $this->movesFor(administrationId: $administrationId, sku: $sku, warehouse: $warehouse, cutoff: $fromCut),
			method: $method
		)['totalValueCents'];
		$endCents = $this->replay(
			moves: $this->movesFor(administrationId: $administrationId, sku: $sku, warehouse: $warehouse, cutoff: $toCut),
			method: $method
		)['totalValueCents'];

		// Receipts landed in the window add value; the residual delta that is
		// NOT explained by receipts is the value consumed by issues (COGS).
		$receiptsCents = $this->receiptsValueInWindow(
			administrationId: $administrationId,
			sku: $sku,
			warehouse: $warehouse,
			fromCut: $fromCut,
			toCut: $toCut
		);

		$cogsCents = (($startCents + $receiptsCents) - $endCents);
		if ($cogsCents < 0) {
			return 0;
		}

		return $cogsCents;
	}//end cogsInWindow()

	/**
	 * Sum receipt value (qty × unitCost) landed within (fromCut, toCut].
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $sku Product SKU.
	 * @param string $warehouse Warehouse location id.
	 * @param string $fromCut Window start (exclusive).
	 * @param string $toCut Window end (inclusive).
	 *
	 * @return int
	 */
	private function receiptsValueInWindow(
		string $administrationId,
		string $sku,
		string $warehouse,
		string $fromCut,
		string $toCut,
	): int {
		$cents = 0;
		foreach ($this->rawReceipts(administrationId: $administrationId, sku: $sku, warehouse: $warehouse) as $move) {
			$postedAt = (string)($move['postedAt'] ?? ($move['draftedAt'] ?? ''));
			if ($postedAt === '' || strcmp($postedAt, $fromCut) <= 0 || strcmp($postedAt, $toCut) > 0) {
				continue;
			}

			$cents += (int)round(((float)($move['quantity'] ?? 0)) * ((float)($move['unitCost'] ?? 0)) * 100);
		}

		return $cents;
	}//end receiptsValueInWindow()

	/**
	 * All posted, non-cancelled receipt + issue moves for a (sku, warehouse)
	 * with postedAt <= cutoff, normalised.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $sku Product SKU.
	 * @param string $warehouse Warehouse location id.
	 * @param string $cutoff Inclusive cut-off timestamp.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function movesFor(string $administrationId, string $sku, string $warehouse, string $cutoff): array {
		$receipts = $this->rawReceipts(administrationId: $administrationId, sku: $sku, warehouse: $warehouse);
		$issues = $this->rawIssues(administrationId: $administrationId, sku: $sku, warehouse: $warehouse);

		$out = [];
		foreach (array_merge($receipts, $issues) as $move) {
			$postedAt = (string)($move['postedAt'] ?? ($move['draftedAt'] ?? ''));
			if ($postedAt !== '' && strcmp($postedAt, $cutoff) > 0) {
				continue;
			}

			$out[] = $move;
		}

		return $out;
	}//end movesFor()

	/**
	 * Grouped posted moves keyed by "sku\x00warehouse" for the whole
	 * administration up to a cut-off, honouring optional filters.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $cutoff Inclusive cut-off.
	 * @param string|null $sku Optional SKU filter.
	 * @param string|null $warehouse Optional warehouse filter.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function groupedMoves(string $administrationId, string $cutoff, ?string $sku, ?string $warehouse): array {
		$filters = [
			'administrationId' => $administrationId,
			'lifecycleState' => 'posted',
		];
		if ($sku !== null && $sku !== '') {
			$filters['itemId'] = $sku;
		}

		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('StockMove')
			->findAll(['filters' => $filters]);

		$groups = [];
		foreach ($rows as $row) {
			$move = $this->asArray(row: $row);
			$type = (string)($move['movementType'] ?? '');
			if (in_array($type, ['receipt', 'issue'], true) === false) {
				continue;
			}

			$wh = (string)($move['sourceLocationId'] ?? '');
			if ($type === 'receipt') {
				$wh = (string)($move['destinationLocationId'] ?? '');
			}

			if ($warehouse !== null && $warehouse !== '' && $wh !== $warehouse) {
				continue;
			}

			$postedAt = (string)($move['postedAt'] ?? ($move['draftedAt'] ?? ''));
			if ($postedAt !== '' && strcmp($postedAt, $cutoff) > 0) {
				continue;
			}

			$groups[$this->makeKey(sku: (string)($move['itemId'] ?? ''), warehouse: $wh)][] = $move;
		}//end foreach

		return $groups;
	}//end groupedMoves()

	/**
	 * Raw receipt moves for a (sku, warehouse).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $sku Product SKU.
	 * @param string $warehouse Warehouse location id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rawReceipts(string $administrationId, string $sku, string $warehouse): array {
		return $this->rawMoves(
			filters: [
				'administrationId' => $administrationId,
				'itemId' => $sku,
				'destinationLocationId' => $warehouse,
				'movementType' => 'receipt',
				'lifecycleState' => 'posted',
			]
		);

	}//end rawReceipts()

	/**
	 * Raw issue moves for a (sku, warehouse).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $sku Product SKU.
	 * @param string $warehouse Warehouse location id.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rawIssues(string $administrationId, string $sku, string $warehouse): array {
		return $this->rawMoves(
			filters: [
				'administrationId' => $administrationId,
				'itemId' => $sku,
				'sourceLocationId' => $warehouse,
				'movementType' => 'issue',
				'lifecycleState' => 'posted',
			]
		);

	}//end rawIssues()

	/**
	 * Execute a StockMove findAll and normalise.
	 *
	 * @param array<string,mixed> $filters Filter map.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rawMoves(array $filters): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('StockMove')
			->findAll(['filters' => $filters]);

		$out = [];
		foreach ($rows as $row) {
			$out[] = $this->asArray(row: $row);
		}

		return $out;
	}//end rawMoves()

	/**
	 * Resolve the valuation method for a (sku, warehouse), default FIFO.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $sku Product SKU.
	 * @param string $warehouse Warehouse location id.
	 *
	 * @return string 'FIFO' or 'average'.
	 */
	private function methodFor(string $administrationId, string $sku, string $warehouse): string {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema('InventoryValuation')
				->findAll(
					[
						'filters' => [
							'productId' => $sku,
							'warehouse' => $warehouse,
							'administrationId' => $administrationId,
						],
						'limit' => 1,
					]
				);

			if ($rows !== []) {
				$method = (string)($this->asArray(row: $rows[0])['valuationMethod'] ?? 'FIFO');
				if ($method === 'average') {
					return 'average';
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning(
				'InventoryValuationReportService: methodFor lookup failed — defaulting to FIFO',
				['sku' => $sku, 'exception' => $e->getMessage()]
			);
		}//end try

		return 'FIFO';
	}//end methodFor()

	/**
	 * Normalise a cut-off to an end-of-day inclusive timestamp when a bare
	 * date is supplied, so `postedAt <= cutoff` includes same-day moves.
	 *
	 * @param string $asOfDate ISO date or timestamp.
	 *
	 * @return string
	 */
	private function normaliseCutoff(string $asOfDate): string {
		$trimmed = trim($asOfDate);
		if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
			return $trimmed . 'T23:59:59Z';
		}

		return $trimmed;
	}//end normaliseCutoff()

	/**
	 * Count whole days in a (fromCut, toCut] window (minimum 1).
	 *
	 * @param string $fromCut Window start.
	 * @param string $toCut Window end.
	 *
	 * @return int
	 */
	private function windowDays(string $fromCut, string $toCut): int {
		$fromTs = strtotime($fromCut);
		$toTs = strtotime($toCut);
		if ($fromTs === false || $toTs === false || $toTs <= $fromTs) {
			return 1;
		}

		return max(1, (int)ceil((($toTs - $fromTs) / 86400)));
	}//end windowDays()

	/**
	 * Build the group key.
	 *
	 * @param string $sku Product SKU.
	 * @param string $warehouse Warehouse location id.
	 *
	 * @return string
	 */
	private function makeKey(string $sku, string $warehouse): string {
		return $sku . "\x00" . $warehouse;
	}//end makeKey()

	/**
	 * Split a group key.
	 *
	 * @param string $key The "sku\0warehouse" key.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function splitKey(string $key): array {
		$parts = explode("\x00", $key, 2);
		return [
			($parts[0] ?? ''),
			($parts[1] ?? ''),
		];

	}//end splitKey()

	/**
	 * Normalise an OR Object / array to a plain array<string,mixed>.
	 *
	 * @param mixed $row Raw row from ObjectService.
	 *
	 * @return array<string,mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}

			return [];
		}

		return [];
	}//end asArray()

	/**
	 * Resolve the OR register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
