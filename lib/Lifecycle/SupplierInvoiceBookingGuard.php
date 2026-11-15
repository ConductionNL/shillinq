<?php

/**
 * Supplier Invoice Booking Guard
 *
 * The `requires` of SupplierInvoice.bookWithoutOrder: an invoice may be
 * handed to accounts payable when no line links to a purchase order, its
 * supplier is a known payee, every line has an expense account (or the payee
 * has a default one), and every open duplicate or IBAN warning carries a
 * reason (purchasing-supplier-invoice-intake design.md D1).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use OCA\OpenRegister\Lifecycle\GuardResult;
use OCA\OpenRegister\Lifecycle\LifecycleGuardInterface;
use OCA\Shillinq\Kernel\Contracts\SupplierInvoiceChecksInterface;
use Throwable;

/**
 * Decides whether a supplier invoice may be booked without an order.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
 */
class SupplierInvoiceBookingGuard implements LifecycleGuardInterface {

	/**
	 * Constructor.
	 *
	 * @param SupplierInvoiceChecksInterface $checks The shared supplier invoice checks.
	 */
	public function __construct(
		private readonly SupplierInvoiceChecksInterface $checks,
	) {

	}//end __construct()

	/**
	 * Allow or refuse the hand-over, naming what is missing.
	 *
	 * @param array<string, mixed> $object The SupplierInvoice.
	 * @param string               $action The transition name.
	 * @param string               $userId The acting user.
	 *
	 * @return GuardResult
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleGuardInterface's.
	 *
	 * @spec openspec/changes/archive/2026-09-29-purchasing-supplier-invoice-intake/tasks.md#task-3.2
	 */
	public function check(array $object, string $action, string $userId): GuardResult {
		try {
			$refusal = $this->refusal(invoice: $object);
		} catch (Throwable $e) {
			return GuardResult::deny('The invoice cannot be booked: its checks could not be completed.');
		}

		if ($refusal !== null) {
			return GuardResult::deny($refusal);
		}

		return GuardResult::allow();

	}//end check()

	/**
	 * The first reason the invoice cannot be booked, or null.
	 *
	 * @param array<string, mixed> $invoice The SupplierInvoice.
	 *
	 * @return string|null
	 */
	private function refusal(array $invoice): string|null {
		$lines = (array)($invoice['lines'] ?? []);
		$orderRefusal = $this->orderLinkRefusal(lines: $lines);
		if ($orderRefusal !== null) {
			return $orderRefusal;
		}

		$supplierRefusal = $this->supplierRefusal(invoice: $invoice, lines: $lines);
		if ($supplierRefusal !== null) {
			return $supplierRefusal;
		}

		return $this->unacknowledgedWarning(invoice: $invoice);

	}//end refusal()

	/**
	 * The refusal for an unknown supplier or, when the supplier has no default
	 * expense account, for the first line without one; or null.
	 *
	 * @param array<string, mixed> $invoice The SupplierInvoice.
	 * @param array<mixed>         $lines   The invoice lines.
	 *
	 * @return string|null
	 */
	private function supplierRefusal(array $invoice, array $lines): string|null {
		$payee = $this->checks->payee(supplierId: (string)($invoice['supplierId'] ?? ''));
		if ($payee === null) {
			return 'The invoice cannot be booked: its supplier is not recognised. Choose the supplier first.';
		}

		if (trim((string)($payee['defaultExpenseAccountNumber'] ?? '')) !== '') {
			return null;
		}

		return $this->missingAccountRefusal(lines: $lines);

	}//end supplierRefusal()

	/**
	 * The refusal for the first line linked to a purchase order, or null.
	 *
	 * @param array<mixed> $lines The invoice lines.
	 *
	 * @return string|null
	 */
	private function orderLinkRefusal(array $lines): string|null {
		foreach ($lines as $line) {
			$order = trim((string)($line['linkedPoId'] ?? ($line['linkedPoLineId'] ?? '')));
			if ($order !== '') {
				return sprintf('The invoice cannot be booked without an order: a line links to purchase order %s. It goes through the three-way match.', $order);
			}
		}

		return null;

	}//end orderLinkRefusal()

	/**
	 * The refusal for the first line without an expense account, or null.
	 *
	 * @param array<mixed> $lines The invoice lines.
	 *
	 * @return string|null
	 */
	private function missingAccountRefusal(array $lines): string|null {
		foreach ($lines as $index => $line) {
			if (trim((string)($line['accountNumber'] ?? '')) === '') {
				return sprintf('The invoice cannot be booked: line %d has no expense account.', ((int)($line['lineNumber'] ?? ($index + 1))));
			}
		}

		return null;

	}//end missingAccountRefusal()

	/**
	 * The refusal for a duplicate number or a differing IBAN that carries no
	 * acknowledgement reason, in that order, or null.
	 *
	 * @param array<string, mixed> $invoice The SupplierInvoice.
	 *
	 * @return string|null
	 */
	private function unacknowledgedWarning(array $invoice): string|null {
		$warnings = $this->checks->warnings(invoice: $invoice);
		if ($warnings['duplicateOfId'] !== '' && $this->hasReason(invoice: $invoice, field: 'duplicateAcknowledgedReason') === false) {
			return 'The invoice cannot be booked: its number was already used for this supplier. Give a reason to book it anyway.';
		}

		if ($warnings['ibanMismatch'] !== '' && $this->hasReason(invoice: $invoice, field: 'ibanAcknowledgedReason') === false) {
			return 'The invoice cannot be booked: its IBAN differs from the supplier record. Give a reason to book it anyway.';
		}

		return null;

	}//end unacknowledgedWarning()

	/**
	 * Whether the invoice carries a non-blank reason in the given field.
	 *
	 * @param array<string, mixed> $invoice The SupplierInvoice.
	 * @param string               $field   The reason field.
	 *
	 * @return bool
	 */
	private function hasReason(array $invoice, string $field): bool {
		return trim((string)($invoice[$field] ?? '')) !== '';

	}//end hasReason()
}//end class
