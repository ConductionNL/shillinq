<?php

/**
 * Contract of FiledReturnDriftCheck
 *
 * The public surface of FiledReturnDriftCheck that other components call, so they depend
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
 * Contract of FiledReturnDriftCheck.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FiledReturnDriftCheckInterface {

	/**
	 * Check the filed returns of one fiscal year of an administration.
	 *
	 * One return that cannot be checked is logged and the others still are.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fiscalYear       The fiscal year.
	 *
	 * @return array{checked:int,corrections:int} How many returns were checked and how many corrections were written.
	 *
	 * @spec openspec/changes/archive/2026-11-24-tax-vat-position-and-suppletie/tasks.md#task-2.3
	 */
	public function checkFiscalYear(string $administrationId, int $fiscalYear): array;
}//end interface
