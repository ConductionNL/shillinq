<?php

/**
 * Moving-Average Valuation Service
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
 * Imperative moving-average engine driving {@see CogsPosterService}.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ElseExpression)         Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
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
		$movementType = (string)($move['movementType'] ?? '');
		if (in_array($movementType, ['receipt', 'issue'], true) === false) {
			return [
				'processed' => false,
				'message' => 'movementType ' . $movementType . ' is not an average trigger',
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

		if ((string)($valuation['lastStockMoveUuid'] ?? '') === $moveUuid) {
			$this->logger->info(
				'MovingAverageValuationService: idempotent retry — skipping',
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
				'message' => 'idempotent retry',
			];
		}

		$rcvQty = (float)($move['quantity'] ?? 0);
		$rcvCost = (float)($move['unitCost'] ?? 0);
		$curQty = (float)($valuation['quantity'] ?? 0);
		$curCost = (float)($valuation['unitCost'] ?? 0);

		if ($movementType === 'receipt') {
			$newQty = ($curQty + $rcvQty);
			// New unitCost = (cur_qty * cur_cost + rcv_qty * rcv_cost) / new_qty,
			// rounded to 4 dp per design.md D3.
			$newCost = 0.0;
			if ($newQty > 0) {
				$newCost = round(((($curQty * $curCost) + ($rcvQty * $rcvCost)) / $newQty), 4);
			}

			// TotalValue = newQty * newCost rounded to 2 dp via integer cents.
			$totalCents = (int)round(($newQty * $newCost) * 100);
			$valuation['quantity'] = round($newQty, 2);
			$valuation['unitCost'] = $newCost;
			$valuation['totalValue'] = round(($totalCents / 100), 2);
			$valuation['date'] = (string)($move['postedAt'] ?? ($move['draftedAt'] ?? ''));
			$valuation['lastStockMoveUuid'] = $moveUuid;

			$saved = $this->saveValuation(data: $valuation);

			return [
				'processed' => true,
				'valuation' => $saved,
				'cogsCents' => 0,
				'message' => 'receipt processed (moving-average recalculated)',
			];
		}//end if

		// Issue: COGS at current unitCost; quantity decremented; cost retained.
		$cogsCents = (int)round(($rcvQty * $curCost) * 100);
		$newQty = ($curQty - $rcvQty);
		if ($newQty < 0) {
			$this->logger->warning(
				'MovingAverageValuationService: outbound exceeds current quantity — clamped to zero',
				[
					'productId' => $productId,
					'warehouse' => $warehouse,
					'curQty' => $curQty,
					'outboundQty' => $rcvQty,
				]
			);
			$newQty = 0.0;
		}

		$totalCents = (int)round(($newQty * $curCost) * 100);
		$valuation['quantity'] = round($newQty, 2);
		$valuation['unitCost'] = $curCost;
		$valuation['totalValue'] = round(($totalCents / 100), 2);
		$valuation['date'] = (string)($move['postedAt'] ?? ($move['draftedAt'] ?? ''));
		$valuation['lastStockMoveUuid'] = $moveUuid;

		$saved = $this->saveValuation(data: $valuation);

		return [
			'processed' => true,
			'valuation' => $saved,
			'cogsCents' => $cogsCents,
			'message' => 'issue processed (COGS at current average)',
		];

	}//end processStockMove()

	/**
	 * Locate the active InventoryValuation snapshot for the tuple, or
	 * create one on the fly (average method, status=active).
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
			'valuationMethod' => 'average',
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

		throw new RuntimeException('MovingAverageValuationService: unsupported row type from ObjectService');
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
