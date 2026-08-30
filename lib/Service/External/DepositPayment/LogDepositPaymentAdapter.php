<?php

/**
 * Dormant default DepositPayment lifecycle adapter.
 *
 * Records the would-be deposit lifecycle operation
 * (request / status / refund) to the structured logger and returns a
 * synthetic `pending` / `PAYMENT_DEFERRED` outcome so the surrounding
 * orchestration (DepositReconciliationService::pollPending,
 * DepositWebhookController, polling-fallback scheduled workflow) stays
 * observable until a production binding (delegating to the existing
 * MolliePaymentAdapterInterface, or a future Stripe sibling) is wired
 * in via `Application::register()`. Mirrors the
 * `LogMolliePaymentAdapter` dormant-default pattern at the lifecycle
 * layer.
 *
 * Composition note: the production binding for this port is the layer
 * that calls down into `MolliePaymentAdapterInterface` and projects
 * the Mollie state onto the DepositPayment lifecycle state. The
 * dormant adapter does not depend on the Mollie adapter — it stays
 * pure-log so a tenant without a payment gateway configured still has
 * a complete DI graph.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\DepositPayment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookings-deposits/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\DepositPayment;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed DepositPayment lifecycle adapter.
 *
 * @spec openspec/specs/bookings-deposits/spec.md
 */
class LogDepositPaymentAdapter implements DepositPaymentAdapterInterface {
	/**
	 * Construct the log-backed DepositPayment adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the request intent + synthesise a `pending` /
	 * `PAYMENT_DEFERRED` result.
	 *
	 * The payment payload is logged in full minus the redirect /
	 * webhook URLs (which may carry tenant-scoped tokens). The
	 * lifecycle state is reported as `pending` so the polling-fallback
	 * workflow keeps the DepositPayment record in its pre-authorize
	 * state until a real binding picks it up.
	 *
	 * @param array<string,mixed> $payload Request envelope.
	 *
	 * @return DepositPaymentResult The dispatch outcome.
	 */
	public function requestPayment(array $payload): DepositPaymentResult {
		$sanitised = $payload;
		unset($sanitised['redirectUrl'], $sanitised['webhookUrl']);

		$intentId = 'dep_log_' . bin2hex(random_bytes(7));
		$this->logger->info(
			'Shillinq DepositPayment requestPayment deferred (no outbound PSP binding bound)',
			[
				'paymentIntentId' => $intentId,
				'payload' => $sanitised,
			]
		);

		return new DepositPaymentResult(
			lifecycleState: 'pending',
			gatewayStatus: 'PAYMENT_DEFERRED',
			paymentIntentId: $intentId,
			paymentLink: '',
			gateway: 'LOG_DEFERRED',
			dormant: true,
			extras: [
				'reason' => 'no-outbound-psp-bound',
				'note' => 'Override DepositPaymentAdapterInterface in Application::register() to a production implementation '
					. 'that delegates to MolliePaymentAdapterInterface (already wired) or a sibling PSP adapter; '
					. 'the Mollie connector slug `mollie-payments` covers Tasks 17/18/27 of bookings-deposits.',
			],
		);
	}//end requestPayment()

	/**
	 * Log the capture intent + synthesise a `captured` /
	 * `PAYMENT_DEFERRED` result.
	 *
	 * Used by the no-show-fee-capture flow (bookings-depth) on the
	 * authorise-now / capture-later rail. The dormant adapter reports a
	 * synthetic `captured` lifecycle state so the caller can finalise
	 * the no-show-fee bookkeeping; the `dormant=true` flag signals that
	 * no actual capture hit the gateway.
	 *
	 * @param string $paymentIntentId Gateway-side authorization intent id.
	 * @param array<string,mixed> $payload Capture envelope.
	 *
	 * @return DepositPaymentResult The capture outcome.
	 *
	 * @spec openspec/specs/bookings-cancellation-rules/spec.md
	 */
	public function capturePayment(string $paymentIntentId, array $payload): DepositPaymentResult {
		$this->logger->info(
			'Shillinq DepositPayment capturePayment deferred (no outbound PSP binding bound)',
			[
				'paymentIntentId' => $paymentIntentId,
				'payload' => $payload,
			]
		);

		return new DepositPaymentResult(
			lifecycleState: 'captured',
			gatewayStatus: 'PAYMENT_DEFERRED',
			paymentIntentId: $paymentIntentId,
			paymentLink: '',
			gateway: 'LOG_DEFERRED',
			dormant: true,
			extras: [
				'reason' => 'no-outbound-psp-bound',
				'note' => 'No actual capture was dispatched. Override DepositPaymentAdapterInterface in '
					. 'Application::register() to a production implementation delegating to '
					. 'MolliePaymentAdapterInterface (capture of an authorized payment) or a sibling PSP adapter.',
			],
		);
	}//end capturePayment()

