<?php

/**
 * Contract of DepositReconciliationService
 *
 * The public surface of DepositReconciliationService that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of DepositReconciliationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DepositReconciliationServiceInterface {

	/**
	 * Outcome: the payment was authorized by the gateway.
	 *
	 * @var string
	 */
	public const OUTCOME_AUTHORIZED = 'authorized';

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
	 * Reconcile a single DepositPayment against a reported outcome.
	 *
	 * Idempotent: if the deposit is already in (or beyond) the target state for the
	 * given outcome, the call is a no-op and no AR invoice/credit note is duplicated
	 * (REQ-DP-006). The lookup is by paymentIntentId, scoping the write to exactly
	 * the matched record (no IDOR — the gateway never supplies a record id).
	 *
	 * @param string $paymentIntentId The opaque gateway payment intent reference.
	 * @param string $outcome One of the OUTCOME_* constants.
	 * @param string $gateway The gateway slug ('mollie'|'stripe').
	 * @param ?string $errorCode Machine error code on failure (REQ-DP-011).
	 * @param ?string $errorMessage Human-readable error on failure (REQ-DP-011).
	 *
	 * @return string One of the RESULT_* constants.
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md (REQ-DP-006, REQ-DP-011)
	 */
	public function reconcile(
		string $paymentIntentId,
		string $outcome,
		string $gateway,
		?string $errorCode = null,
		?string $errorMessage = null,
	): string;
}//end interface
