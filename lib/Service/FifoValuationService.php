<?php

/**
 * FIFO Valuation Service
 *
 * REQ-INV-003 imperative FIFO cost-layer engine for InventoryValuation.
 *
 * Listens (via {@see \OCA\Shillinq\Listener\StockMoveTransitionedListener})
 * to the `post` transition of every `StockMove` record whose driving
 * `InventoryValuation` is set to `valuationMethod = FIFO`. Two
 * branches:
 *
 *   - **receipt** (`movementType: receipt`, null source location):
 *     a new FIFO cost lot is added implicitly (lots live in the
 *     StockMove ledger itself, per design.md D2). The snapshot's
 *     `quantity`, `unitCost` (weighted average of remaining open lots)
 *     and `totalValue` are recomputed.
 *
 *   - **issue** (`movementType: issue`, null destination location):
 *     the engine queries all posted, non-cancelled inbound `StockMove`
 *     rows for the same `(productId, warehouse)` ordered chronologically
 *     by `postedAt`. It walks the lot list, deducting from the oldest
 *     lot first until the outbound quantity is fully allocated, and
 *     returns the consumed `(lotCost, qty)` pairs as the COGS basis
 *     (forwarded to {@see CogsPosterService}). The snapshot is then
 *     recomputed from the residual open lots.
 *
 * Idempotency: the snapshot's `lastStockMoveUuid` is checked at entry —
 * if the incoming uuid matches, this is a retry and the engine returns
 * a no-op result (REQ-INV-003 idempotency scenario). On success the
 * uuid is stamped on the snapshot before save.
 *
 * No persistence beyond the snapshot itself. The StockMove ledger is
 * read-only here. All reads via the real OR ObjectService API
 * (find / findAll / saveObject) per ADR-022.
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Imperative FIFO cost layer engine driving {@see CogsPosterService}.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ElseExpression)         Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
 */
class FifoValuationService {
	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config for the OR register slug.
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
	 * Process a posted StockMove for a FIFO-valued item.
	 *
	 * Returns a result envelope:
	 * <code>
	 *  [
	 *    'processed'  => bool,            // false on idempotent retry
	 *    'valuation'  => array,           // updated InventoryValuation snapshot
	 *    'cogsCents'  => int,             // COGS amount in integer cents (issue only)
	 *    'cogsBasis'  => array<array{lotCost: float, quantity: float}>, // consumed lots
	 *    'message'    => string,
	 *  ]
	 * </code>
	 *
	 * @param array<string,mixed> $move The posted StockMove record.
	 *
	 * @return array<string,mixed> Result envelope.
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-7
	 */
	public function processStockMove(array $move): array {
		$movementType = (string)($move['movementType'] ?? '');
		if (in_array($movementType, ['receipt', 'issue'], true) === false) {
			// Transfer / manufacture / repack don't drive FIFO valuation directly.
			return [
				'processed' => false,
				'message' => 'movementType ' . $movementType . ' is not a FIFO trigger',
			];
		}

		$productId = (string)($move['itemId'] ?? '');
		if ($movementType === 'receipt') {
			$warehouse = (string)($move['destinationLocationId'] ?? '');
		} else {
			$warehouse = (string)($move['sourceLocationId'] ?? '');
		}

		$administrationId = (string)($move['administrationId'] ?? '');
		$moveUuid = (string)($move['uuid'] ?? ($move['@self']['uuid'] ?? ($move['id'] ?? '')));

		if ($productId === '' || $warehouse === '' || $administrationId === '' || $moveUuid === '') {
			return [
				'processed' => false,
				'message' => 'StockMove missing itemId / location / administrationId / uuid',
			];
		}

		$valuation = $this->findOrCreateValuation(
			productId: $productId,
			warehouse: $warehouse,
			administrationId: $administrationId
		);

		// Idempotent retry guard per REQ-INV-003 scenario.
		if ((string)($valuation['lastStockMoveUuid'] ?? '') === $moveUuid) {
			$this->logger->info(
				'FifoValuationService: idempotent retry — skipping',
				[
					'productId' => $productId,
					'warehouse' => $warehouse,
					'moveUuid' => $moveUuid,
				]
			);
			return [
				'processed' => false,
				'valuation' => $valuation,
				'cogsCents' => 0,
				'cogsBasis' => [],
				'message' => 'idempotent retry',
			];
		}

		if ($movementType === 'receipt') {
			return $this->processReceipt(
				move: $move,
				valuation: $valuation,
				moveUuid: $moveUuid,
				productId: $productId,
				warehouse: $warehouse,
				administrationId: $administrationId
			);
		}

		return $this->processIssue(
			move: $move,
			valuation: $valuation,
			moveUuid: $moveUuid,
			productId: $productId,
			warehouse: $warehouse,
			administrationId: $administrationId
		);

	}//end processStockMove()

