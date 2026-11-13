<?php

/**
 * Stock Move Transitioned Listener
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

use OCA\OpenRegister\Event\ObjectTransitionedEvent;
use OCA\Shillinq\Kernel\AppIdentity;
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
			$move = $this->postedStockMove(event: $event);
			if ($move !== null) {
				$this->valuate(move: $move);
			}
		} catch (Throwable $e) {
			// Fail-soft: valuation is a derived view. Never bubble up
			// and block the StockMove transition itself.
			$this->logger->error(
				'StockMoveTransitionedListener: valuation dispatch failed (fail-soft)',
				['exception' => $e->getMessage()]
			);
		}

	}//end handle()

	/**
	 * The StockMove payload when the event posts a receipt or an issue,
	 * else null.
	 *
	 * @param ObjectTransitionedEvent $event The transition.
	 *
	 * @return array<string,mixed>|null The StockMove, or null.
	 */
	private function postedStockMove(ObjectTransitionedEvent $event): array|null {
		if ($this->isStockMoveSchema(schema: (string)$event->getSchema()) === false || $event->getTo() !== 'posted') {
			return null;
		}

		$move = $event->getObject()->getObject();
		if (is_array($move) === false) {
			return null;
		}

		// Transfer, manufacture, repack — out of scope here.
		if (in_array((string)($move['movementType'] ?? ''), ['receipt', 'issue'], true) === false) {
			return null;
		}

		return $move;
	}//end postedStockMove()

	/**
	 * Run the valuation engine for the move and, for a processed issue with
	 * a positive cost, post the COGS.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return void
	 */
	private function valuate(array $move): void {
		$result = $this->processWithMethod(move: $move);
		if (((bool)($result['processed'] ?? false)) !== true) {
			return;
		}

		// Only outbound moves drive COGS posting per REQ-INV-007.
		$cogsCents = (int)($result['cogsCents'] ?? 0);
		if ((string)($move['movementType'] ?? '') !== 'issue' || $cogsCents <= 0) {
			return;
		}

		$this->cogs->postCogs(
			move: $move,
			valuation: $result['valuation'],
			cogsCents: $cogsCents
		);
	}//end valuate()

	/**
	 * Process the move with the engine of its active valuation method.
	 *
	 * No driving valuation yet means FIFO: on a true first receipt the
	 * snapshot is created by the engine on next dispatch via
	 * findOrCreateValuation().
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return array<string,mixed> The engine result.
	 */
	private function processWithMethod(array $move): array {
		$valuation = $this->lookupValuation(move: $move);
		$method = (string)($valuation['valuationMethod'] ?? 'FIFO');

		return match ($method) {
			'average' => $this->average->processStockMove(move: $move),
			default => $this->fifo->processStockMove(move: $move),
		};
	}//end processWithMethod()

	/**
	 * Look up the active InventoryValuation snapshot for the move.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return array<string,mixed>|null Snapshot or null.
	 */
	private function lookupValuation(array $move): array|null {
		$filters = $this->valuationFilters(move: $move);
		if ($filters === null) {
			return null;
		}

		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema('InventoryValuation')
				->findAll(['filters' => $filters, 'limit' => 1]);

			return $this->firstRow(rows: $rows);
		} catch (Throwable $e) {
			$this->logger->warning(
				'StockMoveTransitionedListener: lookupValuation failed',
				['exception' => $e->getMessage()]
			);
			return null;
		}

	}//end lookupValuation()

	/**
	 * The snapshot filters for the move: product, the warehouse the move
	 * books on (destination for a receipt, source otherwise), active status
	 * and administration — or null when any key is missing.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return array<string,string>|null The filters, or null.
	 */
	private function valuationFilters(array $move): array|null {
		$productId = (string)($move['itemId'] ?? '');
		$warehouse = $this->warehouse(move: $move);
		$administrationId = (string)($move['administrationId'] ?? '');
		if ($productId === '' || $warehouse === '' || $administrationId === '') {
			return null;
		}

		return [
			'productId' => $productId,
			'warehouse' => $warehouse,
			'status' => 'active',
			'administrationId' => $administrationId,
		];
	}//end valuationFilters()

	/**
	 * The warehouse a move books on: its destination for a receipt, its
	 * source otherwise.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return string The location id, or ''.
	 */
	private function warehouse(array $move): string {
		if ((string)($move['movementType'] ?? '') === 'receipt') {
			return (string)($move['destinationLocationId'] ?? '');
		}

		return (string)($move['sourceLocationId'] ?? '');
	}//end warehouse()

	/**
	 * The first lookup row as a plain array, or null.
	 *
	 * @param array<int,mixed> $rows The findAll() rows.
	 *
	 * @return array<string,mixed>|null The snapshot, or null.
	 */
	private function firstRow(array $rows): array|null {
		if (count($rows) === 0) {
			return null;
		}

		return $this->rowArray(row: $rows[0]);
	}//end firstRow()

	/**
	 * One lookup row as a plain array: an array as is, an entity's serialised
	 * or object payload when that is an array, else null.
	 *
	 * @param mixed $row One findAll() row.
	 *
	 * @return array<string,mixed>|null The snapshot, or null.
	 */
	private function rowArray(mixed $row): array|null {
		if (is_object($row) === false) {
			return self::arrayOrNull(value: $row);
		}

		if (method_exists($row, 'jsonSerialize') === true) {
			return self::arrayOrNull(value: $row->jsonSerialize());
		}

		if (method_exists($row, 'getObject') === true) {
			return self::arrayOrNull(value: $row->getObject());
		}

		return null;
	}//end rowArray()

	/**
	 * The value when it is an array, else null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<string,mixed>|null The array, or null.
	 */
	private static function arrayOrNull(mixed $value): array|null {
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end arrayOrNull()

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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
