<?php

/**
 * The order and invoice a cancel-after-invoice decision is about.
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
 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-27
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * Order id, linked invoice id and the order's administration.
 *
 * @spec openspec/changes/bookings-deposit-to-invoice/tasks.md#task-27
 */
final class CancelAfterInvoiceCheck {

	/**
	 * Construct the check.
	 *
	 * @param string $orderId The Order.id.
	 * @param string $invoiceId The linked invoice id.
	 * @param string $administrationId The order's administration id.
	 */
	public function __construct(
		public readonly string $orderId,
		public readonly string $invoiceId,
		public readonly string $administrationId,
	) {

	}//end __construct()
}//end class
