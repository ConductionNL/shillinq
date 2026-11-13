<?php

/**
 * Contract of SoftCloseExecutor
 *
 * The public surface of SoftCloseExecutor that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of SoftCloseExecutor.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SoftCloseExecutorInterface {

	/**
	 * Execute the nightly soft-close for a single administratie + period (REQ-CLS-002).
	 *
	 * @param string $administrationId The administration the run targets.
	 * @param string $periodId The yyyy-mm business period identifier.
	 * @param DateTimeImmutable $asOf The run timestamp (typically 'now').
	 *
	 * @return array{
	 *   status: string,
	 *   administrationId: string,
	 *   periodId: string,
	 *   postingCount: int,
	 *   accrualPostings: int,
	 *   fxPostings: int,
	 *   revenuePostings: int,
	 *   leasePostings: int,
	 *   intercompanyMatches: int,
	 *   trialBalanceComplete: bool,
	 *   completedAt: string,
	 *   alerts: array<int,array<string,mixed>>
	 * } The structured run report.
	 *
	 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-20
	 */
	public function execute(string $administrationId, string $periodId, DateTimeImmutable $asOf): array;
}//end interface
