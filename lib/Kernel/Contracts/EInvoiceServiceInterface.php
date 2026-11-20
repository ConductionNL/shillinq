<?php

/**
 * Contract of EInvoiceService
 *
 * The public surface of EInvoiceService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of EInvoiceService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EInvoiceServiceInterface {

	/**
	 * Run the Send e-invoice flow for one ARInvoice (REQ-EINV-005).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $invoiceNumber ARInvoice.invoiceNumber to send.
	 *
	 * @return array{deliveryStatus:string,transmissionId:?string,payloadFileUri:?string,fallback:bool,warnings:array<int,array<string,string>>}
	 *
	 * @throws RuntimeException When the invoice is missing/not accessible (IDOR-safe 404),
	 *                          not in the required `issued` lifecycle state, or pre-send
	 *                          validation fails (400 — no event is ever emitted on failure).
	 *
	 * @spec openspec/specs/bookkeeping-einvoicing-ubl-peppol/spec.md
	 */
	public function sendEInvoice(string $administrationId, string $invoiceNumber): array;

	/**
	 * The invoices the old send path left at `queued` without handing them over.
	 *
	 * Before this change a send reserved a made-up transmission id from the
	 * log-only port and recorded a `docudesk://file/<hash>` reference that named
	 * no stored file, so nothing reached integriq. A `queued` invoice is one that
	 * was handed over only when its payload reference is a stored file; the rest
	 * are what `occ shillinq:einvoice:requeue` offers to send again.
	 *
	 * Operator use only: this reads every administration in the register.
	 *
	 * @return array<int,array<string,mixed>> The stuck invoices, in register order.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.4
	 */
	public function queuedByTheOldPath(): array;

	/**
	 * Send one invoice the old path left at `queued` again, through integriq.
	 *
	 * The same validation, storage and hand-off as {@see self::sendEInvoice()},
	 * without the per-user administration check: the caller is the operator's
	 * `occ` command, which has no user session. The made-up transmission id of
	 * the log-only port is cleared; integriq's status writes the real one.
	 *
	 * @param array<string,mixed> $invoice One record from {@see self::queuedByTheOldPath()}.
	 *
	 * @return array{deliveryStatus:string,transmissionId:?string,payloadFileUri:?string,fallback:bool,warnings:array<int,array<string,string>>}
	 *
	 * @throws RuntimeException When the record names no administration or invoice
	 *                          number, is not issued, was already handed over, or
	 *                          fails validation.
	 *
	 * @spec openspec/changes/sales-einvoice-exchange/tasks.md#task-1.4
	 */
	public function requeue(array $invoice): array;
}//end interface
