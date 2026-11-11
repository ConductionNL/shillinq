<?php

/**
 * Moving-Average Valuation Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * REQ-INV-004 imperative weighted-moving-average engine for
 * InventoryValuation.
 *
 * Listens (via {@see \OCA\Shillinq\Listener\StockMoveTransitionedListener})
 * to the `post` transition of every `StockMove` record whose driving
 * `InventoryValuation` is set to `valuationMethod = average`. Two
 * branches:
 *
 *   - **receipt** (`movementType: receipt`): recalculates the running
 *     weighted average per design.md D3:
 *       new_unitCost = (cur_qty * cur_cost + rcv_qty * rcv_cost) /
 *                      (cur_qty + rcv_qty)
 *     Unit cost rounded to 4 decimal places, totalValue rounded to 2.
 *
 *   - **issue** (`movementType: issue`): COGS posts at the current
 *     `unitCost` (no lot traversal); snapshot quantity decremented;
 *     `unitCost` retained (per design.md D3 — moving-average outbound
 *     uses current average).
 *
 * Idempotency: `lastStockMoveUuid` blocks duplicate processing on
 * retry per REQ-INV-003 idempotency scenario applied to the average
 * service too.
 *
 * No persistence beyond the snapshot itself. All reads via the real OR
 * ObjectService API (find / findAll / saveObject) per ADR-022.
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
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Imperative moving-average engine driving {@see CogsPosterService}.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
 */
class MovingAverageValuationService {
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
	 * Process a posted StockMove for an average-valued item.
	 *
	 * Returns a result envelope:
	 * <code>
	 *  [
	 *    'processed'  => bool,            // false on idempotent retry / non-trigger
	 *    'valuation'  => array,           // updated InventoryValuation snapshot
	 *    'cogsCents'  => int,             // COGS amount in integer cents (issue only)
	 *    'message'    => string,
	 *  ]
	 * </code>
	 *
	 * @param array<string,mixed> $move The posted StockMove record.
	 *
	 * @return array<string,mixed> Result envelope.
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
	 */
	public function processStockMove(array $move): array {
		$key = new StockMoveValuationKey(move: $move);
		$refusal = $key->refusal(triggerLabel: 'an average trigger');
		if ($refusal !== null) {
			return $refusal;
		}

		$valuation = $this->findOrCreateValuation(key: $key);
		if ($key->isRetryOf(valuation: $valuation) === true) {
			$this->logger->info('MovingAverageValuationService: idempotent retry — skipping', $key->logContext());
			return [
				'processed' => false,
				'valuation' => $valuation,
				'cogsCents' => 0,
				'message' => 'idempotent retry',
			];
		}

		if ($key->movementType === 'receipt') {
			return $this->processReceipt(key: $key, valuation: $valuation);
		}

		return $this->processIssue(key: $key, valuation: $valuation);

	}//end processStockMove()

	/**
	 * Recalculate the moving average for a receipt.
	 *
	 * New unitCost = (cur_qty * cur_cost + rcv_qty * rcv_cost) / new_qty,
	 * rounded to 4 dp per design.md D3; totalValue = newQty * newCost rounded
	 * to 2 dp via integer cents.
	 *
	 * @param StockMoveValuationKey $key       The receipt's valuation key.
	 * @param array<string,mixed>   $valuation Current snapshot.
	 *
	 * @return array<string,mixed> Result envelope.
	 */
	private function processReceipt(StockMoveValuationKey $key, array $valuation): array {
		$rcvQty = (float)($key->move['quantity'] ?? 0);
		$rcvCost = (float)($key->move['unitCost'] ?? 0);
		$curQty = (float)($valuation['quantity'] ?? 0);
		$curCost = (float)($valuation['unitCost'] ?? 0);

		$newQty = ($curQty + $rcvQty);
		$newCost = 0.0;
		if ($newQty > 0) {
			$newCost = round(((($curQty * $curCost) + ($rcvQty * $rcvCost)) / $newQty), 4);
		}

		$position = new AveragePosition(quantity: $newQty, unitCost: $newCost);
		return [
			'processed' => true,
			'valuation' => $this->saveValuation(data: $key->stamp(valuation: $position->applyTo(valuation: $valuation))),
			'cogsCents' => 0,
			'message' => 'receipt processed (moving-average recalculated)',
		];
	}//end processReceipt()

	/**
	 * Book an issue: COGS at the current unitCost; quantity decremented (and
	 * clamped at zero); cost retained.
	 *
	 * @param StockMoveValuationKey $key       The issue's valuation key.
	 * @param array<string,mixed>   $valuation Current snapshot.
	 *
	 * @return array<string,mixed> Result envelope.
	 */
	private function processIssue(StockMoveValuationKey $key, array $valuation): array {
		$curCost = (float)($valuation['unitCost'] ?? 0);
		$cogsCents = (int)round(((float)($key->move['quantity'] ?? 0) * $curCost) * 100);
		$position = new AveragePosition(quantity: $this->remainingQuantity(key: $key, valuation: $valuation), unitCost: $curCost);

		return [
			'processed' => true,
			'valuation' => $this->saveValuation(data: $key->stamp(valuation: $position->applyTo(valuation: $valuation))),
			'cogsCents' => $cogsCents,
			'message' => 'issue processed (COGS at current average)',
		];

	}//end processIssue()

	/**
	 * The quantity left after an issue, clamped to zero (with a warning)
	 * when the issue exceeds the current quantity.
	 *
	 * @param StockMoveValuationKey $key       The issue's valuation key.
	 * @param array<string,mixed>   $valuation Current snapshot.
	 *
	 * @return float The remaining quantity.
	 */
	private function remainingQuantity(StockMoveValuationKey $key, array $valuation): float {
		$outboundQty = (float)($key->move['quantity'] ?? 0);
		$curQty = (float)($valuation['quantity'] ?? 0);
		$newQty = ($curQty - $outboundQty);
		if ($newQty < 0) {
			$this->logger->warning(
				'MovingAverageValuationService: outbound exceeds current quantity — clamped to zero',
				[
					'productId' => $key->productId,
					'warehouse' => $key->warehouse,
					'curQty' => $curQty,
					'outboundQty' => $outboundQty,
				]
			);
			$newQty = 0.0;
		}

		return $newQty;
	}//end remainingQuantity()

	/**
	 * Locate the active InventoryValuation snapshot for the move's product,
	 * warehouse and administration, or create one on the fly (average
	 * method, status=active).
	 *
	 * @param StockMoveValuationKey $key The move's valuation key.
	 *
	 * @return array<string,mixed> The snapshot record.
	 */
	private function findOrCreateValuation(StockMoveValuationKey $key): array {
		return ($this->valuations()->findActive(key: $key, register: $this->register()) ?? $key->newValuation(valuationMethod: 'average'));
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
		return new InventoryValuationStore(objectService: $this->objectService, source: 'MovingAverageValuationService');
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
