<?php

/**
 * Contract of TrialBalanceService
 *
 * The public surface of TrialBalanceService that other components call, so they depend
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
 * Contract of TrialBalanceService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TrialBalanceServiceInterface {

	/**
	 * Compute the trial balance for one administration + period (REQ-TB-008).
	 *
	 * Returns a sorted list of per-account rows plus aggregate totals. Each row
	 * carries periodId, accountNumber, accountName, accountType, openingBalance,
	 * debitMovement, creditMovement, closingBalance, currency, parentAccountNumber.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-TB-016).
	 * @param string $periodId Fiscal period to report (REQ-TB-004).
	 * @param array<string,mixed> $filters Optional filters; supports 'priorPeriodId'
	 *                                     to source opening balances (REQ-TB-002).
	 *
	 * @return array{data: array<int,array<string,mixed>>, total: int, totals: array<string,float>, isBalanced: bool}
	 *
	 * @spec openspec/changes/bookkeeping-trial-balance/tasks.md#task-3-1
	 *
	 * KNOWINGLY DANGLING — do not repoint this tag until shillinq#500 is answered.
	 * `#task-3-1` cannot resolve (`- [x] Task 3.1:` makes the checker read `Task`
	 * as the item id), but no canonical target is honest: REQ-TB-001 forbids this
	 * very class by name ("MUST NOT introduce a `TrialBalanceService.php`"), and
	 * the archived change's REQ-TB-008, which mandates it, was never canonical —
	 * that change was archived with `--skip-specs` as having no spec delta. The
	 * prohibition predates this file by nine days. A tag that resolves to a
	 * requirement the code violates reports conformance; a dangling one reports
	 * work to do.
	 */
	public function compute(string $administrationId, string $periodId, array $filters = []): array;
}//end interface
