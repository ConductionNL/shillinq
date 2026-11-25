<?php

/**
 * Releases a StockMove's stock reservation when the move transitions.
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

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Lifecycle\StockReservationGuard;
use RuntimeException;

/**
 * The lifecycle action the StockMove transition declares by this class name
 * (a kebab-case name such as `release-stock-reservation` never resolves: the
 * server container reaches shillinq's container only for an `OCA\Shillinq\` id).
 * It runs StockReservationGuard::releaseReservation() and refuses the transition,
 * by throwing, when that does not succeed, so stock never silently stays as
 * it was.
 *
 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
 */
final class ReleaseStockReservationAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param StockReservationGuard $reservations The InventoryStock reservation arithmetic.
	 */
	public function __construct(
		private readonly StockReservationGuard $reservations,
	) {
	}//end __construct()

	/**
	 * Run the reservation step for the move being transitioned.
	 *
	 * @param LifecycleActionContext $context The transition: the move is its objectData.
	 *
	 * @return array<string,mixed> The move, unchanged.
	 *
	 * @throws RuntimeException When the stock could not be updated.
	 *
	 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
	 */
	public function execute(LifecycleActionContext $context): array {
		$move = $context->objectData;
		if ($this->reservations->releaseReservation(move: $move) === false) {
			throw new RuntimeException(
				sprintf(
					'The stock for move %s could not be updated: the location has no stock row for the item, its on-hand does not cover the move, or another operator changed it. Try again.',
					(string)($move['movementNumber'] ?? '')
				)
			);
		}

		return $move;
	}//end execute()
}//end class
