<?php

/**
 * Contract of WebshopOrderInvoicer
 *
 * The public surface of WebshopOrderInvoicer that other components call, so they depend
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
 * Contract of WebshopOrderInvoicer.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WebshopOrderInvoicerInterface {

	/**
	 * Handle one saved order: invoice it, mark it duplicate or refuse it.
	 *
	 * @param array<string,mixed> $order   The WebshopOrder as saved.
	 * @param string              $orderId Its id.
	 * @param string              $today   The invoice date (Y-m-d).
	 *
	 * @return string invoiced, duplicate, refused, or skipped for an order that is not waiting for its invoice.
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
	 */
	public function handle(array $order, string $orderId, string $today): string;

	/**
	 * Settle the invoice of a paid order on the channel's clearing account
	 * (REQ-SWO-004).
	 *
	 * A paid order whose invoice is issued gets one receipt journal entry,
	 * debit the channel's clearing account and credit receivables, posted
	 * through its `postDirect` transition; the invoice keeps the provider's
	 * payment id as its payment reference for the payout match and is marked
	 * paid, and the order keeps the journal entry so it is settled once. A
	 * pending order only passes its payment id on to the invoice.
	 *
	 * @param array<string,mixed> $order   The order.
	 * @param string              $orderId Its id.
	 *
	 * @return string settled, pending, or skipped (no invoice, already settled, invoice not issued, no clearing account).
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.4
	 */
	public function settle(array $order, string $orderId): string;

	/**
	 * Credit a refunded or cancelled order (REQ-SWO-005).
	 *
	 * An invoiced order whose payment status becomes refunded or cancelled
	 * gets one ARInvoice of type credit-note referencing the original invoice:
	 * for the whole invoice, or, when the shop names the refunded lines in
	 * `payment.refundedLines` (and `payment.refundedShipping`), for those
	 * lines and quantities only. The order keeps the credit note's id and
	 * moves to credited, so it is credited once.
	 *
	 * @param array<string,mixed> $order   The order.
	 * @param string              $orderId Its id.
	 * @param string              $today   The credit note date (Y-m-d).
	 *
	 * @return string credited, or skipped (not invoiced, not refunded or cancelled, already credited, invoice gone, nothing to credit).
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.5
	 */
	public function credit(array $order, string $orderId, string $today): string;

	/**
	 * Whether an order still waits for its invoice: received, with no invoice and no refusal.
	 *
	 * @param array<string,mixed> $order The order.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/sales-webshop-orders/tasks.md#task-2.1
	 */
	public static function isWaiting(array $order): bool;
}//end interface
