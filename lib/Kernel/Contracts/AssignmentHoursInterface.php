<?php

/**
 * Contract of AssignmentHours
 *
 * The public surface of AssignmentHours that other components call, so they depend
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
 * Contract of AssignmentHours.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AssignmentHoursInterface {

	/**
	 * The schema holding one booked hour, with `projectAssignmentId` and `hours`.
	 */
	public const HOUR_SCHEMA = 'UrenRegistratie';

	/**
	 * The assignment schema.
	 */
	public const ASSIGNMENT_SCHEMA = 'ProjectAssignment';

	/**
	 * The ids of every assignment, for the repair step.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
	 */
	public function assignmentIds(): array;

	/**
	 * Clear the warned flags of an assignment whose estimate changed, then re-sum it.
	 *
	 * Two writes: a flag that stays true within one write is no change, so the
	 * declared warning would not fire again for the new estimate.
	 *
	 * @param string $assignmentId The ProjectAssignment id.
	 *
	 * @return bool True when the assignment was patched after clearing.
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
	 */
	public function rearm(string $assignmentId): bool;

	/**
	 * Re-sum one assignment and write its hours budget and its project's totals.
	 *
	 * @param string      $assignmentId The ProjectAssignment id.
	 * @param string|null $ignoreHourId An hour record to leave out (one being deleted).
	 *
	 * @return bool True when the assignment was patched.
	 *
	 * @spec openspec/specs/bookkeeping-consultancy-project-accounting/spec.md
	 */
	public function recalculate(string $assignmentId, ?string $ignoreHourId = null): bool;
}//end interface
