<?php

/**
 * Contract of GoodsReceiptNoteService
 *
 * The public surface of GoodsReceiptNoteService that other components call, so they depend
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
 * Contract of GoodsReceiptNoteService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GoodsReceiptNoteServiceInterface {

	/**
	 * Accept the GRN, post StockMove credits, and update originating PO(s)
	 * lifecycle (REQ-PO3W-003 / REQ-GRN-001).
	 *
	 * Side effects:
	 *  - lifecycle transitions to "accepted" (callable from "received" or
	 *    "quality_checked"; the quality-check step is optional per REQ-GRN-002);
	 *  - for every GoodsReceiptLine whose quantity_accepted > 0, a StockMove
	 *    row is persisted with lifecycleState=posted, movementType=receipt,
	 *    movementReason=normal, referenceDocumentUri pointing at the
	 *    originating PO, and unitCost copied from the PO line (so downstream
	 *    valuation in member 09 + inventory-valuation has the data it needs);
	 *  - rejected quantities NEVER produce a StockMove — they are recorded on
	 *    the GoodsReceiptLine.rejectionReason but do not mutate stock;
	 *  - the originating PO(s) lifecycle is recomputed: if every line is
	 *    fully received the PO transitions to "fully_received" otherwise to
	 *    "partial_received".
	 *
	 * The GR/IR clearing GL posting on accept is specified in member 09; this
	 * service merely fires the lifecycle transition so the chain stays atomic
	 * per member.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $grnId GRN id.
	 *
	 * @return array<string,mixed> The persisted GoodsReceiptNote payload.
	 *
	 * @throws \RuntimeException When the GRN is missing or the source state is
	 *                           terminal.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function acceptGRN(string $administrationId, string $grnId): array;

	/**
	 * Append a GoodsReceiptLine to an existing GRN.
	 *
	 * Validates that the PO line belongs to one of the GRN's po_ids[] and
	 * that quantity_received >= quantity_accepted + quantity_rejected (the
	 * GRN ledger cannot accept-or-reject more than was physically received).
	 * rejection_reason is mandatory once quantity_rejected is non-zero per
	 * REQ-PO3W-003.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $grnId Parent GRN id.
	 * @param array<string,mixed> $payload poLineId, quantityReceived,
	 *                                     quantityAccepted, quantityRejected,
	 *                                     rejectionReason, batchReference.
	 *
	 * @return array<string,mixed> The persisted GoodsReceiptLine payload.
	 *
	 * @throws \RuntimeException When the GRN or PO line is missing, or
	 *                           validation fails.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function addGRNLine(string $administrationId, string $grnId, array $payload): array;

	/**
	 * Create a GoodsReceiptNote against one or more PurchaseOrders.
	 *
	 * Server-authoritative:
	 *  - receivedBy is derived from the validated session — never trusted
	 *    from the request body (ADR-005);
	 *  - the grn_number is generated server-side (per-administration
	 *    sequence: GRN-{year}-{administrationCode}-{sequence});
	 *  - the supplied po_ids[] are validated for tenant scope; cross-tenant
	 *    refs are masked as 404;
	 *  - lifecycle starts at "received" (REQ-GRN-002 — the receiver has
	 *    physically signed for the goods).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param array<string,mixed> $payload Caller payload (po_ids, received_at,
	 *                                     carrier, delivery_note_ref, costCenter,
	 *                                     projectCode).
	 *
	 * @return array<string,mixed> The persisted GoodsReceiptNote payload.
	 *
	 * @throws \RuntimeException When the caller lacks access, no valid PO is
	 *                           supplied, or a required field is missing.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function createGRN(string $administrationId, array $payload): array;

	/**
	 * Transition the GRN to "quality_checked" (REQ-GRN-002).
	 *
	 * Only callable from the "received" state; any other source state is a
	 * conflict and surfaces a RuntimeException so the controller maps it to
	 * a 409.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $grnId GRN id.
	 *
	 * @return array<string,mixed> The persisted GoodsReceiptNote payload.
	 *
	 * @throws \RuntimeException When the GRN is missing or the source state is
	 *                           not "received".
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function qualityCheckPass(string $administrationId, string $grnId): array;

	/**
	 * Append docudesk file-id references to the GRN's photos[] array.
	 *
	 * Photos are stored in docudesk with the receiving user's context (slice
	 * 04 design — Security/ADR-005); this service only persists the FK array.
	 * Existing photos are preserved.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $grnId GRN id.
	 * @param array<int,string> $photoFileIds File ids returned by docudesk.
	 *
	 * @return array<string,mixed> The persisted GoodsReceiptNote payload.
	 *
	 * @throws \RuntimeException When the GRN is missing.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function uploadPhotos(string $administrationId, string $grnId, array $photoFileIds): array;
}//end interface
