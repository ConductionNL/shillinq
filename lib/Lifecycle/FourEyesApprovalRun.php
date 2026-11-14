<?php

/**
 * The payment-run approval a FourEyesPaymentRunGuard check works on.
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
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * The payment run, approver and transition action shared by the steps of one
 * four-eyes check. Built and used only by FourEyesPaymentRunGuard.
 *
 * @spec openspec/specs/payment-run-four-eyes/spec.md
 */
final class FourEyesApprovalRun {
	/**
	 * Constructor.
	 *
	 * @param string $paymentRunId The PaymentRun uuid.
	 * @param string $approverId   The trimmed approver uid.
	 * @param string $action       The transition action being applied.
	 */
	public function __construct(
		public readonly string $paymentRunId,
		public readonly string $approverId,
		public readonly string $action,
	) {

	}//end __construct()
}//end class
