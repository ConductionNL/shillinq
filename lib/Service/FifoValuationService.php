<?php

/**
 * FIFO Valuation Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Imperative FIFO cost layer engine driving {@see CogsPosterService}.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
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
		$key = new StockMoveValuationKey(move: $move);
		// Transfer / manufacture / repack don't drive FIFO valuation directly.
		$refusal = $key->refusal(triggerLabel: 'a FIFO trigger');
		if ($refusal !== null) {
			return $refusal;
		}

		$valuation = $this->findOrCreateValuation(key: $key);
		if ($key->isRetryOf(valuation: $valuation) === true) {
			// Idempotent retry guard per REQ-INV-003 scenario.
			return $this->skipRetry(key: $key, valuation: $valuation);
		}

		if ($key->movementType === 'receipt') {
			return $this->processReceipt(key: $key, valuation: $valuation);
		}

		return $this->processIssue(key: $key, valuation: $valuation);

	}//end processStockMove()

	/**
	 * Log and skip a move the valuation already recorded.
	 *
	 * @param StockMoveValuationKey $key       The move's valuation key.
	 * @param array<string,mixed>   $valuation The active valuation.
	 *
	 * @return array<string,mixed> Result envelope.
	 */
	private function skipRetry(StockMoveValuationKey $key, array $valuation): array {
		$this->logger->info('FifoValuationService: idempotent retry — skipping', $key->logContext());
		return [
			'processed' => false,
			'valuation' => $valuation,
			'cogsCents' => 0,
			'cogsBasis' => [],
			'message' => 'idempotent retry',
		];
	}//end skipRetry()

	/**
	 * Recompute the snapshot from open inbound lots, no COGS to post.
	 *
	 * @param StockMoveValuationKey $key       The receipt's valuation key.
	 * @param array<string,mixed>   $valuation Current snapshot.
	 *
	 * @return array<string,mixed> Result envelope.
	 */
	private function processReceipt(StockMoveValuationKey $key, array $valuation): array {
		$remainingLots = $this->availableLots(key: $key, excludeMoveUuid: null);
		$valuation = $key->stamp(valuation: $this->withSnapshot(valuation: $valuation, remainingLots: $remainingLots));

		return [
			'processed' => true,
			'valuation' => $this->saveValuation(data: $valuation),
			'cogsCents' => 0,
			'cogsBasis' => [],
			'message' => 'receipt processed',
		];

	}//end processReceipt()

	/**
	 * Consume the oldest lots for an issue and post its COGS basis.
	 *
	 * @param StockMoveValuationKey $key       The issue's valuation key.
	 * @param array<string,mixed>   $valuation Current snapshot.
	 *
	 * @return array<string,mixed> Result envelope.
	 */
	private function processIssue(StockMoveValuationKey $key, array $valuation): array {
		$outboundQty = (float)($key->move['quantity'] ?? 0);
		if ($outboundQty <= 0) {
			return [
				'processed' => false,
				'message' => 'outbound quantity is non-positive',
			];
		}

		// Subtract historical outbound consumption (excluding the current move).
		$allocation = new FifoIssueAllocation(outboundQty: $outboundQty);
		$allocation->allocate(availableLots: $this->availableLots(key: $key, excludeMoveUuid: $key->moveUuid));
		$this->warnOnShortfall(key: $key, allocation: $allocation);

		$valuation = $key->stamp(valuation: $this->withSnapshot(valuation: $valuation, remainingLots: $allocation->residualLots));
		return [
			'processed' => true,
			'valuation' => $this->saveValuation(data: $valuation),
			'cogsCents' => $allocation->cogsCents,
			'cogsBasis' => $allocation->cogsBasis,
			'message' => 'issue processed',
		];

	}//end processIssue()

	/**
	 * Warn when an issue asks for more than the available FIFO lots hold.
	 *
	 * @param StockMoveValuationKey $key        The issue's valuation key.
	 * @param FifoIssueAllocation   $allocation The allocation over the available lots.
	 *
	 * @return void
	 */
	private function warnOnShortfall(StockMoveValuationKey $key, FifoIssueAllocation $allocation): void {
		if ($allocation->isShort() === true) {
			$this->logger->warning(
				'FifoValuationService: outbound exceeds available FIFO lots — partial allocation',
				[
					'productId' => $key->productId,
					'warehouse' => $key->warehouse,
					'outboundQty' => $allocation->outboundQty,
					'totalAvailable' => $allocation->totalAvailable,
				]
			);
		}
	}//end warnOnShortfall()

	/**
	 * The open inbound lots left after historical outbound consumption.
	 *
	 * @param StockMoveValuationKey $key             The move's valuation key.
	 * @param string|null           $excludeMoveUuid An issue to leave out of the history.
	 *
	 * @return array<int,array<string,mixed>> The remaining lots, oldest first.
	 */
	private function availableLots(StockMoveValuationKey $key, ?string $excludeMoveUuid): array {
		$openLots = $this->openInboundLots(key: $key);
		$consumedQty = $this->consumedQtyFromHistory(key: $key, excludeMoveUuid: $excludeMoveUuid);

		return $this->residualLotsAfterConsumption(
			openLots: $openLots,
			consumedQty: $consumedQty
		);
	}//end availableLots()

	/**
	 * A valuation with the quantity, unit cost and total value of a lot set.
	 *
	 * @param array<string,mixed>            $valuation     The snapshot.
	 * @param array<int,array<string,mixed>> $remainingLots The lots it holds.
	 *
	 * @return array<string,mixed> The updated snapshot.
	 */
	private function withSnapshot(array $valuation, array $remainingLots): array {
		$snapshot = $this->snapshotFromLots(remainingLots: $remainingLots);
		$valuation['quantity'] = $snapshot['quantity'];
		$valuation['unitCost'] = $snapshot['unitCost'];
		$valuation['totalValue'] = $snapshot['totalValue'];
		return $valuation;
	}//end withSnapshot()

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
	 * @param StockMoveValuationKey $key The move's valuation key (product, warehouse, tenant).
	 *
	 * @return array<int,array{quantity: float, unitCost: float, postedAt: string}>
	 */
	private function openInboundLots(StockMoveValuationKey $key): array {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('StockMove')
			->findAll(
				[
					'filters' => [
						'itemId' => $key->productId,
						'destinationLocationId' => $key->warehouse,
						'movementType' => 'receipt',
						'lifecycleState' => 'posted',
						'administrationId' => $key->administrationId,
					],
				]
			);

		$lots = [];
		foreach ($rows as $row) {
			$data = $this->valuations()->toArray(row: $row);
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
	 * @param StockMoveValuationKey $key             The move's valuation key (product, warehouse, tenant).
	 * @param string|null           $excludeMoveUuid Optional uuid to exclude (the current move).
	 *
	 * @return float Total consumed quantity.
	 */
	private function consumedQtyFromHistory(StockMoveValuationKey $key, ?string $excludeMoveUuid): float {
		$rows = $this->objectService
			->setRegister($this->register())
			->setSchema('StockMove')
			->findAll(
				[
					'filters' => [
						'itemId' => $key->productId,
						'sourceLocationId' => $key->warehouse,
						'movementType' => 'issue',
						'lifecycleState' => 'posted',
						'administrationId' => $key->administrationId,
					],
				]
			);

		$consumed = 0.0;
		foreach ($rows as $row) {
			$data = $this->valuations()->toArray(row: $row);
			$uuid = (string)($data['uuid'] ?? ($data['@self']['uuid'] ?? ($data['id'] ?? '')));
			if ($excludeMoveUuid !== null && $uuid === $excludeMoveUuid) {
				continue;
			}

			$consumed += (float)($data['quantity'] ?? 0);
		}

		return $consumed;
	}//end consumedQtyFromHistory()

	/**
	 * Locate the active InventoryValuation snapshot for the move's product,
	 * warehouse and administration, or create one on the fly (FIFO
	 * method, status=active).
	 *
	 * @param StockMoveValuationKey $key The move's valuation key.
	 *
	 * @return array<string,mixed> The snapshot record.
	 */
	private function findOrCreateValuation(StockMoveValuationKey $key): array {
		return ($this->valuations()->findActive(key: $key, register: $this->register()) ?? $key->newValuation(valuationMethod: 'FIFO'));
	}//end findOrCreateValuation()

	/**
	 * Persist the InventoryValuation snapshot.
	 *
	 * @param array<string,mixed> $data The snapshot.
	 *
	 * @return array<string,mixed> The stored snapshot.
	 */
	private function saveValuation(array $data): array {
		return $this->valuations()->save(data: $data, register: $this->register());
	}//end saveValuation()

	/**
	 * The InventoryValuation store, refusing unsupported rows as this service.
	 *
	 * @return InventoryValuationStore The store.
	 */
	private function valuations(): InventoryValuationStore {
		return new InventoryValuationStore(objectService: $this->objectService, source: 'FifoValuationService');
	}//end valuations()

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
