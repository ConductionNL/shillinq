<?php

/**
 * Releases a StockMove's stock when the move transitions.
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
 * Cancelling a draft StockMove releases what it reserved; on-hand does not change.
 *
 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
 */
final class ReleaseStockReservationAction extends StockReservationAction {

	/**
	 * Run StockReservationGuard::releaseReservation() for the move.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return bool True when the stock was updated.
	 *
	 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
	 */
	protected function applyTo(array $move): bool {
		return $this->reservations->releaseReservation(move: $move);
	}//end applyTo()
}//end class
