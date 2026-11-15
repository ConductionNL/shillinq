<?php

/**
 * Contract of MovingAverageValuationService
 *
 * The public surface of MovingAverageValuationService that other components call, so they depend
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
 * Contract of MovingAverageValuationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MovingAverageValuationServiceInterface {

	/**
	 * Process a posted StockMove for an average-valued item.
	 *
	 * Returns a result envelope:
	 * <code>
	 *  [
	 *    'processed'  => bool,            // false on idempotent retry / non-trigger
	 *    'valuation'  => array,           // updated InventoryValuation snapshot
	 *    'cogsCents'  => int,             // COGS amount in integer cents (issue only)
	 *    'message'    => string,
	 *  ]
	 * </code>
	 *
	 * @param array<string,mixed> $move The posted StockMove record.
	 *
	 * @return array<string,mixed> Result envelope.
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-8
	 */
	public function processStockMove(array $move): array;
}//end interface
