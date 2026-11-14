<?php

/**
 * Delivery issue line
 *
 * One line of a Delivery being issued: its position, the row itself, the sku
 * and quantity it ships, and what the issue resolves for it (reference URI,
 * stock rows, lot verdict, source location, draft time).
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
 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A Delivery line on its way to an issue StockMove.
 *
 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
 */
final class DeliveryIssueLine {
	/**
	 * The line row, or null when the entry is not an array.
	 *
	 * @var array<string,mixed>|null
	 */
	public readonly ?array $row;

	/**
	 * The product SKU (line.productReference, trimmed).
	 *
	 * @var string
	 */
	public readonly string $sku;

	/**
	 * The quantity shipped.
	 *
	 * @var float
	 */
	public readonly float $quantity;

	/**
	 * The idempotency-carrying reference URI.
	 *
	 * @var string
	 */
	public string $referenceUri = '';

	/**
	 * The InventoryStock rows for the sku.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $stockRows = [];

	/**
	 * The lot sellability verdict, when the product is lot-controlled.
	 *
	 * @var array<string,mixed>
	 */
	public array $verdict = [];

	/**
	 * The resolved source warehouse.
	 *
	 * @var string
	 */
	public string $locationId = '';

	/**
	 * When the draft StockMove was drafted.
	 *
	 * @var string
	 */
	public string $draftedAt = '';

	/**
	 * Read one Delivery line.
	 *
	 * @param int   $index The line's ordinal index in Delivery.lines.
	 * @param mixed $line  The line entry.
	 */
	public function __construct(
		public readonly int $index,
		mixed $line,
	) {
		$row = null;
		if (is_array($line) === true) {
			$row = $line;
		}

		$this->row = $row;
		$this->sku = trim((string)($row['productReference'] ?? ''));
		$this->quantity = (float)($row['quantityShipped'] ?? 0);
	}//end __construct()

	/**
	 * True when the entry is a line with a sku and a positive quantity.
	 *
	 * @return bool Whether the line ships anything.
	 *
	 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
	 */
	public function isShippable(): bool {
		return $this->row !== null && $this->sku !== '' && $this->quantity > 0;
	}//end isShippable()
}//end class
