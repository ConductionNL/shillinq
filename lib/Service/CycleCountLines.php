<?php

/**
 * Cycle count lines
 *
 * Builds the InventoryCycleCountLine payloads of a snapshot and holds the
 * integer-cent arithmetic (multipleOf 0.01) shared by snapshot and variance
 * posting in CycleCountService.
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
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Stateless snapshot-line and cent helpers for CycleCountService.
 *
 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
 */
final class CycleCountLines {
	/**
	 * The counted-side fields of a freshly snapshotted line, all still empty,
	 * in payload order.
	 *
	 * @var array<string,mixed>
	 */
	private const UNCOUNTED_LINE_FIELDS = [
		'countedValue' => null,
		'quantityVariance' => null,
		'valueVariance' => null,
		'requiresReason' => false,
		'reasonCode' => null,
		'notes' => null,
		'adjustmentStockMoveId' => null,
	];

	/**
	 * The stock rows a line can be made for: arrays with a sku and a location.
	 *
	 * @param array<int,mixed> $stockRows In-scope stock rows.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function countable(array $stockRows): array {
		$countable = [];
		foreach ($stockRows as $stock) {
			if ($this->isCountable(stock: $stock) === true) {
				$countable[] = $stock;
			}
		}

		return $countable;
	}//end countable()

	/**
	 * The line payload for one stock row (without its line id): the expected
	 * quantity and value from the snapshot, every counted field still empty.
	 *
	 * @param array<string,mixed> $stock A countable stock row.
	 * @param CycleCountScope     $scope The count being snapshotted.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function forStock(array $stock, CycleCountScope $scope): array {
		$unitCost = $this->numericOrZero(value: $stock['unitCost'] ?? null);
		$expectedQ = $this->numericOrZero(value: $stock['quantity'] ?? null);
		return [
			'countId' => $scope->countId,
			'sku' => (string)$stock['sku'],
			'productName' => $this->productName(stock: $stock),
			'locationId' => (string)$stock['locationId'],
			'expectedQuantity' => $expectedQ,
			'countedQuantity' => null,
			'unitCost' => $unitCost,
			'expectedValue' => $this->expectedValue(quantity: $expectedQ, unitCost: $unitCost),
		] + self::UNCOUNTED_LINE_FIELDS + ['administrationId' => $scope->administrationId];
	}//end forStock()

	/**
	 * Convert a money/quantity value to integer cents (multipleOf 0.01).
	 *
	 * @param mixed $value Schema number (float or int).
	 *
	 * @return int
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function cents(mixed $value): int {
		if (is_int($value) === true) {
			return ($value * 100);
		}

		return (int)round(((float)$value) * 100);
	}//end cents()

	/**
	 * Convert integer cents back to a 2-decimal float.
	 *
	 * @param int $cents Integer cents.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function fromCents(int $cents): float {
		return ((float)$cents / 100.0);
	}//end fromCents()

	/**
	 * Coerce a schema value to a float or return null when missing / non-numeric.
	 *
	 * @param mixed $value Schema value.
	 *
	 * @return float|null
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function numericOrNull(mixed $value): ?float {
		// Ints, floats and numeric strings are exactly what is_numeric() accepts.
		if (is_numeric($value) === false) {
			return null;
		}

		return (float)$value;
	}//end numericOrNull()

	/**
	 * Coerce a schema value to a float, returning 0.0 when missing.
	 *
	 * @param mixed $value Schema value.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/inventory-cycle-count/tasks.md#task-10
	 */
	public function numericOrZero(mixed $value): float {
		$coerced = $this->numericOrNull(value: $value);
		if ($coerced === null) {
			return 0.0;
		}

		return $coerced;
	}//end numericOrZero()

	/**
	 * Whether a stock row is an array carrying a non-empty sku and location.
	 *
	 * @param mixed $stock One stock row.
	 *
	 * @return bool
	 */
	private function isCountable(mixed $stock): bool {
		return is_array($stock) === true
			&& (string)($stock['sku'] ?? '') !== ''
			&& (string)($stock['locationId'] ?? '') !== '';
	}//end isCountable()

	/**
	 * The stock row's product name as a string, or null when it has none.
	 *
	 * @param array<string,mixed> $stock A stock row.
	 *
	 * @return string|null
	 */
	private function productName(array $stock): ?string {
		if (isset($stock['productName']) === true) {
			return (string)$stock['productName'];
		}

		return null;
	}//end productName()

	/**
	 * Expected value of a snapshot line: quantity x unit cost in integer cents.
	 *
	 * @param float $quantity The expected quantity.
	 * @param float $unitCost The unit cost.
	 *
	 * @return float
	 */
	private function expectedValue(float $quantity, float $unitCost): float {
		$expectedValCts = (int)round(($this->cents(value: $quantity) * $this->cents(value: $unitCost)) / 100);
		return $this->fromCents(cents: $expectedValCts);
	}//end expectedValue()
}//end class
