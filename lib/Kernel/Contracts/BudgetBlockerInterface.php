<?php

/**
 * Contract of BudgetBlocker
 *
 * The public surface of BudgetBlocker that other components call, so they depend
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
 * Contract of BudgetBlocker.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetBlockerInterface {

	/**
	 * Precondition for the `aangaan` / `goedkeuren` transitions: is there enough
	 * budget room for every rule of this commitment (REQ-VPL-001)?
	 *
	 * A commitment is allowed when, for each rule, the rule amount fits within
	 * the matching Budget's free_capacity — OR the signer holds a valid override-
	 * mandate (in which case the commitment proceeds and the override reason is
	 * expected to be recorded on the commitment). Each rule is validated against
	 * its own programme + financial_year budget independently (multi-year isolation).
	 *
	 * Fail-closed: returns false on any exception (CWE-863).
	 *
	 * @param string $commitmentNumber The commitment identifier (lifecycle-engine call parity).
	 * @param array<string,mixed>|null $object The Commitment object being transitioned.
	 *
	 * @return bool True when the commitment may be signed.
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.4
	 */
	public function canCommit(string $commitmentNumber, ?array $object = null): bool;
}//end interface
