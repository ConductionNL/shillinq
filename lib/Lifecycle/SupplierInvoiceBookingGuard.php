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
use OCA\Shillinq\Service\Purchasing\SupplierInvoiceChecks;
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
	 * @param SupplierInvoiceChecks $checks The shared supplier invoice checks.
	 */
	public function __construct(
		private readonly SupplierInvoiceChecks $checks,
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
	 * @spec openspec/changes/purchasing-supplier-invoice-intake/tasks.md#task-3.2
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
	private function refusal(array $invoice): ?string {
		$lines = (array)($invoice['lines'] ?? []);
		foreach ($lines as $line) {
			$order = trim((string)($line['linkedPoId'] ?? ($line['linkedPoLineId'] ?? '')));
			if ($order !== '') {
				return sprintf('The invoice cannot be booked without an order: a line links to purchase order %s. It goes through the three-way match.', $order);
			}
		}

		$supplierId = (string)($invoice['supplierId'] ?? '');
		$payee = $this->checks->payee(supplierId: $supplierId);
		if ($payee === null) {
			return 'The invoice cannot be booked: its supplier is not recognised. Choose the supplier first.';
		}

		$default = trim((string)($payee['defaultExpenseAccountNumber'] ?? ''));
		foreach ($lines as $index => $line) {
			if (trim((string)($line['accountNumber'] ?? '')) === '' && $default === '') {
				return sprintf('The invoice cannot be booked: line %d has no expense account.', ((int)($line['lineNumber'] ?? ($index + 1))));
			}
		}

		$warnings = $this->checks->warnings(invoice: $invoice);
		if ($warnings['duplicateOfId'] !== '' && trim((string)($invoice['duplicateAcknowledgedReason'] ?? '')) === '') {
			return 'The invoice cannot be booked: its number was already used for this supplier. Give a reason to book it anyway.';
		}

		if ($warnings['ibanMismatch'] !== '' && trim((string)($invoice['ibanAcknowledgedReason'] ?? '')) === '') {
			return 'The invoice cannot be booked: its IBAN differs from the supplier record. Give a reason to book it anyway.';
		}

		return null;

	}//end refusal()
}//end class
