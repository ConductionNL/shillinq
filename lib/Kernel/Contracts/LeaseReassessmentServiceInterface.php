<?php

/**
 * Contract of LeaseReassessmentService
 *
 * The public surface of LeaseReassessmentService that other components call, so they depend
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
 * Contract of LeaseReassessmentService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LeaseReassessmentServiceInterface {

	/**
	 * Record an extension-option reassessment (REQ-LR-002).
	 *
	 * The new extension-options array carries updated exercise-likelihoods;
	 * scheduleLength then resolves the new term and the calculator produces the
	 * post-event liability. The schedule itself is regenerated from
	 * fromSequence by LeasePaymentScheduleService once the event is approved.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope.
	 * @param array<int,array<string,mixed>> $updatedExtensionOptions The new extension-options array.
	 * @param string $triggerDescription Free-text business reason.
	 * @param string $approver Approver person id.
	 *
	 * @return array<string,mixed>|null The persisted event payload.
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function recordExtensionOptionReassessment(
		string $leaseContractId,
		string $administrationId,
		array $updatedExtensionOptions,
		string $triggerDescription = '',
		string $approver = '',
	): ?array;

	/**
	 * Record an impairment write-down on the RoU asset (REQ-LR-004).
	 *
	 * The recoverable value is the post-impairment carrying amount; the
	 * difference vs the pre-event RoU asset is the impairment loss, recognised
	 * in P&L via a `lease-modification-gain-loss` GL subtype.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope.
	 * @param float $recoverableValue Post-impairment carrying amount (decimal money).
	 * @param string $triggerDescription Free-text business reason.
	 * @param string $approver Approver person id.
	 *
	 * @return array<string,mixed>|null The persisted event payload.
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function recordImpairment(
		string $leaseContractId,
		string $administrationId,
		float $recoverableValue,
		string $triggerDescription = '',
		string $approver = '',
	): ?array;

	/**
	 * Record an indexation (CPI / fixed-percent) remeasurement (REQ-LR-001).
	 *
	 * The base-payment-amount is updated to the indexed value, the post-event
	 * liability is the PV of the remaining unavoidable payments under the new
	 * stream, and the RoU asset is adjusted catch-up by the liability delta
	 * (IFRS 16.42).
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope (server-resolved, ADR-005).
	 * @param float $newPaymentAmount The indexed payment per period (decimal money).
	 * @param string $triggerDescription Free-text business reason recorded on the event.
	 * @param string $approver Approver person id (organisations.person-id FK).
	 *
	 * @return array<string,mixed>|null The persisted event payload, or null when out of scope.
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function recordIndexationEvent(
		string $leaseContractId,
		string $administrationId,
		float $newPaymentAmount,
		string $triggerDescription = '',
		string $approver = '',
	): ?array;

	/**
	 * Record a scope / term / payment modification per IFRS 16.44 (REQ-LR-003).
	 *
	 * The caller supplies the new contract terms (any subset of the lease
	 * fields). The decision tree branch — separate-lease vs catch-up vs
	 * prospective — is selected by the caller via $approach; the default is
	 * `catch-up-adjustment`, matching the most common case where the same
	 * underlying asset's scope is modified mid-term.
	 *
	 * @param string $leaseContractId The LeaseContract id or slug.
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $newTerms Field overrides to apply on the lease snapshot.
	 * @param string $approach catch-up-adjustment|prospective|separate-lease.
	 * @param string $triggerDescription Free-text business reason.
	 * @param string $approver Approver person id.
	 *
	 * @return array<string,mixed>|null The persisted event payload.
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function recordModification(
		string $leaseContractId,
		string $administrationId,
		array $newTerms,
		string $approach = 'catch-up-adjustment',
		string $triggerDescription = '',
		string $approver = '',
	): ?array;
}//end interface
