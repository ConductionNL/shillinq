<?php

/**
 * Commits a StockMove's stock when the move transitions.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

/**
 * Posting a StockMove takes its quantity from the source and adds it to the destination, releasing what the move reserved.
 *
 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
 */
final class CommitStockReservationAction extends StockReservationAction {

	/**
	 * Run StockReservationGuard::commitReservation() for the move.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return bool True when the stock was updated.
	 *
	 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
	 */
	protected function applyTo(array $move): bool {
		return $this->reservations->commitReservation(move: $move);
	}//end applyTo()
}//end class