	/**
	 * Recompute the snapshot from open inbound lots, no COGS to post.
	 *
	 * @param array<string,mixed> $move The receipt StockMove.
	 * @param array<string,mixed> $valuation Current snapshot.
	 * @param string $moveUuid Idempotency key.
	 * @param string $productId Product / item id.
	 * @param string $warehouse Warehouse location id.
	 * @param string $administrationId Tenant scope.
	 *
	 * @return array<string,mixed> Result envelope.
	 */
	private function processReceipt(
		array $move,
		array $valuation,
		string $moveUuid,
		string $productId,
		string $warehouse,
		string $administrationId,
	): array {
		$openLots = $this->openInboundLots(
			productId: $productId,
			warehouse: $warehouse,
			administrationId: $administrationId
		);

		$consumedQty = $this->consumedQtyFromHistory(
			productId: $productId,
			warehouse: $warehouse,
			administrationId: $administrationId
		);

		$remainingLots = $this->residualLotsAfterConsumption(
			openLots: $openLots,
			consumedQty: $consumedQty
		);

		$snapshot = $this->snapshotFromLots(remainingLots: $remainingLots);

		$valuation['quantity'] = $snapshot['quantity'];
		$valuation['unitCost'] = $snapshot['unitCost'];
		$valuation['totalValue'] = $snapshot['totalValue'];
		$valuation['date'] = (string)($move['postedAt'] ?? ($move['draftedAt'] ?? ''));
		$valuation['lastStockMoveUuid'] = $moveUuid;

		$saved = $this->saveValuation(data: $valuation);

		return [
			'processed' => true,
			'valuation' => $saved,
			'cogsCents' => 0,
			'cogsBasis' => [],
			'message' => 'receipt processed',
		];

	}//end processReceipt()

	/**
	 * Walk open lots oldest-first, deduct outbound qty, return cogs basis.
	 *
	 * @param array<string,mixed> $move The issue StockMove.
	 * @param array<string,mixed> $valuation Current snapshot.
	 * @param string $moveUuid Idempotency key.
	 * @param string $productId Product / item id.
	 * @param string $warehouse Warehouse location id.
	 * @param string $administrationId Tenant scope.
	 *
	 * @return array<string,mixed> Result envelope including cogsBasis.
	 */
	private function processIssue(
		array $move,
		array $valuation,
		string $moveUuid,
		string $productId,
		string $warehouse,
		string $administrationId,
	): array {
		$outboundQty = (float)($move['quantity'] ?? 0);
		if ($outboundQty <= 0) {
			return [
				'processed' => false,
				'message' => 'outbound quantity is non-positive',
			];
		}

		$openLots = $this->openInboundLots(
			productId: $productId,
			warehouse: $warehouse,
			administrationId: $administrationId
		);

		// Subtract historical outbound consumption (excluding the current move).
		$consumedQty = $this->consumedQtyFromHistory(
			productId: $productId,
			warehouse: $warehouse,
			administrationId: $administrationId,
			excludeMoveUuid: $moveUuid
		);
		$availableLots = $this->residualLotsAfterConsumption(
			openLots: $openLots,
			consumedQty: $consumedQty
		);

		$totalAvailable = 0.0;
		foreach ($availableLots as $lot) {
			$totalAvailable += (float)$lot['quantity'];
		}

		if ($totalAvailable + 1e-9 < $outboundQty) {
			$this->logger->warning(
				'FifoValuationService: outbound exceeds available FIFO lots — partial allocation',
				[
					'productId' => $productId,
					'warehouse' => $warehouse,
					'outboundQty' => $outboundQty,
					'totalAvailable' => $totalAvailable,
				]
			);
		}

		// Walk lots oldest-first, deduct, build cogs basis.
		$remaining = $outboundQty;
		$cogsBasis = [];
		$cogsCents = 0;
		$residualLots = [];

		foreach ($availableLots as $lot) {
			$lotQty = (float)$lot['quantity'];
			$lotCost = (float)$lot['unitCost'];
			if ($remaining <= 0) {
				$residualLots[] = $lot;
				continue;
			}

			$take = min($lotQty, $remaining);
			$takeCents = (int)round($take * $lotCost * 100);
			$cogsCents += $takeCents;
			$cogsBasis[] = [
				'lotCost' => $lotCost,
				'quantity' => $take,
			];
			$remaining -= $take;

			$left = ($lotQty - $take);
			if ($left > 0) {
				$residualLots[] = [
					'quantity' => $left,
					'unitCost' => $lotCost,
					'postedAt' => $lot['postedAt'],
				];
			}
		}//end foreach

		$snapshot = $this->snapshotFromLots(remainingLots: $residualLots);

		$valuation['quantity'] = $snapshot['quantity'];
		$valuation['unitCost'] = $snapshot['unitCost'];
		$valuation['totalValue'] = $snapshot['totalValue'];
		$valuation['date'] = (string)($move['postedAt'] ?? ($move['draftedAt'] ?? ''));
		$valuation['lastStockMoveUuid'] = $moveUuid;

		$saved = $this->saveValuation(data: $valuation);

		return [
			'processed' => true,
			'valuation' => $saved,
			'cogsCents' => $cogsCents,
			'cogsBasis' => $cogsBasis,
			'message' => 'issue processed',
		];

	}//end processIssue()

