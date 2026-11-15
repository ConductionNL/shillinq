<?php

/**
 * Contract of ExceptionResolutionService
 *
 * The public surface of ExceptionResolutionService that other components call, so they depend
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
 * Contract of ExceptionResolutionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExceptionResolutionServiceInterface {

	/**
	 * Record the operator's accept-with-motivation disposition on a
	 * ThreeWayMatch and advance the linked invoice to `approved`.
	 *
	 * The method is server-authoritative:
	 *  - administration scope is validated;
	 *  - the matchId is re-loaded from OR so a forged POST cannot pivot
	 *    to a cross-tenant match record;
	 *  - the match must currently be in one of the
	 *    {@see EXCEPTION_STATUSES} — calling on an already-resolved
	 *    match throws a RuntimeException (the controller maps to 400);
	 *  - resolvedBy is taken from the authenticated user session, never
	 *    from the request body;
	 *  - the resolution stamps resolvedAt deterministically (date('c')).
	 *
	 * Payment is unblocked as a consequence of the linked invoice moving
	 * out of `exception` into `approved`.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $matchId ThreeWayMatch id.
	 * @param string $resolutionNotes Operator-supplied motivation
	 *                                (mandatory — empty notes are
	 *                                rejected with a RuntimeException).
	 *
	 * @return array<string,mixed> The updated ThreeWayMatch record.
	 *
	 * @throws \RuntimeException On cross-tenant access, missing match,
	 *                           non-exception match status, or blank
	 *                           motivation.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
	 */
	public function acceptWithMotivation(
		string $administrationId,
		string $matchId,
		string $resolutionNotes,
	): array;

	/**
	 * File a dispute on an out-of-tolerance ThreeWayMatch — auto-generate
	 * a UBL CreditNote request via openconnector and escalate to the
	 * Inkoper notification queue. Payment stays blocked until the
	 * supplier settles the dispute (the linked invoice stays in
	 * `exception`).
	 *
	 * The dispute payload is composed server-side from the match + the
	 * linked invoice header. The dispatch is best-effort — a failure of
	 * the openconnector adapter does NOT roll back the ThreeWayMatch
	 * record (the canonical surface for the audit trail) but is logged
	 * and surfaced through the returned envelope so the controller can
	 * report the partial success to the operator.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $matchId ThreeWayMatch id.
	 * @param string $disputeReason Human-readable dispute reason
	 *                              (mandatory — empty reason is
	 *                              rejected with a RuntimeException).
	 *
	 * @return array{match:array<string,mixed>,dispatch:array{accepted:bool,dispatchId:?string,error:?string}}
	 *
	 * @throws \RuntimeException On cross-tenant access, missing match,
	 *                           missing invoice, non-exception match
	 *                           status, or blank reason.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
	 */
	public function fileDispute(
		string $administrationId,
		string $matchId,
		string $disputeReason,
	): array;

	/**
	 * Reject an out-of-tolerance ThreeWayMatch and block the linked
	 * invoice from payment. Reverses any partial GR/IR posting via the
	 * slice-09 settlement hook (placeholder — slice 09 owns the actual
	 * GL transaction reversal; this method records the resolution and
	 * advances the invoice to `rejected` so the payment block is
	 * effective immediately).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $matchId ThreeWayMatch id.
	 * @param string $rejectionReason Operator-supplied rejection reason
	 *                                (mandatory).
	 *
	 * @return array<string,mixed> The updated ThreeWayMatch record.
	 *
	 * @throws \RuntimeException On cross-tenant access, missing match,
	 *                           non-exception match status, or blank
	 *                           reason.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-005
	 */
	public function rejectAndBlockPayment(
		string $administrationId,
		string $matchId,
		string $rejectionReason,
	): array;
}//end interface
