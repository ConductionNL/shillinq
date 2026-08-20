<?php

/**
 * DepositPayment lifecycle adapter port.
 *
 * The `bookings-deposits` lifecycle (REQ-DP-001 / 003 / 005 / 007 / 008)
 * needs three high-level operations the surrounding orchestrators
 * (DepositReconciliationService, DepositWebhookController,
 * scheduled-workflow polling fallback) can call without binding to a
 * specific payment service provider:
 *
 *  1. `requestPayment()` — open a payment intent for a DepositPayment
 *     (REQ-DP-005: paymentLink) and persist the gateway-side
 *     `paymentIntentId` on the record.
 *  2. `fetchStatus()` — polling fallback when a webhook is missed
 *     (REQ-DP-007: 5-minute reconciliation window) — query the
 *     gateway and project the result onto the DepositPayment
 *     lifecycle state.
 *  3. `initiateRefund()` — on `voidFromAuthorized` /
 *     `voidFromCaptured` (REQ-DP-008) — issue a refund through the
 *     gateway, materialise the credit note from the lifecycle action.
 *
 * Per ADR-022 the actual gateway transport lives in lower-level
 * adapters (`MolliePaymentAdapterInterface` for Mollie iDEAL / SEPA /
 * cards; a future StripeAdapter for Stripe). This adapter is the
 * deposit-LIFECYCLE port — it sits one layer up so the lifecycle code
 * never sees a Mollie vs. Stripe branch, only the projected lifecycle
 * state. The production binding will delegate to whichever underlying
 * PSP adapter is configured per-tenant.
 *
 * Until the production binding is configured, the default binding is
 * dormant: it logs the intent and returns a synthetic `pending` /
 * `PAYMENT_DEFERRED` outcome so the polling-fallback workflow + webhook
 * controller stay observable in test + staging environments — exactly
 * the dormancy contract the existing `MolliePaymentAdapterInterface`
 * already meets, lifted to the lifecycle layer.
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

/**
 * DepositPayment lifecycle adapter port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is
 * set; a dormant adapter records the intent (logger, audit trail) and
 * returns a synthetic `pending` / `PAYMENT_DEFERRED` outcome so the
 * polling-fallback workflow + webhook controller can advance without
 * contacting the gateway.
 *
 * Activation steps for a real binding:
 *  1. Provision a payment gateway per tenant (Mollie production API
 *     key, or Stripe secret key) and bind it through the existing
 *     `MolliePaymentAdapterInterface` (openconnector source slug
 *     `mollie-payments`) or a sibling adapter.
 *  2. Override the DepositPaymentAdapterInterface DI binding in
 *     `Application::register()` to a production implementation that
 *     delegates to the configured PSP adapter — e.g. a
 *     `MollieDepositPaymentAdapter` that resolves a
 *     `MolliePaymentAdapterInterface` and projects the Mollie payment
 *     status onto the DepositPayment lifecycle state.
 *  3. Set the gateway secrets / webhook HMAC in openconnector — the
 *     `DepositWebhookController` already verifies signatures
 *     constant-time per REQ-DP-001.
 *
 * @spec openspec/specs/bookings-deposits/spec.md
 */
interface DepositPaymentAdapterInterface {
	/**
	 * Request a payment for a DepositPayment record.
	 *
	 * @param array<string,mixed> $payload Request envelope —
	 *                                     depositPaymentId, amount
	 *                                     {value,currency},
	 *                                     description, redirectUrl,
	 *                                     webhookUrl, methodHint
	 *                                     (optional — `ideal` |
	 *                                     `creditcard` | `bancontact` |
	 *                                     `sepadirectdebit`),
	 *                                     metadata{orderId,
	 *                                     administrationId,
	 *                                     correlationId}.
	 *
	 * @return DepositPaymentResult The dispatch outcome (lifecycle
	 *                              state + gateway-side intent id +
	 *                              paymentLink).
	 *
	 * @spec openspec/specs/bookings-cancellation-rules/spec.md
	 */
	public function requestPayment(array $payload): DepositPaymentResult;

	/**
	 * Capture a previously-authorized charge (authorise-now /
	 * capture-later rail).
	 *
	 * Used by the no-show-fee-capture flow (bookings-depth): when a
	 * card hold / deposit authorization already exists, the defined
	 * no-show fee is captured against that authorization instead of
	 * opening a fresh payment. Implementations MUST clamp the captured
	 * amount to the authorized amount and MUST be side-effect-free when
	 * dormant (returning a synthetic `captured` / `PAYMENT_DEFERRED`
	 * outcome so the surrounding lifecycle stays observable).
	 *
	 * @param string $paymentIntentId Gateway-side
	 *                                authorization intent
	 *                                id to capture against.
	 * @param array<string,mixed> $payload Capture envelope —
	 *                                     amount {value,currency},
	 *                                     reason (`noShowFee` |
	 *                                     `operatorCapture`),
	 *                                     metadata{
	 *                                     appointmentId,
	 *                                     administrationId,
	 *                                     correlationId}.
	 *
	 * @return DepositPaymentResult The capture outcome (lifecycle state
	 *                              `captured` on success).
	 *
	 * @spec openspec/specs/bookings-cancellation-rules/spec.md
	 */
	public function capturePayment(string $paymentIntentId, array $payload): DepositPaymentResult;

	/**
	 * Fetch the current gateway status for an open
	 * DepositPayment intent.
	 *
	 * @param string $paymentIntentId Gateway-side intent id.
	 * @param string $depositPaymentId DepositPayment record id (audit
	 *                                 correlation key).
	 *
	 * @return DepositPaymentResult The status outcome (lifecycle state
	 *                              + raw gateway status).
	 */
	public function fetchStatus(string $paymentIntentId, string $depositPaymentId): DepositPaymentResult;

	/**
	 * Initiate a refund for an authorized / captured DepositPayment.
	 *
	 * @param string $paymentIntentId Gateway-side intent id.
	 * @param array<string,mixed> $payload Refund envelope —
	 *                                     amount {value,currency},
	 *                                     reason
	 *                                     (`bookingCancelled` |
	 *                                     `operatorRefund` |
	 *                                     `chargeback`),
	 *                                     metadata{
	 *                                     depositPaymentId,
	 *                                     creditNoteId,
	 *                                     correlationId}.
	 *
	 * @return DepositPaymentResult The refund outcome (lifecycle state
	 *                              `voided` on success).
	 */
	public function initiateRefund(string $paymentIntentId, array $payload): DepositPaymentResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * a payment gateway.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 */
	public function isDormant(): bool;
}//end interface