	/**
	 * Compute snapshot (quantity, weighted-avg unitCost, totalValue) from
	 * the remaining open lots. Money discipline: totalValue rounded to
	 * 2 dp via integer cents.
	 *
	 * @param array<int,array<string,mixed>> $remainingLots The lots.
	 *
	 * @return array{quantity: float, unitCost: float, totalValue: float}
	 */
	private function snapshotFromLots(array $remainingLots): array {
		$totalQty = 0.0;
		$totalCents = 0;
		foreach ($remainingLots as $lot) {
			$qty = (float)($lot['quantity'] ?? 0);
			$cost = (float)($lot['unitCost'] ?? 0);
			$totalQty += $qty;
			$totalCents += (int)round($qty * $cost * 100);
		}

		$unitCost = 0.0;
		if ($totalQty > 0) {
			$unitCost = round(($totalCents / 100) / $totalQty, 4);
		}

		return [
			'quantity' => round($totalQty, 2),
			'unitCost' => $unitCost,
			'totalValue' => round(($totalCents / 100), 2),
		];

	}//end snapshotFromLots()

	/**
	 * Subtract historical outbound consumption from open inbound lots,
	 * oldest-first.
	 *
	 * @param array<int,array<string,mixed>> $openLots Inbound lots (oldest-first).
	 * @param float $consumedQty Total qty consumed so far.
	 *
	 * @return array<int,array<string,mixed>> Residual lots with quantity adjusted.
	 */
	private function residualLotsAfterConsumption(array $openLots, float $consumedQty): array {
		if ($consumedQty <= 0) {
			return $openLots;
		}

		$remaining = $consumedQty;
		$residual = [];
		foreach ($openLots as $lot) {
			$qty = (float)($lot['quantity'] ?? 0);
			if ($remaining <= 0) {
				$residual[] = $lot;
				continue;
			}

			if ($qty <= $remaining) {
				$remaining -= $qty;
				continue;
			}

			$residual[] = [
				'quantity' => ($qty - $remaining),
				'unitCost' => $lot['unitCost'],
				'postedAt' => $lot['postedAt'],
			];
			$remaining = 0.0;
		}

		return $residual;
	}//end residualLotsAfterConsumption()

