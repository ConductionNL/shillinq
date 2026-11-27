<?php

/**
 * The StockMove transition actions that change InventoryStock.
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
 * A StockMove transition declares its stock step by the class name of a
 * subclass (a kebab-case name such as `commit-stock-reservation` never
 * resolves: the server container reaches shillinq's container only for an
 * `OCA\Shillinq\` id). The step runs through StockReservationGuard; when it
 * does not succeed the transition is refused, by throwing, so stock never
 * silently stays as it was.
 *
 * @spec openspec/specs/inventory-stock-movement-ledger/spec.md
 */
abstract class StockReservationAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param StockReservationGuard $reservations The InventoryStock reservation arithmetic.
	 */
	public function __construct(
		protected readonly StockReservationGuard $reservations,
	) {
	}//end __construct()

	/**
	 * Run the stock step for the move being transitioned.
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
		if ($this->applyTo(move: $move) === false) {
			throw new RuntimeException(
				sprintf(
					'The stock for move %s could not be updated: the location has no stock row for the item, '
					. 'its on-hand does not cover the move, or another operator changed it. Try again.',
					(string)($move['movementNumber'] ?? '')
				)
			);
		}

		return $move;
	}//end execute()

	/**
	 * Apply the step to the move's InventoryStock rows.
	 *
	 * @param array<string,mixed> $move The StockMove.
	 *
	 * @return bool True when the stock was updated.
	 */
	abstract protected function applyTo(array $move): bool;
}//end class
