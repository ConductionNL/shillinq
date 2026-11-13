<?php

/**
 * Contract of DownPaymentService
 *
 * The public surface of DownPaymentService that other components call, so they depend
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

use OCA\Shillinq\Service\Sales\DownPaymentRefusedException;
use OutOfBoundsException;

/**
 * Contract of DownPaymentService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DownPaymentServiceInterface {

	/**
	 * Make a draft invoice the final invoice of an order: one negative line per
	 * open down payment and VAT rate, the totals reduced, each down payment
	 * referenced (REQ-SDP-003). Saved on the draft.
	 *
	 * @param array<string,mixed> $invoice        The draft invoice.
	 * @param string              $orderReference The order.
	 *
	 * @return array<string,mixed> The invoice as saved.
	 *
	 * @throws DownPaymentRefusedException When the invoice is not a draft or nothing is open.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function deductOnto(array $invoice, string $orderReference): array;

	/**
	 * Read an ARInvoice by uuid or invoice number.
	 *
	 * @param string $invoiceId The uuid or number.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws OutOfBoundsException When there is no such invoice.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function findInvoice(string $invoiceId): array;

	/**
	 * The issued down payments of the invoice's customer that no invoice has
	 * deducted, optionally for one order (REQ-SDP-003).
	 *
	 * @param array<string,mixed> $invoice        The (final) invoice.
	 * @param string              $orderReference Only this order; '' for every order.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function openDownPayments(array $invoice, string $orderReference = ''): array;

	/**
	 * Every down payment of the invoice's order with its amount, paid state
	 * and the invoice that deducted it (REQ-SDP-005).
	 *
	 * @param array<string,mixed> $invoice A down-payment or final invoice.
	 *
	 * @return list<array<string,mixed>>
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function position(array $invoice): array;

	/**
	 * Raise a draft down-payment invoice on an order (REQ-SDP-001).
	 *
	 * @param array<string,mixed> $request administrationId, customerId, orderReference, orderLabel,
	 *                                     orderVatBreakdown (when shillinq cannot read the order),
	 *                                     percentage or amount, invoiceNumber, invoiceDate, dueDate.
	 *
	 * @return array<string,mixed> The saved draft invoice.
	 *
	 * @throws DownPaymentRefusedException When the request cannot make a down payment.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function raise(array $request): array;

	/**
	 * Refuse to issue a final invoice whose down payment was already deducted
	 * on another invoice, or whose deductions exceed its total (REQ-SDP-004).
	 *
	 * @param array<string,mixed> $invoice The invoice being issued.
	 *
	 * @return void
	 *
	 * @throws DownPaymentRefusedException When the invoice may not be issued.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function requireOpenDeductions(array $invoice): void;

	/**
	 * Record on each down payment the final invoice that deducted it (REQ-SDP-004).
	 *
	 * @param array<string,mixed> $invoice The issued final invoice.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function stampDeductions(array $invoice): void;
}//end interface