	/**
	 * Load all posted, non-cancelled inbound StockMove records for the
	 * (product, warehouse, administration) tuple, ordered chronologically.
	 *
	 * @param string $productId Product id.
	 * @param string $warehouse Destination location id (the warehouse).
	 * @param string $administrationId Tenant scope.
	 *
	 * @return array<int,array{quantity: float, unitCost: float, postedAt: string}>
	 */
	private function openInboundLots(
		string $productId,
		string $warehouse,
		string $administrationId,
	): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('StockMove')
			->findAll(
				[
					'filters' => [
						'itemId' => $productId,
						'destinationLocationId' => $warehouse,
						'movementType' => 'receipt',
						'lifecycleState' => 'posted',
						'administrationId' => $administrationId,
					],
				]
			);

		$lots = [];
		foreach ($rows as $row) {
			$data = $this->asArray(row: $row);
			$lots[] = [
				'quantity' => (float)($data['quantity'] ?? 0),
				'unitCost' => (float)($data['unitCost'] ?? 0),
				'postedAt' => (string)($data['postedAt'] ?? ($data['draftedAt'] ?? '')),
			];
		}

		usort(
			$lots,
			static fn (array $a, array $b): int => strcmp((string)$a['postedAt'], (string)$b['postedAt'])
		);

		return $lots;
	}//end openInboundLots()

	/**
	 * Sum quantity of posted, non-cancelled outbound StockMove rows for
	 * the same tuple — used to derive historical consumption.
	 *
	 * @param string $productId Product id.
	 * @param string $warehouse Source location id (the warehouse).
	 * @param string $administrationId Tenant scope.
	 * @param string|null $excludeMoveUuid Optional uuid to exclude (the current move).
	 *
	 * @return float Total consumed quantity.
	 */
	private function consumedQtyFromHistory(
		string $productId,
		string $warehouse,
		string $administrationId,
		?string $excludeMoveUuid = null,
	): float {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('StockMove')
			->findAll(
				[
					'filters' => [
						'itemId' => $productId,
						'sourceLocationId' => $warehouse,
						'movementType' => 'issue',
						'lifecycleState' => 'posted',
						'administrationId' => $administrationId,
					],
				]
			);

		$consumed = 0.0;
		foreach ($rows as $row) {
			$data = $this->asArray(row: $row);
			$uuid = (string)($data['uuid'] ?? ($data['@self']['uuid'] ?? ($data['id'] ?? '')));
			if ($excludeMoveUuid !== null && $uuid === $excludeMoveUuid) {
				continue;
			}

			$consumed += (float)($data['quantity'] ?? 0);
		}

		return $consumed;
	}//end consumedQtyFromHistory()

	/**
	 * Locate the active InventoryValuation snapshot for the tuple, or
	 * create one on the fly (FIFO method, status=active).
	 *
	 * @param string $productId Product id.
	 * @param string $warehouse Warehouse location id.
	 * @param string $administrationId Tenant scope.
	 *
	 * @return array<string,mixed> The snapshot record.
	 */
	private function findOrCreateValuation(
		string $productId,
		string $warehouse,
		string $administrationId,
	): array {
		$existing = $this->objectService
			->setRegister($this->register())
			->setSchema('InventoryValuation')
			->findAll(
				[
					'filters' => [
						'productId' => $productId,
						'warehouse' => $warehouse,
						'status' => 'active',
						'administrationId' => $administrationId,
					],
					'limit' => 1,
				]
			);

		if (count($existing) > 0) {
			return $this->asArray(row: $existing[0]);
		}

		return [
			'productId' => $productId,
			'warehouse' => $warehouse,
			'quantity' => 0.0,
			'unitCost' => 0.0,
			'totalValue' => 0.0,
			'valuationMethod' => 'FIFO',
			'date' => '',
			'status' => 'active',
			'administrationId' => $administrationId,
			'pendingCogs' => false,
		];

	}//end findOrCreateValuation()

	/**
	 * Persist the snapshot via OR's ObjectService.
	 *
	 * @param array<string,mixed> $data The snapshot data.
	 *
	 * @return array<string,mixed> Persisted snapshot (with id).
	 */
	private function saveValuation(array $data): array {
		$saved = $this->objectService
			->setRegister($this->register())
			->setSchema('InventoryValuation')
			->saveObject($data);

		// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
		// is_array() test was constant — asArray() is the only path that runs.
		return $this->asArray(row: $saved);
	}//end saveValuation()

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

		throw new RuntimeException('FifoValuationService: unsupported row type from ObjectService');
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
