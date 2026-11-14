<?php

/**
 * Contract of PaymentReconciliationService
 *
 * The public surface of PaymentReconciliationService that other components call, so they depend
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
 * Contract of PaymentReconciliationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentReconciliationServiceInterface {

	/**
	 * Outcome: the payment was authorized by the gateway.
	 *
	 * @var string
	 */
	public const OUTCOME_AUTHORIZED = 'authorized';

	/**
	 * Outcome: the payment was captured/settled at the gateway.
	 *
	 * @var string
	 */
	public const OUTCOME_CAPTURED = 'captured';

	/**
	 * Outcome: the payment was rejected/failed at the gateway.
	 *
	 * @var string
	 */
	public const OUTCOME_FAILED = 'failed';

	/**
	 * Outcome: the payment was voided/refunded at the gateway.
	 *
	 * @var string
	 */
	public const OUTCOME_VOIDED = 'voided';

	/**
	 * Reconcile a single payment record against a reported outcome.
	 *
	 * Resolves the record by paymentIntentId across BOTH the PaymentRequest and
	 * DepositPayment schemas (REQ-APL-004). Idempotent: if the record is already
	 * in (or beyond) the target state, the call is a no-op and no invoice is
	 * settled twice (REQ-APL-004 replay safety). The lookup is by
	 * paymentIntentId, scoping the write to exactly the matched record (no IDOR —
	 * the gateway never supplies a record id). On capture of a PaymentRequest
	 * the linked ARInvoice is settled through its existing lifecycle; if that is
	 * impossible the request lands in captured_unapplied (REQ-APL-005).
	 *
	 * @param string $gateway The gateway slug ('mollie'|'stripe').
	 * @param array<string, mixed> $event Normalised event with at least
	 *                                    'paymentIntentId' and 'outcome'; optional
	 *                                    'errorCode', 'errorMessage',
	 *                                    'settlementReference', 'gatewayFeeAmount'.
	 *
	 * @return array{result: string, schema: ?string} Result constant + which schema matched (or null).
	 *
	 * @spec openspec/changes/ar-invoice-payment-links/specs/ar-invoice-payment-links/spec.md (REQ-APL-004, REQ-APL-005, REQ-APL-006)
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-005)
	 */
	public function reconcile(string $gateway, array $event): array;
}//end interface
