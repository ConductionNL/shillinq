<?php

/**
 * Contract of RevenueCutoffService
 *
 * The public surface of RevenueCutoffService that other components call, so they depend
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
 * Contract of RevenueCutoffService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RevenueCutoffServiceInterface {

	/**
	 * Compute the revenue cut-off for one administration (REQ-IFRS15-007, REQ-IFRS15-008).
	 *
	 * Returns a per-contract list of cut-off rows: cumulative recognised, cumulative
	 * billed (from the supplied $billedByContract map), the contract asset /
	 * liability split, the transaction price allocated, the remaining amount, and the
	 * period recognised. The job is idempotent — it derives balances from the current
	 * event + billing snapshot, so re-running on the same snapshot yields identical
	 * rows (REQ-IFRS15-007).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $periodEnd Period-end date the cut-off covers (REQ-IFRS15-008).
	 * @param array<string,float> $billedByContract contractId => cumulative billed amount (from AR module).
	 *
	 * @return array{data: array<int,array<string,mixed>>, total: int}
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-17
	 */
	public function compute(string $administrationId, string $periodEnd, array $billedByContract = []): array;
}//end interface
