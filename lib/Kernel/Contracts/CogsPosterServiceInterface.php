<?php

/**
 * Contract of CogsPosterService
 *
 * The public surface of CogsPosterService that other components call, so they depend
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
 * Contract of CogsPosterService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CogsPosterServiceInterface {

	/**
	 * Post one balanced GLTransaction for an outbound StockMove.
	 *
	 * @param array<string,mixed> $move The outbound StockMove (movementType=issue).
	 * @param array<string,mixed> $valuation The driving InventoryValuation snapshot.
	 * @param int $cogsCents Total COGS amount in integer cents (from FIFO/avg).
	 *
	 * @return array<string,mixed> Result envelope with 'posted' bool, 'transaction', 'valuation'.
	 *
	 * @spec openspec/changes/inventory-valuation-fifo-avg/tasks.md#task-9
	 */
	public function postCogs(array $move, array $valuation, int $cogsCents): array;
}//end interface
