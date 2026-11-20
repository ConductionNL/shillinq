<?php

/**
 * The POS transaction a PosStockDecrementListener run works on.
 *
 * @category Listener
 * @package  OCA\Shillinq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Listener;

/**
 * The POS transaction id and administration shared by the steps of one
 * PosStockMovedEvent's processing. Built and used only by
 * PosStockDecrementListener.
 *
 * @spec openspec/changes/inventory-pos-decrement/specs/pos-stock-decrement/spec.md
 */
final class PosSaleRun {
	/**
	 * Constructor.
	 *
	 * @param string $posTxnId         The trimmed POS transaction id.
	 * @param string $administrationId The trimmed administration id.
	 */
	public function __construct(
		public readonly string $posTxnId,
		public readonly string $administrationId,
	) {

	}//end __construct()
}//end class
