<?php

/**
 * Contract of StockLedgerService
 *
 * The public surface of StockLedgerService that other components call, so they depend
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
 * Contract of StockLedgerService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StockLedgerServiceInterface {

	/**
	 * Recompute the on-hand quantity at a bin location for an SKU from the
	 * StockMove ledger per REQ-SM-005.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $locationId Bin location id.
	 * @param string $sku Product SKU.
	 * @param float $initialStock Optional initial-stock seed (default 0).
	 *
	 * @return float Recomputed on-hand quantity (rounded to 2 decimals).
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	public function quantityForLocation(
		string $administrationId,
		string $locationId,
		string $sku,
		float $initialStock = 0.0,
	): float;

	/**
	 * Sum reserved quantity (draft moves whose source is this location for
	 * this SKU) per REQ-SM-009.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $locationId Bin location id.
	 * @param string $sku Product SKU.
	 *
	 * @return float Reserved quantity (rounded to 2 decimals).
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	public function reservedForLocation(string $administrationId, string $locationId, string $sku): float;

	/**
	 * Build the drill-down trace for the Stock Ledger detail view: every
	 * posted, non-cancelled StockMove touching (sku, location) with a
	 * running cumulative total. Returned rows are ordered chronologically
	 * by postedAt ASC so the operator can audit the balance per REQ-SM-005.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $locationId Bin location id.
	 * @param string $sku Product SKU.
	 *
	 * @return array<int,array<string,mixed>> Trace rows: {movementNumber, postedAt, movementType, sign, quantity, runningTotal}.
	 *
	 * @spec openspec/changes/inventory-stock-movement-ledger/tasks.md#task-9
	 */
	public function traceLocation(string $administrationId, string $locationId, string $sku): array;
}//end interface
