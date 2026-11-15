<?php

/**
 * One stock-turnover window.
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
 * A stock position, the window (fromCut, toCut] and the valuation method a
 * turnover report reads it under. Built and read by
 * InventoryValuationReportService.
 *
 * @spec openspec/specs/inventory-accounting-correctness/spec.md
 */
final class StockWindow {

	/**
	 * The stock position.
	 *
	 * @var StockLocation
	 */
	public StockLocation $location;

	/**
	 * Window start (exclusive), a normalised cut-off.
	 *
	 * @var string
	 */
	public string $fromCut = '';

	/**
	 * Window end (inclusive), a normalised cut-off.
	 *
	 * @var string
	 */
	public string $toCut = '';

	/**
	 * 'FIFO' or 'average'.
	 *
	 * @var string
	 */
	public string $method = 'FIFO';
}//end class
