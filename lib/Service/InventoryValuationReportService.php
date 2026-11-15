<?php

/**
 * Inventory Valuation Report Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\InventoryValuationReportServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

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
class InventoryValuationReportService implements InventoryValuationReportServiceInterface {
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
		$filter = new StockLocation();
		$filter->administrationId = $administrationId;
		$filter->sku       = (string)$sku;
		$filter->warehouse = (string)$warehouse;

		$cutoff = $this->normaliseCutoff(asOfDate: $asOfDate);
		$groups = $this->groupedMoves(filter: $filter, cutoff: $cutoff);
		return ['asOfDate' => $cutoff] + $this->valuationOf(groups: $groups, administrationId: $administrationId);

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
		$location = new StockLocation();
		$location->administrationId = $administrationId;
		$location->sku       = $sku;
		$location->warehouse = $warehouse;

		$cutoff = $this->normaliseCutoff(asOfDate: $asOfDate);
		$replay = $this->moveReplay()->replay(moves: $this->movesFor(location: $location, cutoff: $cutoff), method: 'FIFO');

		return [
			'asOfDate' => $cutoff,
			'sku' => $sku,
			'warehouse' => $warehouse,
			'totalValue' => round(($replay['totalValueCents'] / 100), 2),
			'buckets' => $this->moveReplay()->ageBuckets(lots: $replay['lots'], cutoff: $cutoff),
		];
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
		$window = new StockWindow();
		$window->location = new StockLocation();
		$window->location->administrationId = $administrationId;
		$window->location->sku       = $sku;
		$window->location->warehouse = $warehouse;
		$window->fromCut = $this->normaliseCutoff(asOfDate: $from);
		$window->toCut   = $this->normaliseCutoff(asOfDate: $to);
		$window->method  = $this->methodFor(location: $window->location);

		return $this->turnoverOf(window: $window);

	}//end turnover()

	/**
	 * The valued lines and totals of grouped moves; a group with nothing
	 * left is skipped.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $groups           Moves keyed by "sku\x00warehouse".
	 * @param string                                       $administrationId Tenant scope.
	 *
	 * @return array{totalValue: float, totalQuantity: float, lines: array<int,array<string,mixed>>}
	 */
	private function valuationOf(array $groups, string $administrationId): array {
		$lines         = [];
		$totalCents    = 0;
		$totalQuantity = 0.0;
		foreach ($groups as $key => $moves) {
			$line = $this->valuationLine(location: $this->locationOf(administrationId: $administrationId, key: $key), moves: $moves);
			if ($line !== null) {
				$totalCents    += $line['totalValueCents'];
				$totalQuantity += $line['rawQuantity'];
				unset($line['totalValueCents'], $line['rawQuantity']);
				$lines[] = $line;
			}
		}

		return ['totalValue' => round(($totalCents / 100), 2), 'totalQuantity' => round($totalQuantity, 2), 'lines' => $lines];
	}//end valuationOf()

	/**
	 * The valued line of one stock position under its method, with its raw
	 * cents and quantity for the totals; null when nothing is left.
	 *
	 * @param StockLocation                  $location The stock position.
	 * @param array<int,array<string,mixed>> $moves    Its moves up to the cut-off.
	 *
	 * @return array<string,mixed>|null
	 */
	private function valuationLine(StockLocation $location, array $moves): ?array {
		$method   = $this->methodFor(location: $location);
		$snapshot = $this->moveReplay()->replay(moves: $moves, method: $method);
		if ($snapshot['quantity'] <= 0.0 && $snapshot['totalValueCents'] === 0) {
			return null;
		}

		$unitCost = 0.0;
		if ($snapshot['quantity'] > 0) {
			$unitCost = round(($snapshot['totalValueCents'] / 100) / $snapshot['quantity'], 4);
		}

		return [
			'sku' => $location->sku,
			'warehouse' => $location->warehouse,
			'method' => $method,
			'quantity' => round($snapshot['quantity'], 2),
			'unitCost' => $unitCost,
			'totalValue' => round(($snapshot['totalValueCents'] / 100), 2),
			'totalValueCents' => $snapshot['totalValueCents'],
			'rawQuantity' => $snapshot['quantity'],
		];
	}//end valuationLine()

	/**
	 * The stock position a group key names.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $key              The "sku\x00warehouse" key.
	 *
	 * @return StockLocation
	 */
	private function locationOf(string $administrationId, string $key): StockLocation {
		[$sku, $warehouse] = $this->splitKey(key: $key);

		$location = new StockLocation();
		$location->administrationId = $administrationId;
		$location->sku       = $sku;
		$location->warehouse = $warehouse;
		return $location;
	}//end locationOf()

	/**
	 * Turnover ratio and days on hand of a window: COGS over the average of
	 * the opening and closing inventory value.
	 *
	 * @param StockWindow $window The position, window and method.
	 *
	 * @return array<string,mixed>
	 */
	private function turnoverOf(StockWindow $window): array {
		$startCents = $this->valueAt(window: $window, cutoff: $window->fromCut);
		$endCents   = $this->valueAt(window: $window, cutoff: $window->toCut);
		$avgCents   = (int)round((($startCents + $endCents) / 2));
		$cogsCents  = $this->cogsInWindow(window: $window);

		$turnoverRatio = 0.0;
		$daysOnHand    = 0.0;
		if ($avgCents > 0) {
			$turnoverRatio = round(($cogsCents / $avgCents), 4);
			$daysOnHand    = $this->daysOnHand(window: $window, cents: ['average' => $avgCents, 'cogs' => $cogsCents]);
		}

		return [
			'from' => $window->fromCut,
			'to' => $window->toCut,
			'method' => $window->method,
			'cogs' => round(($cogsCents / 100), 2),
			'averageInventoryValue' => round(($avgCents / 100), 2),
			'turnoverRatio' => $turnoverRatio,
			'daysOnHand' => $daysOnHand,
		];
	}//end turnoverOf()

	/**
	 * Days on hand: average inventory over COGS times the window's days; 0
	 * without COGS.
	 *
	 * @param StockWindow                       $window The window.
	 * @param array{average: int, cogs: int}    $cents  Average inventory and COGS in cents.
	 *
	 * @return float
	 */
	private function daysOnHand(StockWindow $window, array $cents): float {
		$windowDays = $this->windowDays(fromCut: $window->fromCut, toCut: $window->toCut);
		if ($cents['cogs'] <= 0) {
			return 0.0;
		}

		return round(($cents['average'] / $cents['cogs']) * $windowDays, 2);
	}//end daysOnHand()

	/**
	 * The inventory value of the window's position at a cut-off, in cents.
	 *
	 * @param StockWindow $window The position and method.
	 * @param string      $cutoff Inclusive cut-off.
	 *
	 * @return int
	 */
	private function valueAt(StockWindow $window, string $cutoff): int {
		return $this->moveReplay()->replay(
			moves: $this->movesFor(location: $window->location, cutoff: $cutoff),
			method: $window->method
		)['totalValueCents'];
	}//end valueAt()

	/**
	 * COGS consumed by issues whose postedAt falls within (fromCut, toCut],
	 * valued under the active method: receipts landed in the window add value;
	 * the residual delta that is NOT explained by receipts is the value
	 * consumed by issues.
	 *
	 * @param StockWindow $window The position, window and method.
	 *
	 * @return int COGS in integer cents.
	 */
	private function cogsInWindow(StockWindow $window): int {
		$startCents    = $this->valueAt(window: $window, cutoff: $window->fromCut);
		$endCents      = $this->valueAt(window: $window, cutoff: $window->toCut);
		$receiptsCents = $this->receiptsValueInWindow(window: $window);

		return max(0, (($startCents + $receiptsCents) - $endCents));
	}//end cogsInWindow()

	/**
	 * Sum receipt value (qty × unitCost) landed within (fromCut, toCut].
	 *
	 * @param StockWindow $window The position and window.
	 *
	 * @return int
	 */
	private function receiptsValueInWindow(StockWindow $window): int {
		$cents = 0;
		foreach ($this->rawReceipts(location: $window->location) as $move) {
			$postedAt = $this->moveReplay()->postedAtOf(move: $move);
			if ($postedAt !== '' && strcmp($postedAt, $window->fromCut) > 0 && strcmp($postedAt, $window->toCut) <= 0) {
				$cents += (int)round(((float)($move['quantity'] ?? 0)) * ((float)($move['unitCost'] ?? 0)) * 100);
			}
		}

		return $cents;
	}//end receiptsValueInWindow()

	/**
	 * All posted receipt + issue moves of a position with postedAt <= cutoff
	 * (or no postedAt).
	 *
	 * @param StockLocation $location The stock position.
	 * @param string        $cutoff   Inclusive cut-off timestamp.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function movesFor(StockLocation $location, string $cutoff): array {
		$moves = array_merge($this->rawReceipts(location: $location), $this->rawIssues(location: $location));

		return array_values(array_filter($moves, fn (array $move): bool => $this->isUpTo(move: $move, cutoff: $cutoff)));
	}//end movesFor()

	/**
	 * Grouped posted moves keyed by "sku\x00warehouse" for the whole
	 * administration up to a cut-off, honouring the filter's SKU and
	 * warehouse when they are set.
	 *
	 * @param StockLocation $filter The administration and optional SKU / warehouse.
	 * @param string        $cutoff Inclusive cut-off.
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function groupedMoves(StockLocation $filter, string $cutoff): array {
		$groups = [];
		foreach ($this->rawMoves(filters: $this->groupFilters(filter: $filter)) as $move) {
			$warehouse = $this->groupWarehouse(move: $move, filter: $filter);
			if ($warehouse !== null && $this->isUpTo(move: $move, cutoff: $cutoff) === true) {
				$groups[$this->makeKey(sku: (string)($move['itemId'] ?? ''), warehouse: $warehouse)][] = $move;
			}
		}

		return $groups;
	}//end groupedMoves()

	/**
	 * The StockMove filters of a grouped read: the administration's posted
	 * moves, of one SKU when the filter names one.
	 *
	 * @param StockLocation $filter The administration and optional SKU.
	 *
	 * @return array<string,string>
	 */
	private function groupFilters(StockLocation $filter): array {
		$filters = [
			'administrationId' => $filter->administrationId,
			'lifecycleState' => 'posted',
		];
		if ($filter->sku !== '') {
			$filters['itemId'] = $filter->sku;
		}

		return $filters;
	}//end groupFilters()

	/**
	 * The warehouse a receipt or issue belongs to, or null when the move is
	 * neither or lies outside the filter's warehouse.
	 *
	 * @param array<string,mixed> $move   One stock move.
	 * @param StockLocation       $filter The optional warehouse filter.
	 *
	 * @return string|null
	 */
	private function groupWarehouse(array $move, StockLocation $filter): ?string {
		$type = (string)($move['movementType'] ?? '');
		if (in_array($type, ['receipt', 'issue'], true) === false) {
			return null;
		}

		$warehouse = (string)($move['sourceLocationId'] ?? '');
		if ($type === 'receipt') {
			$warehouse = (string)($move['destinationLocationId'] ?? '');
		}

		if ($filter->warehouse !== '' && $warehouse !== $filter->warehouse) {
			return null;
		}

		return $warehouse;
	}//end groupWarehouse()

	/**
	 * Whether a move has no postedAt or one on or before the cut-off.
	 *
	 * @param array<string,mixed> $move   One stock move.
	 * @param string              $cutoff Inclusive cut-off.
	 *
	 * @return bool
	 */
	private function isUpTo(array $move, string $cutoff): bool {
		$postedAt = $this->moveReplay()->postedAtOf(move: $move);
		return $postedAt === '' || strcmp($postedAt, $cutoff) <= 0;
	}//end isUpTo()

	/**
	 * Raw receipt moves for a (sku, warehouse).
	 *
	 * @param StockLocation $location The stock position.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rawReceipts(StockLocation $location): array {
		return $this->rawMoves(
			filters: [
				'administrationId' => $location->administrationId,
				'itemId' => $location->sku,
				'destinationLocationId' => $location->warehouse,
				'movementType' => 'receipt',
				'lifecycleState' => 'posted',
			]
		);

	}//end rawReceipts()

	/**
	 * Raw issue moves for a (sku, warehouse).
	 *
	 * @param StockLocation $location The stock position.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function rawIssues(StockLocation $location): array {
		return $this->rawMoves(
			filters: [
				'administrationId' => $location->administrationId,
				'itemId' => $location->sku,
				'sourceLocationId' => $location->warehouse,
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
	 * Resolve the valuation method for a stock position, default FIFO; a
	 * failed lookup is logged and answers FIFO.
	 *
	 * @param StockLocation $location The stock position.
	 *
	 * @return string 'FIFO' or 'average'.
	 */
	private function methodFor(StockLocation $location): string {
		try {
			return $this->storedMethod(location: $location);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'InventoryValuationReportService: methodFor lookup failed — defaulting to FIFO',
				['sku' => $location->sku, 'exception' => $e->getMessage()]
			);
		}

		return 'FIFO';
	}//end methodFor()

	/**
	 * The valuation method stored on the position's InventoryValuation: 'average'
	 * when it says so, else FIFO.
	 *
	 * @param StockLocation $location The stock position.
	 *
	 * @return string 'FIFO' or 'average'.
	 */
	private function storedMethod(StockLocation $location): string {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('InventoryValuation')
			->findAll(
				[
					'filters' => [
						'productId' => $location->sku,
						'warehouse' => $location->warehouse,
						'administrationId' => $location->administrationId,
					],
					'limit' => 1,
				]
			);

		if ($rows !== [] && (string)($this->asArray(row: $rows[0])['valuationMethod'] ?? 'FIFO') === 'average') {
			return 'average';
		}

		return 'FIFO';
	}//end storedMethod()

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

		if (is_object($row) === false) {
			return [];
		}

		if (method_exists($row, 'jsonSerialize') === true) {
			return $this->arrayOrEmpty(value: $row->jsonSerialize());
		}

		if (method_exists($row, 'getObject') === true) {
			return $this->arrayOrEmpty(value: $row->getObject());
		}

		return [];
	}//end asArray()

	/**
	 * The valuation arithmetic.
	 *
	 * @return StockMoveReplay
	 */
	private function moveReplay(): StockMoveReplay {
		return new StockMoveReplay();
	}//end moveReplay()

	/**
	 * A value when it is an array, else [].
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string,mixed>
	 */
	private function arrayOrEmpty(mixed $value): array {
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end arrayOrEmpty()

	/**
	 * Resolve the OR register slug, defaulting to 'shillinq'.
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
