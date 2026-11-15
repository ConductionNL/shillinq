<?php

/**
 * Contract of AuditExportService
 *
 * The public surface of AuditExportService that other components call, so they depend
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
 * Contract of AuditExportService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AuditExportServiceInterface {

	/**
	 * Assemble the JSON ledger from the lifecycle bundle.
	 *
	 * The ledger is a strictly-ordered list of events (by timestamp
	 * ascending, ties broken by event kind) so the auditor can read it
	 * top-to-bottom as the lifecycle unfolded. Events that lack a
	 * timestamp are stamped with the parent record's createdAt to keep
	 * the ordering deterministic.
	 *
	 * @param array<string,mixed> $bundle The collected lifecycle bundle —
	 *                                    shape: { invoice: array,
	 *                                    purchaseOrders: list, goodsReceiptNotes:
	 *                                    list, threeWayMatches: list }.
	 *
	 * @return array<string,mixed> The ledger payload (events list + summary).
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	public function buildLedger(array $bundle): array;

	/**
	 * Generate an immutable audit package for one SupplierInvoice and
	 * return the package envelope.
	 *
	 * Steps:
	 *  1. Resolve the SupplierInvoice + every linked PO/GRN/3WM record.
	 *  2. Build the ledger via {@see buildLedger()}.
	 *  3. Render the HTML summary via {@see renderSummaryHtml()}.
	 *  4. Assemble manifest.json with sha256 over the ledger + retention
	 *     marker.
	 *  5. Write the ZIP to a tempnam file (sys_get_temp_dir).
	 *  6. Best-effort hand-off to docudesk archival (logged-only stub
	 *     here; production wires the real adapter).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId SupplierInvoice id.
	 *
	 * @return array{
	 *     packageId:string,
	 *     invoiceId:string,
	 *     invoiceNumber:string,
	 *     generatedAt:string,
	 *     generatedBy:string,
	 *     sha256:string,
	 *     zipPath:string,
	 *     retentionYears:int,
	 *     archived:bool,
	 *     archiveReference:?string,
	 *     attachmentCount:int,
	 *     eventCount:int
	 * }
	 *
	 * @throws \RuntimeException On cross-tenant access, missing invoice,
	 *                           or ZIP write failure.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	public function generateAuditPackage(string $administrationId, string $invoiceId): array;
}//end interface
