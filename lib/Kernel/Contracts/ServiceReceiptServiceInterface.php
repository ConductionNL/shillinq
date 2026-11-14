<?php

/**
 * Contract of ServiceReceiptService
 *
 * The public surface of ServiceReceiptService that other components call, so they depend
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
 * Contract of ServiceReceiptService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ServiceReceiptServiceInterface {

	/**
	 * Accept the SvcReceipt and update originating PO(s) lifecycle
	 * (REQ-PO3W-011).
	 *
	 * Side effects:
	 *  - lifecycle transitions to "accepted" (callable only from
	 *    "confirmed" — an unconfirmed receipt cannot feed the matching
	 *    engine);
	 *  - the originating PO(s) lifecycle is recomputed: if every line is
	 *    fully confirmed the PO transitions to "fully_received" otherwise
	 *    to "partial_received" — the same accumulation
	 *    GoodsReceiptNoteService::updatePurchaseOrderReceiptLifecycle()
	 *    already performs for goods.
	 *
	 * Unlike acceptGRN(), this never posts a StockMove — services never
	 * move inventory.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $receiptId SvcReceipt id.
	 *
	 * @return array<string,mixed> The persisted SvcReceipt payload.
	 *
	 * @throws \RuntimeException When the receipt is missing or the source
	 *                           state is not "confirmed".
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
	 */
	public function acceptServiceReceipt(string $administrationId, string $receiptId): array;

	/**
	 * Append a SvcReceiptLine to an existing SvcReceipt.
	 *
	 * Validates that the PO line belongs to one of the receipt's poIds[]
	 * and that exactly one confirmation mode (percentageComplete OR
	 * quantityConfirmed OR amountConfirmedCents) is supplied. Derives
	 * quantityAccepted/quantityReceived from that mode so the matching
	 * engine reads the same field names it already reads off
	 * GoodsReceiptLine.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $receiptId Parent SvcReceipt id.
	 * @param array<string,mixed> $payload poLineId, percentageComplete,
	 *                                     quantityConfirmed, amountConfirmedCents,
	 *                                     periodStart, periodEnd, notes.
	 *
	 * @return array<string,mixed> The persisted SvcReceiptLine payload.
	 *
	 * @throws \RuntimeException When the receipt or PO line is missing, or
	 *                           validation fails.
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
	 */
	public function addServiceReceiptLine(string $administrationId, string $receiptId, array $payload): array;

	/**
	 * Transition the SvcReceipt to "confirmed" (approver confirms delivery).
	 *
	 * Only callable from the "draft" state; any other source state is a
	 * conflict and surfaces a RuntimeException so the controller maps it to
	 * a 409.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $receiptId SvcReceipt id.
	 *
	 * @return array<string,mixed> The persisted SvcReceipt payload.
	 *
	 * @throws \RuntimeException When the receipt is missing or the source
	 *                           state is not "draft".
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
	 */
	public function confirmServiceReceipt(string $administrationId, string $receiptId): array;

	/**
	 * Create a SvcReceipt against one or more PurchaseOrders.
	 *
	 * Server-authoritative:
	 *  - approver is derived from the validated session — never trusted
	 *    from the request body (ADR-005);
	 *  - the receiptNumber is generated server-side (per-administration
	 *    sequence: SVR-{year}-{administrationCode}-{sequence});
	 *  - the supplied po_ids[] are validated for tenant scope; cross-tenant
	 *    refs are masked as 404;
	 *  - lifecycle starts at "draft".
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param array<string,mixed> $payload Caller payload (poIds, periodStart,
	 *                                     periodEnd, notes, costCenter,
	 *                                     projectCode).
	 *
	 * @return array<string,mixed> The persisted SvcReceipt payload.
	 *
	 * @throws \RuntimeException When the caller lacks access, no valid PO is
	 *                           supplied, or a required field is missing.
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
	 */
	public function createServiceReceipt(string $administrationId, array $payload): array;
}//end interface
