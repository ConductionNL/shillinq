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
	 * Refuse to advance a PO to lifecycle "sent" until it is approved.
	 *
	 * Server-authoritative: the Vue layer never grants the transition. The order
	 * must carry statusCode approved, which only OpenRegister's approval chain
	 * sets (REQ-PAD-001); on success it persists statusCode="sent" with a sentAt
	 * stamp. On failure the PO is left in its current state and a
	 * RuntimeException is raised so the controller maps it to a 409 Conflict
	 * (ADR-005, REQ-PO3W-001 send-block).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $purchaseOrderId PO id (id of the persisted record).
	 *
	 * @return array<string,mixed> The PurchaseOrder after transition to "sent".
	 *
	 * @throws \RuntimeException When the PO is missing or its statusCode is not
	 *                           approved by OpenRegister's approval chain.
	 *
	 * @spec openspec/changes/purchasing-approval-delegation/tasks.md#task-1.3
	 */
	public function markSent(string $administrationId, string $purchaseOrderId): array;

	/**
	 * Which dispatch channels can take an order, and why one cannot.
	 *
	 * @return array{peppol: array{available: bool, reason: string|null}, email: array{available: bool, reason: string|null}}
	 *
	 * @spec openspec/changes/archive/2026-11-24-purchasing-order-dispatch-and-receipt/tasks.md#task-3.2
	 */
	public function dispatchChannels(): array;

	/**
	 * Apply integriq's Peppol delivery status to the purchase order it names (REQ-PODR-001).
	 *
	 * @param string $purchaseOrderId The order's id.
	 * @param string $status          integriq's delivery status.
	 * @param string $detail          integriq's reason, for a failure.
	 *
	 * @return bool True when the order changed.
	 *
	 * @spec openspec/changes/archive/2026-11-24-purchasing-order-dispatch-and-receipt/tasks.md#task-2.3
	 */
	public function applyPeppolDeliveryStatus(string $purchaseOrderId, string $status, string $detail): bool;

	/**
	 * Create a purchase order in draft, routed to its approvers (REQ-PO3W-001).
	 *
	 * Server-authoritative:
	 *  - the requesterId is derived from the validated administration membership;
	 *    it is never trusted from the request body (ADR-005);
	 *  - the po_number is generated server-side using a CBS-conform sequence
	 *    (PO-{year}-{administrationCode}-{6-digit-sequence});
	 *  - cost-center budget is checked against the CostCenter record;
	 *  - the roles the amount needs are read from the PurchaseOrder schema's
	 *    declared approval chain and an ApprovalTask record is created for each;
	 *    the approval steps themselves are OpenRegister's;
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
	 * precondition (re-using the same approved-state check as markSent so
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
