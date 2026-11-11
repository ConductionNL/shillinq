<?php

/**
 * Stock move valuation key
 *
 * What a posted StockMove means to an inventory valuation service: whether it
 * drives valuation at all (receipt or issue), the product, warehouse and
 * administration whose active InventoryValuation it updates, and its uuid —
 * the idempotency key a valuation records as lastStockMoveUuid.
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
 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A posted StockMove read as the key of the valuation it updates.
 *
 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
 */
final class StockMoveValuationKey {
	/**
	 * The movement type (receipt, issue, ...).
	 *
	 * @var string
	 */
	public readonly string $movementType;

	/**
	 * The product / item id.
	 *
	 * @var string
	 */
	public readonly string $productId;

	/**
	 * The warehouse: the destination of a receipt, the source otherwise.
	 *
	 * @var string
	 */
	public readonly string $warehouse;

	/**
	 * The tenant scope.
	 *
	 * @var string
	 */
	public readonly string $administrationId;

	/**
	 * The move's uuid (uuid, @self.uuid or id).
	 *
	 * @var string
	 */
	public readonly string $moveUuid;

	/**
	 * Read a posted StockMove.
	 *
	 * @param array<string,mixed> $move The posted StockMove record.
	 */
	public function __construct(
		public readonly array $move,
	) {
		$this->movementType = (string)($move['movementType'] ?? '');
		$this->productId = (string)($move['itemId'] ?? '');
		$warehouse = (string)($move['sourceLocationId'] ?? '');
		if ($this->movementType === 'receipt') {
			$warehouse = (string)($move['destinationLocationId'] ?? '');
		}

		$this->warehouse = $warehouse;
		$this->administrationId = (string)($move['administrationId'] ?? '');
		$this->moveUuid = (string)($move['uuid'] ?? ($move['@self']['uuid'] ?? ($move['id'] ?? '')));
	}//end __construct()

	/**
	 * True for the movement types that drive valuation (receipt, issue).
	 *
	 * @return bool Whether the move drives valuation.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
	 */
	public function isTrigger(): bool {
		return in_array($this->movementType, ['receipt', 'issue'], true);
	}//end isTrigger()

	/**
	 * True when product, warehouse, administration and uuid are all known.
	 *
	 * @return bool Whether the key is complete.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
	 */
	public function isComplete(): bool {
		return $this->productId !== '' && $this->warehouse !== '' && $this->administrationId !== '' && $this->moveUuid !== '';
	}//end isComplete()

	/**
	 * The not-processed envelope of a move that does not drive valuation or
	 * misses a field, or null when the move is to be valued.
	 *
	 * @param string $triggerLabel How the service names its trigger, e.g. "a FIFO trigger".
	 *
	 * @return array{processed:false,message:string}|null The refusal, or null.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
	 */
	public function refusal(string $triggerLabel): ?array {
		if ($this->isTrigger() === false) {
			return [
				'processed' => false,
				'message' => 'movementType ' . $this->movementType . ' is not ' . $triggerLabel,
			];
		}

		if ($this->isComplete() === false) {
			return $this->incompleteResult();
		}

		return null;
	}//end refusal()

	/**
	 * The not-processed envelope of an incomplete move.
	 *
	 * @return array{processed:false,message:string}
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
	 */
	public function incompleteResult(): array {
		return [
			'processed' => false,
			'message' => 'StockMove missing itemId / location / administrationId / uuid',
		];
	}//end incompleteResult()

	/**
	 * True when the valuation already recorded this move (idempotent retry).
	 *
	 * @param array<string,mixed> $valuation The active valuation.
	 *
	 * @return bool Whether the move was already applied.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
	 */
	public function isRetryOf(array $valuation): bool {
		return (string)($valuation['lastStockMoveUuid'] ?? '') === $this->moveUuid;
	}//end isRetryOf()

	/**
	 * The log context naming the product, warehouse and move.
	 *
	 * @return array<string,string> productId, warehouse, moveUuid.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
	 */
	public function logContext(): array {
		return [
			'productId' => $this->productId,
			'warehouse' => $this->warehouse,
			'moveUuid' => $this->moveUuid,
		];
	}//end logContext()

	/**
	 * A fresh active valuation for the key's product and warehouse.
	 *
	 * @param string $valuationMethod FIFO or average.
	 *
	 * @return array<string,mixed> The new snapshot.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
	 */
	public function newValuation(string $valuationMethod): array {
		return [
			'productId' => $this->productId,
			'warehouse' => $this->warehouse,
			'quantity' => 0.0,
			'unitCost' => 0.0,
			'totalValue' => 0.0,
			'valuationMethod' => $valuationMethod,
			'date' => '',
			'status' => 'active',
			'administrationId' => $this->administrationId,
			'pendingCogs' => false,
		];
	}//end newValuation()

	/**
	 * Stamp a valuation with the move's date and uuid.
	 *
	 * @param array<string,mixed> $valuation The updated valuation.
	 *
	 * @return array<string,mixed> The stamped valuation.
	 *
	 * @spec openspec/specs/inventory-valuation-fifo-avg/spec.md#req-inv-005-each-item-shall-have-exactly-one-active-inventoryvaluation-per-warehouse
	 */
	public function stamp(array $valuation): array {
		$valuation['date'] = (string)($this->move['postedAt'] ?? ($this->move['draftedAt'] ?? ''));
		$valuation['lastStockMoveUuid'] = $this->moveUuid;
		return $valuation;
	}//end stamp()
}//end class
