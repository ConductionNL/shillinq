<?php

/**
 * Payment Run Reconcile Guard
 *
 * Guards `PaymentRun.reconcile` (exported -> reconciled). A run is reconciled
 * when every payment line is found on a booked CAMT.053 bank statement;
 * PaymentRunReconciliationService does that match and saves the transition
 * under the `payment-run-camt-reconcile` grant. A PATCH of `lifecycleState`
 * through the object API carries no grant and no statement, and is refused.
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
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * Lets a payment run become reconciled only through a CAMT.053 match.
 *
 * @spec openspec/changes/enforced-schema-authorization/tasks.md#task-2.3
 */
final class PaymentRunReconcileGuard extends ServerWriteGrantGuard {

	/**
	 * The grant PaymentRunReconciliationService holds while it saves a full match.
	 *
	 * @var string
	 */
	public const PURPOSE = 'payment-run-camt-reconcile';

	/**
	 * Refusal shown to the caller.
	 *
	 * @var string
	 */
	public const MESSAGE = 'A payment run is reconciled by matching it against a CAMT.053 bank statement. Upload the statement to reconcile it.';
}//end class
