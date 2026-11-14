<?php

/**
 * Contract of RequisitionService
 *
 * The public surface of RequisitionService that other components call, so they depend
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
 * Contract of RequisitionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RequisitionServiceInterface {

	/**
	 * Approve a submitted requisition (REQ-REQ-003).
	 *
	 * Gated by BudgetBlocker::canCommit() — reused unmodified from
	 * bookkeeping-verplichtingenadministratie. BudgetBlocker resolves the
	 * matching Budget for (programma, boekjaar) and checks that
	 * totaalbedrag_excl_btw fits the free room, or that the approver's
	 * mandate carries an override, exactly as it does for a Commitment.
	 * Fail-closed: when the budget check fails or errors, the requisition is
	 * NOT approved (CWE-863).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $requisitionId Requisition id.
	 * @param string $approverId User id of the approving user (server-resolved).
	 *
	 * @return array<string,mixed> The updated Requisition.
	 *
	 * @throws \RuntimeException When the requisition is missing, not submitted,
	 *                           or the budget check fails.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function approveRequisition(string $administrationId, string $requisitionId, string $approverId): array;

	/**
	 * Create a draft requisition with its lines (REQ-REQ-001).
	 *
	 * Server-authoritative: the requester is derived from the validated
	 * administration membership, never trusted from the request body
	 * (ADR-005). The requisitionNumber is generated server-side. Lines are
	 * normalised and totaalbedrag_excl_btw is computed as their sum.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param array<string,mixed> $payload Caller payload (programma, boekjaar,
	 *                                     neededByDate, justification, soort,
	 *                                     preferredSupplierId, lines).
	 *
	 * @return array<string,mixed> The persisted Requisition payload.
	 *
	 * @throws \RuntimeException When the requester lacks access or a required field is missing.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function createRequisition(string $administrationId, array $payload): array;

	/**
	 * Reject a submitted requisition (REQ-REQ-004).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $requisitionId Requisition id.
	 * @param string $rejectorId User id of the rejecting user (server-resolved).
	 * @param string $reason Non-blank rejection reason.
	 *
	 * @return array<string,mixed> The updated Requisition.
	 *
	 * @throws \RuntimeException When the requisition is missing, not submitted,
	 *                           or the reason is blank.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function rejectRequisition(
		string $administrationId,
		string $requisitionId,
		string $rejectorId,
		string $reason,
	): array;

	/**
	 * Submit a draft requisition for approval (REQ-REQ-002).
	 *
	 * Always routes through human approval regardless of mandate sufficiency
	 * — unlike Commitment's `indienen` transition, a Requisition never
	 * skips straight to approved.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $requisitionId Requisition id.
	 *
	 * @return array<string,mixed> The updated Requisition.
	 *
	 * @throws \RuntimeException When the requisition is missing, not in draft,
	 *                           or has no positive total.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function submitRequisition(string $administrationId, string $requisitionId): array;
}//end interface