	/**
	 * Log the status-fetch intent + synthesise a `pending` /
	 * `PAYMENT_DEFERRED` result.
	 *
	 * Per REQ-DP-007 the polling fallback runs every 5 minutes against
	 * records in `state=pending`. A dormant fetch keeps the record
	 * pending — the surrounding `DepositReconciliationService` MUST NOT
	 * advance the record on a dormant outcome, which is enforced by
	 * the `dormant=true` flag the caller inspects.
	 *
	 * @param string $paymentIntentId Gateway-side intent id.
	 * @param string $depositPaymentId DepositPayment record id.
	 *
	 * @return DepositPaymentResult The status outcome.
	 */
	public function fetchStatus(string $paymentIntentId, string $depositPaymentId): DepositPaymentResult {
		$this->logger->info(
			'Shillinq DepositPayment fetchStatus deferred (no outbound PSP binding bound)',
			[
				'paymentIntentId' => $paymentIntentId,
				'depositPaymentId' => $depositPaymentId,
			]
		);

		return new DepositPaymentResult(
			lifecycleState: 'pending',
			gatewayStatus: 'PAYMENT_DEFERRED',
			paymentIntentId: $paymentIntentId,
			paymentLink: '',
			gateway: 'LOG_DEFERRED',
			dormant: true,
			extras: [
				'reason' => 'no-outbound-psp-bound',
				'note' => 'DepositReconciliationService::pollPending() MUST inspect the dormant flag before advancing '
					. 'the record. The dormant adapter never advances the lifecycle.',
			],
		);
	}//end fetchStatus()

	/**
	 * Log the refund intent + synthesise a `voided` /
	 * `PAYMENT_DEFERRED` result.
	 *
	 * Per REQ-DP-008 a void from authorized / captured triggers a
	 * credit-note materialisation in the lifecycle action. The dormant
	 * adapter reports a synthetic `voided` lifecycle state so the
	 * surrounding lifecycle can finalise the void; the `dormant=true`
	 * flag signals to the caller that no actual refund hit the gateway.
	 *
	 * @param string $paymentIntentId Gateway-side intent id.
	 * @param array<string,mixed> $payload Refund envelope.
	 *
	 * @return DepositPaymentResult The refund outcome.
	 */
	public function initiateRefund(string $paymentIntentId, array $payload): DepositPaymentResult {
		$this->logger->info(
			'Shillinq DepositPayment initiateRefund deferred (no outbound PSP binding bound)',
			[
				'paymentIntentId' => $paymentIntentId,
				'payload' => $payload,
			]
		);

		return new DepositPaymentResult(
			lifecycleState: 'voided',
			gatewayStatus: 'PAYMENT_DEFERRED',
			paymentIntentId: $paymentIntentId,
			paymentLink: '',
			gateway: 'LOG_DEFERRED',
			dormant: true,
			extras: [
				'reason' => 'no-outbound-psp-bound',
				'note' => 'No actual refund was dispatched. The lifecycle MAY still materialise the CreditNote per '
					. 'REQ-DP-008 (the deferred refund is treated as an operator-approved void); the credit note carries '
					. '`paymentRefundDeferred: true` for later reconciliation.',
			],
		);
	}//end initiateRefund()

	/**
	 * Report whether this adapter is dormant (logs only, no outbound PSP).
	 *
	 * @inheritDoc
	 *
	 * @return bool Always true for the dormant log adapter.
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
