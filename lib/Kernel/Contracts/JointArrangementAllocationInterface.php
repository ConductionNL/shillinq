<?php

/**
 * Contract of JointArrangementAllocation
 *
 * The public surface of JointArrangementAllocation that other components call, so they depend
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

use DomainException;

/**
 * Contract of JointArrangementAllocation.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface JointArrangementAllocationInterface {

	/**
	 * Fill a run's lines and total from the posted lines on its key's cost clusters.
	 *
	 * @param array<string,mixed> $run The run.
	 *
	 * @return array<string,mixed> The run with keyName, name, total and lines.
	 *
	 * @throws DomainException When the run cannot be proposed.
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
	 */
	public function propose(array $run): array;

	/**
	 * Lock a proposed run.
	 *
	 * @param array<string,mixed> $run The proposed run.
	 *
	 * @return array<string,mixed> The run.
	 *
	 * @throws DomainException When the run has no lines or its key and period already have a confirmed run.
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
	 */
	public function confirm(array $run): array;

	/**
	 * Write one draft contribution invoice per participant line of a confirmed run.
	 *
	 * @param array<string,mixed> $run The confirmed run.
	 *
	 * @return array<string,mixed> The run with each line's invoiceId.
	 *
	 * @throws DomainException When a participant to invoice has no customer.
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-gr-consolidation/spec.md
	 */
	public function invoice(array $run): array;
}//end interface
