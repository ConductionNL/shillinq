<?php

/**
 * Contract of BegrotingswijzigingStacker
 *
 * The public surface of BegrotingswijzigingStacker that other components call, so they depend
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
 * Contract of BegrotingswijzigingStacker.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BegrotingswijzigingStackerInterface {

	/**
	 * Return the effective authorized lasten for a single taakveldCode.
	 *
	 * Convenience wrapper used by the budget-overrun precondition: returns the
	 * vastgestelde basis lasten plus all vastgestelde wijziging deltas for one
	 * taakveld, in euro. Returns 0.0 when the taakveld is unknown.
	 *
	 * @param string $taskFieldCode The taakveld to look up.
	 * @param array<int,array<string,mixed>> $basisTaskFields The vastgestelde basis Taakveld rows.
	 * @param array<int,array<string,mixed>> $wijzigingen The Begrotingswijziging rows.
	 *
	 * @return float The effective authorized lasten in euro.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-27
	 */
	public function authorizedLasten(string $taskFieldCode, array $basisTaskFields, array $wijzigingen): float;
}//end interface
