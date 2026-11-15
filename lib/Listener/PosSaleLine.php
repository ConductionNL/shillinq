<?php

/**
 * One sold line of a POS transaction, as PosStockDecrementListener reads and audits it.
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
 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

/**
 * One sold POS line: its transaction, its position in the event, the
 * product reference and quantity, and — once it is known not to be issued —
 * the reason it is audited.
 *
 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
 */
final class PosSaleLine {
	/**
	 * Constructor.
	 *
	 * @param string $posTxnId         The POS transaction id.
	 * @param string $administrationId The administration id.
	 * @param int    $lineIndex        The line's ordinal index in the event.
	 * @param string $productRef       The (possibly empty) product reference.
	 * @param float  $quantity         The sold quantity.
	 * @param string $reason           Why the line is audited ('' while it is not):
	 *                                 'no_product_ref' | 'no_inventory_stock' | 'unsellable_lot'.
	 */
	public function __construct(
		public readonly string $posTxnId,
		public readonly string $administrationId,
		public readonly int $lineIndex,
		public readonly string $productRef,
		public readonly float $quantity,
		public readonly string $reason = '',
	) {

	}//end __construct()

	/**
	 * This line, audited for the given reason.
	 *
	 * @param string $reason The audit reason.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
	 */
	public function because(string $reason): self {
		return new self(
			posTxnId: $this->posTxnId,
			administrationId: $this->administrationId,
			lineIndex: $this->lineIndex,
			productRef: $this->productRef,
			quantity: $this->quantity,
			reason: $reason,
		);

	}//end because()
}//end class
