<?php

/**
 * Contract of InvoiceSendingService
 *
 * The public surface of InvoiceSendingService that other components call, so they depend
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

use DomainException;
use OutOfBoundsException;

/**
 * Contract of InvoiceSendingService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InvoiceSendingServiceInterface {

	/**
	 * Send one invoice.
	 *
	 * `$via` empty follows the customer's delivery method; `email` mails the
	 * PDF whatever the method is, which is how a user answers the Peppol
	 * fallback. `$mailOnFallback` mails at once when Peppol has no participant
	 * for the customer, for a send nobody is watching (a batch, a recurring
	 * run); a person is offered the choice instead.
	 *
	 * @param string $administrationId The administration the invoice belongs to.
	 * @param string $invoiceId        The ARInvoice id.
	 * @param string $via              `''` for the customer's method, or `email`.
	 * @param bool   $mailOnFallback   Whether a Peppol fallback mails without asking.
	 *
	 * @return array{status: string, sending: array<string, mixed>, error: string}
	 *
	 * @throws OutOfBoundsException When the invoice is not found in an administration the user may act on.
	 * @throws DomainException      When the invoice is not issued or overdue.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) A watched and an unwatched send differ only in
	 * whether a Peppol fallback mails without asking.
	 *
	 * @spec openspec/changes/sales-invoice-sending/tasks.md#task-2.1
	 * @spec openspec/changes/sales-invoice-sending/specs/bookkeeping-accounts-receivable-core/spec.md#requirement-an-issued-invoice-is-sent-by-email-with-its-pdf-attached-req-sis-001
	 */
	public function send(string $administrationId, string $invoiceId, string $via = '', bool $mailOnFallback = false): array;

}//end interface
