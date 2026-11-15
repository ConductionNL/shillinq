<?php

/**
 * Contract of LotSellabilityGuard
 *
 * The public surface of LotSellabilityGuard that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of LotSellabilityGuard.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LotSellabilityGuardInterface {

	/**
	 * Evaluate the sellability of a set of InventoryLot rows against a
	 * required quantity.
	 *
	 * A lot is sellable iff `lotStatus === 'active'` AND (`expiryDate` is
	 * null/empty OR `expiryDate >= today`). The line is satisfiable iff the
	 * summed available quantity of the sellable lots is greater than or equal
	 * to the required quantity — FEFO preference over hard-failing: as long as
	 * sellable lots can cover the line, quarantined/expired siblings do not
	 * block it (REQ-BLK-002). It fails closed only when no combination of
	 * sellable lots can satisfy the line (REQ-BLK-001).
	 *
	 * @param array<int,array<string,mixed>> $lots Candidate InventoryLot rows for the line's product.
	 * @param float $requiredQuantity Quantity the line needs to ship (> 0).
	 * @param string $today Today's date as `Y-m-d` (injected for testability).
	 *
	 * @return array<string,mixed> Verdict:
	 *                             {sellable: bool, availableSellable: float, shortfall: float,
	 *                             sellableLotIds: list<string>,
	 *                             offendingLots: list<array{lotId:string, lotNumber:string,
	 *                             lotStatus:string, expiryDate:?string, reason:string, reasonNl:string}>}.
	 *
	 * @spec openspec/specs/block-unsellable-stock-dispatch/spec.md
	 */
	public function evaluate(array $lots, float $requiredQuantity, string $today): array;
}//end interface
