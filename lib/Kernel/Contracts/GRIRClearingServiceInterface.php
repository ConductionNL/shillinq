<?php

/**
 * Contract of GRIRClearingService
 *
 * The public surface of GRIRClearingService that other components call, so they depend
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
 * Contract of GRIRClearingService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface GRIRClearingServiceInterface {

	/**
	 * Fan out {@see createGRIRPosting()} over every accepted
	 * `GoodsReceiptLine` of a just-accepted `GoodsReceiptNote`
	 * (grir-accrual-wiring REQ-001). Wired by
	 * {@see \OCA\Shillinq\Listener\GRIRClearingListener} on the
	 * `GoodsReceiptNote` `* -> accepted` transition.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $grn The accepted GoodsReceiptNote.
	 *
	 * @return array<string,mixed> Result envelope: {posted:int, skipped:int, results:array}.
	 *
	 * @spec openspec/specs/grir-accrual-wiring/spec.md
	 */
	public function postGRIRForGoodsReceiptAccept(string $administrationId, array $grn): array;

	/**
	 * Fan out {@see createGRIRPosting()} over every accepted
	 * `SvcReceiptLine` of a just-accepted `SvcReceipt` (grir-accrual-wiring
	 * REQ-002). `SvcReceipt` carries no `grnNumber`/`receivedAt` fields —
	 * this normalises the receipt array to the shape `createGRIRPosting()`
	 * expects (`receiptNumber` -> `grnNumber`, `periodEnd`/`confirmedAt`
	 * -> `receivedAt`) without changing `createGRIRPosting()` itself.
	 * Wired by {@see \OCA\Shillinq\Listener\GRIRClearingListener} on the
	 * `SvcReceipt` `confirmed -> accepted` transition.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $receipt The accepted SvcReceipt.
	 *
	 * @return array<string,mixed> Result envelope: {posted:int, skipped:int, results:array}.
	 *
	 * @spec openspec/specs/grir-accrual-wiring/spec.md
	 */
	public function postGRIRForServiceReceiptAccept(string $administrationId, array $receipt): array;

	/**
	 * Compute the GR/IR clearing account saldo for a fiscal period
	 * (REQ-PO3W-009 — period-end reconciliation).
	 *
	 * Iterates every GLLine on the configured GR/IR clearing account for
	 * the given period and computes the net (debit minus credit) in
	 * integer cents. The result MUST equal zero when every GRN in the
	 * period has a matching approved invoice; a non-zero value means
	 * dangling goods-in-transit (the operator must investigate).
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $periodId Fiscal period identifier (e.g. `2026-Q2`).
	 *
	 * @return array{periodId:string,clearingAccount:string,debitCents:int,
	 *                creditCents:int,saldoCents:int,balanced:bool}
	 *
	 * @throws \RuntimeException When the administration is inaccessible.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-009
	 */
	public function reconcileGRIRSaldoForPeriod(string $administrationId, string $periodId): array;

	/**
	 * Resolve the driving `auto_approved`/`within_tolerance` `ThreeWayMatch`
	 * for a `SupplierInvoice` that just reached `matched`, and call
	 * {@see settleGRIRPosting()} (grir-accrual-wiring REQ-003). When no
	 * qualifying match is found the settlement is skipped without error
	 * (the invoice may have been matched by a path this service does not
	 * recognise, or the match is not yet visible — fail-soft per REQ-004).
	 * Wired by {@see \OCA\Shillinq\Listener\GRIRClearingListener} on the
	 * `SupplierInvoice` `matching -> matched` transition.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId SupplierInvoice id.
	 *
	 * @return array<string,mixed> Result envelope (see {@see settleGRIRPosting()}),
	 *                             or {posted:false, message} when no
	 *                             qualifying match is found.
	 *
	 * @spec openspec/specs/grir-accrual-wiring/spec.md
	 */
	public function settleGRIRForMatchedInvoice(string $administrationId, string $invoiceId): array;
}//end interface
