<?php

/**
 * Contract of InventoryValuationReportService
 *
 * The public surface of InventoryValuationReportService that other components call, so they depend
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
 * Contract of InventoryValuationReportService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InventoryValuationReportServiceInterface {

	/**
	 * Bucket FIFO residual open lots by age as of a cut-off date.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $asOfDate ISO cut-off; inclusive.
	 * @param string $sku Product SKU.
	 * @param string $warehouse Warehouse location id.
	 *
	 * @return array<string,mixed> { asOfDate, sku, warehouse, buckets:{0-30,31-60,61-90,90+}, totalValue }
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function ageing(string $administrationId, string $asOfDate, string $sku, string $warehouse): array;

	/**
	 * Compute the inventory value as of a cut-off date, per (sku, warehouse).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $asOfDate ISO cut-off (yyyy-mm-dd or full timestamp); inclusive.
	 * @param string|null $sku Optional single-SKU filter.
	 * @param string|null $warehouse Optional single-warehouse filter.
	 *
	 * @return array<string,mixed> { asOfDate, totalValue, totalQuantity, lines:[{sku,warehouse,method,quantity,unitCost,totalValue}] }
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function valuationAsOf(
		string $administrationId,
		string $asOfDate,
		?string $sku = null,
		?string $warehouse = null,
	): array;
}//end interface
