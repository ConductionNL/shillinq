<?php

/**
 * Contract of LeasePaymentScheduleService
 *
 * The public surface of LeasePaymentScheduleService that other components call, so they depend
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
 * Contract of LeasePaymentScheduleService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LeasePaymentScheduleServiceInterface {

	/**
	 * Build the amortization schedule for a lease without persisting it (REQ-LA-002).
	 *
	 * Pure read + compute: fetches the lease, verifies the administration scope,
	 * and returns the calculator's per-period rows. Used by the detail view's
	 * "next 12 months" preview and by the disclosure aggregation.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope (server-resolved).
	 *
	 * @return array<int,array<string,float|int>> Amortization rows (empty if out of scope / not found).
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function buildSchedule(string $leaseContractId, string $administrationId): array;

	/**
	 * Generate and persist the LeasePaymentSchedule rows for an active lease (REQ-LA-002).
	 *
	 * Idempotent within a generation: each row is keyed by leaseContract +
	 * periodSequence, so re-running after a reassessment overwrites the matching
	 * future rows. Returns the number of rows written.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope (server-resolved, ADR-005).
	 * @param int $fromSequence First period sequence to (re)generate (1 = full schedule).
	 *
	 * @return int Count of rows written.
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function generateSchedule(string $leaseContractId, string $administrationId, int $fromSequence = 1): int;
}//end interface
