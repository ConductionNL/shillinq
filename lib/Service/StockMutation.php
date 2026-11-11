<?php

/**
 * Shillinq StockMutation
 *
 * The change a scan operation makes to the on-hand stock of one location:
 * the location and the signed quantity. InventoryScanService applies it to
 * the InventoryStock record of the operation's SKU.
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
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A signed stock change at one location.
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
 */
final class StockMutation {
	/**
	 * Hold the mutation.
	 *
	 * @param string $location Location code.
	 * @param float $delta Signed quantity change.
	 */
	public function __construct(
		public readonly string $location,
		public readonly float $delta,
	) {
	}//end __construct()
}//end class
