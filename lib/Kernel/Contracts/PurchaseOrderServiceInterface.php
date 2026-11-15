<?php

/**
 * Contract of PurchaseOrderService
 *
 * The public surface of PurchaseOrderService that other components call, so they depend
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
 * Contract of PurchaseOrderService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PurchaseOrderServiceInterface {

	/**
	 * Refuse to advance a PO to lifecycle "sent" until the chain is fully signed.
	 *
	 * Server-authoritative: the Vue layer never grants the transition. The method
	 * inspects the persisted PurchaseOrder, asserts every approval_chain entry has
	 * decision=approved + a non-empty decidedAt timestamp, and on success persists
	 * statusCode="sent" with a sentAt stamp. On failure the PO is left in its
	 * current state and a RuntimeException is raised so the controller maps it to
	 * a 409 Conflict (ADR-005, REQ-PO3W-001 send-block).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $purchaseOrderId PO id (id of the persisted record).
	 *
	 * @return array<string,mixed> The PurchaseOrder after transition to "sent".
	 *
	 * @throws \RuntimeException When the PO is missing, not approved, or the chain
	 *                           is incomplete.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
	 */
	public function blockSendUntilApproved(string $administrationId, string $purchaseOrderId): array;

	/**
	 * Create a purchase order with a materialised approval chain (REQ-PO3W-001).
	 *
	 * Server-authoritative:
	 *  - the requesterId is derived from the validated administration membership;
	 *    it is never trusted from the request body (ADR-005);
	 *  - the po_number is generated server-side using a CBS-conform sequence
	 *    (PO-{year}-{administrationCode}-{6-digit-sequence});
	 *  - cost-center budget is checked against the CostCenter record;
	 *  - the approval chain is computed from determineApprovalChain() and an
	 *    ApprovalTask record is created for each required approver;
	 *  - every approver is notified via the notification manager;
	 *  - statusCode starts at "draft", the schema's initial state, also while the approval chain is pending
	 *    or "draft" (no chain — defensive fallback that should not occur for
	 *    positive totals).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param array<string,mixed> $payload Caller payload (supplierId, costCenter,
	 *                                     projectCode, lines, etc.). Lines are
	 *                                     accepted as a flat array of
	 *                                     {productCode, quantity, unitPrice,
	 *                                     vatRate, glAccount, lineNumber?} entries.
	 *
	 * @return array<string,mixed> The persisted PurchaseOrder payload.
	 *
	 * @throws \RuntimeException When the requester lacks access, the cost-center
	 *                           budget is exceeded, or a required field is missing.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
	 */
	public function createPurchaseOrder(string $administrationId, array $payload): array;

	/**
	 * Determine the ordered list of required approvers for a PO total.
	 *
	 * Threshold table (REQ-PO3W-001):
	 *  - amount < €10,000          → [Teamleider]
	 *  - €10,000 ≤ amount < €50,000 → [Teamleider, Facility Manager]
	 *  - amount ≥ €50,000           → [Teamleider, Facility Manager, Procurement Manager]
	 *
	 * Comparison is done in integer cents to avoid float drift.
	 *
	 * @param float $amount The PO total in euro (multipleOf 0.01).
	 *
	 * @return array<int,array{role:string,order:int}> Ordered approver descriptors.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
	 */
	public function determineApprovalChain(float $amount): array;

	/**
	 * Transmit an approved PO via the PDF + email fallback path.
	 *
	 * Slice 03 surface. Used directly when the operator selects "PDF + email" on
	 * the PO form, and used indirectly by {@see sendToPeppol} when the supplier
	 * is not Peppol-registered or the Access Point fails. The method enforces
	 * the slice-02 approval-complete precondition, delegates the actual
	 * dispatch to the mailer port, persists `peppolFallbackReason` (the audit
	 * trail of why Peppol was not used) and transitions the PO to `sent`
	 * (REQ-PO3W-002 D2 — graceful fallback, never silent).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $purchaseOrderId PO id.
	 * @param string $fallbackReason The reason the fallback was used (audit
	 *                               trail); empty string defaults to
	 *                               `manual_pdf_email_fallback`.
	 *
	 * @return array<string,mixed> The PurchaseOrder after transition to "sent".
	 *
	 * @throws \RuntimeException When the PO is missing, the approval chain is
	 *                           incomplete, or the mailer cannot dispatch.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
	 */
	public function sendToPDFEmail(
		string $administrationId,
		string $purchaseOrderId,
		string $fallbackReason = '',
	): array;

	/**
	 * Transmit an approved PO to the supplier via Peppol BIS Ordering 3.0.
	 *
	 * Slice 03 surface. The method enforces the slice-02 approval-complete
	 * precondition (re-using the same chain check as blockSendUntilApproved so
	 * the guard stays single-sourced), resolves the supplier's Peppol participant
	 * id via the adapter port, transforms the PO into a UBL 2.1 Order document
	 * via PeppolBisOrderMapper, submits the document to the Peppol Access Point,
	 * and persists `peppolMessageId` + `peppolSentAt` + `statusCode=sent`
	 * on the PurchaseOrder record (REQ-PO3W-002).
	 *
	 * Graceful fallback (REQ-PO3W-002 D2): when the supplier is not a Peppol
	 * participant the method automatically delegates to {@see sendToPDFEmail}
	 * with a `supplier_not_peppol_participant` reason — the PO is never silently
	 * un-transmitted. When the Peppol Access Point itself fails the fallback
	 * also fires with reason `peppol_send_failed`.
	 *
	 * Server-authoritative: the controller cannot forge `peppolMessageId` or
	 * bypass the approval-state precondition (ADR-005).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $purchaseOrderId PO id (id of the persisted record).
	 *
	 * @return array<string,mixed> The PurchaseOrder after transition to "sent".
	 *
	 * @throws \RuntimeException When the PO is missing or the approval chain is
	 *                           incomplete (mapped to 404 / 409 by the controller).
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-002
	 */
	public function sendToPeppol(string $administrationId, string $purchaseOrderId): array;
}//end interface
