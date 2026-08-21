<?php

/**
 * Stock Move Transitioned Listener
 *
 * Reacts to OpenRegister's ObjectTransitionedEvent on the `StockMove`
 * schema. When a draft StockMove transitions to `posted`, dispatches
 * the move into the valuation engine:
 *
 *   1. Look up the driving `InventoryValuation` for
 *      `(itemId, warehouse, administrationId, status=active)`.
 *      `valuationMethod` decides the engine.
 *   2. FIFO  -> {@see FifoValuationService::processStockMove()}
 *      average -> {@see MovingAverageValuationService::processStockMove()}
 *   3. On an issue move with `cogsCents > 0`, forward to
 *      {@see CogsPosterService::postCogs()} to materialise the
 *      balanced GLTransaction (REQ-INV-007).
 *
 * Fail-soft: any downstream exception is logged but never bubbles up
 * to the StockMove transition itself (the ledger is the source of
 * truth; valuation is a derived view per design.md D2).
 *
 * Idempotency: each engine guards via `lastStockMoveUuid`; this
 * listener trusts that contract and does not re-check.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Service\CogsPosterService;
use OCA\Shillinq\Service\FifoValuationService;
use OCA\Shillinq\Service\MovingAverageValuationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Dispatcher from posted StockMove -> valuation engine + COGS poster.
 *
 * @implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; early-return refactor deferred pending full behavioral
 * verification of each branch.
 *
 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-10
 */
class StockMoveTransitionedListener implements IEventListener {
	/**
	 * Construct the listener with DI dependencies.
	 *
	 * @param FifoValuationService $fifo FIFO engine.
	 * @param MovingAverageValuationService $average Moving-average engine.
	 * @param CogsPosterService $cogs COGS poster.
	 * @param IAppConfig $appConfig App config for register slug.
	 * @param LoggerInterface $logger Logger for fail-soft diagnostics.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly FifoValuationService $fifo,
		private readonly MovingAverageValuationService $average,
		private readonly CogsPosterService $cogs,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Handle an ObjectTransitionedEvent.
	 *
	 * @param Event $event Event from OpenRegister.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-10
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectTransitionedEvent === false) {
			return;
		}

		try {
			if ($this->isStockMoveSchema(schema: (string)$event->getSchema()) === false) {
				return;
			}

			if ($event->getTo() !== 'posted') {
				return;
			}

			$entity = $event->getObject();
			$move = $entity->getObject();
			if (is_array($move) === false) {
				return;
			}

			$movementType = (string)($move['movementType'] ?? '');
			if (in_array($movementType, ['receipt', 'issue'], true) === false) {
				// Transfer, manufacture, repack — out of scope here.
				return;
			}

			$valuation = $this->lookupValuation(move: $move);
			if ($valuation === null) {
				// No driving valuation yet — only fire if the operator already
				// has a snapshot. On a true first receipt the snapshot will be
				// created by the engine on next dispatch via findOrCreateValuation().
				$valuation = ['valuationMethod' => 'FIFO'];
			}

			$method = (string)($valuation['valuationMethod'] ?? 'FIFO');
			$result = match ($method) {
				'average' => $this->average->processStockMove(move: $move),
				default => $this->fifo->processStockMove(move: $move),
			};

			if (((bool)($result['processed'] ?? false)) !== true) {
				return;
			}

			// Only outbound moves drive COGS posting per REQ-INV-007.
			if ($movementType !== 'issue') {
				return;
			}

			$cogsCents = (int)($result['cogsCents'] ?? 0);
			if ($cogsCents <= 0) {
				return;
			}

			$this->cogs->postCogs(
				move: $move,
				valuation: $result['valuation'],
				cogsCents: $cogsCents
			);
		} catch (Throwable $e) {
			// Fail-soft: valuation is a derived view. Never bubble up
			// and block the StockMove transition itself.
			$this->logger->error(
				'StockMoveTransitionedListener: valuation dispatch failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}//end try

	}//end handle()

	/**
	 * Look up the active InventoryValuation snapshot for the move.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return array<string,mixed>|null Snapshot or null.
	 */
	private function lookupValuation(array $move): ?array {
		$movementType = (string)($move['movementType'] ?? '');
		if ($movementType === 'receipt') {
			$warehouse = (string)($move['destinationLocationId'] ?? '');
		} else {
			$warehouse = (string)($move['sourceLocationId'] ?? '');
		}

		$productId = (string)($move['itemId'] ?? '');
		$administrationId = (string)($move['administrationId'] ?? '');

		if ($productId === '' || $warehouse === '' || $administrationId === '') {
			return null;
		}

		try {
			$rows = $this->objectService
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

			if (count($rows) === 0) {
				return null;
			}

			$row = $rows[0];
			if (is_array($row) === true) {
				return $row;
			}

			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$out = $row->jsonSerialize();
				if (is_array($out) === true) {
					return $out;
				}

				return null;
			}

			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$out = $row->getObject();
				if (is_array($out) === true) {
					return $out;
				}

				return null;
			}

			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'StockMoveTransitionedListener: lookupValuation failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}//end try

	}//end lookupValuation()

	/**
	 * Schema-name match for StockMove (plain slug or namespaced).
	 *
	 * @param string $schema Schema identifier.
	 *
	 * @return bool
	 */
	private function isStockMoveSchema(string $schema): bool {
		$normalised = strtolower(trim($schema));
		return ($normalised === 'stockmove' || str_ends_with($normalised, '/stockmove'));
	}//end isStockMoveSchema()

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
