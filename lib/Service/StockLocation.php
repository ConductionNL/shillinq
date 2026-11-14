<?php

/**
 * One stock position in one administration.
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
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A SKU in a warehouse of an administration; an empty SKU or warehouse means
 * "any" where a report filters on it. Built and read by
 * InventoryValuationReportService.
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
final class StockLocation {

	/**
	 * Tenant scope.
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * Product SKU.
	 *
	 * @var string
	 */
	public string $sku = '';

	/**
	 * Warehouse location id.
	 *
	 * @var string
	 */
	public string $warehouse = '';
}//end class
