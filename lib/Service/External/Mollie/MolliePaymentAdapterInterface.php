<?php

/**
 * Mollie Payments adapter port.
 *
 * Mollie is a Dutch PSP widely used by the MKB to collect
 * one-off + recurring iDEAL / SEPA / card / Bancontact payments.
 * Shillinq materialises payment intents through Mollie on two
 * lifecycles:
 *  1. AR collection — the
 *     `bookkeeping-accounts-receivable-core` cycle attaches a
 *     payment link to an outbound invoice and listens for the
 *     `payments.paid` webhook to advance the invoice into
 *     `paid` state without operator intervention.
 *  2. Deposit-at-booking — the `bookings-deposits` cycle creates
 *     a `DepositPayment` record at booking time and routes the
 *     tokenised intent through Mollie so the booking can advance
 *     `pending_payment → confirmed` once Mollie reports
 *     `authorized` / `paid`.
 *
 * The port is intentionally narrow — `createPayment()` returning a
 * structured result — so the
 * production binding (openconnector source slug `mollie-payments`,
 * per-tenant Mollie API key + webhook HMAC secret) can be swapped in
 * via `Application::register()` without touching the AR / bookings
 * orchestrators. Inbound webhook signature verification is NOT part of
 * this port: the shared, fail-closed HMAC gate lives on the
 * `#[PublicPage]` webhook controllers (`PaymentRequestWebhookController`
 * + `DepositWebhookController`, one implementation per REQ-APL-004
 * "ONE shared surface — never a fork"). Until that binding is configured, the default
 * binding is dormant: it logs the intent and returns a synthetic
 * PAYMENT_DEFERRED outcome so the surrounding lifecycle stays
 * observable in test + staging environments.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Mollie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://docs.mollie.com/reference/v2/payments-api/create-payment
 *
 * @spec openspec/specs/bookings-deposits/spec.md
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\External\Mollie;

/**
 * Mollie Payments adapter port.
 *
 * Implementations MUST be side-effect-free when the dormant flag is set;
 * a dormant adapter records the intent (logger, audit trail) and
 * returns a synthetic PAYMENT_DEFERRED outcome so the surrounding
 * lifecycle (AR collection, booking deposit) can advance into
 * `awaiting-mollie` without contacting Mollie.
 *
 * Activation steps for a real Mollie binding:
 *  1. Provision a Mollie live API key + register the webhook URL
 *     (`/apps/shillinq/api/external/mollie/webhook`) in the Mollie
 *     dashboard.
 *  2. Create an openconnector source with slug
 *     `mollie-payments`, pointing at the Mollie Payments API v2
 *     endpoint (`api.mollie.com/v2`) and storing the API key + the
 *     HMAC secret openconnector will use to verify webhook bodies.
 *  3. Override the MolliePaymentAdapterInterface DI binding in
 *     `Application::register()` to the openconnector-backed
 *     implementation.
 *
 * @spec openspec/specs/bookings-deposits/spec.md
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */
interface MolliePaymentAdapterInterface {
	/**
	 * Create a Mollie payment intent.
	 *
	 * @param array<string,mixed> $payload Payment envelope —
	 *                                     amount{value,currency},
	 *                                     description, redirectUrl,
	 *                                     webhookUrl, method (optional —
	 *                                     `ideal` | `creditcard` |
	 *                                     `bancontact` | `sepadirectdebit`),
	 *                                     metadata{invoiceId | depositPaymentId,
	 *                                     administrationId, correlationId}.
	 *
	 * @return MolliePaymentResult The dispatch outcome (status +
	 *                             Mollie-side paymentId + checkoutUrl).
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function createPayment(array $payload): MolliePaymentResult;

	/**
	 * Whether the adapter is dormant — i.e. wired but not contacting
	 * Mollie.
	 *
	 * @return bool TRUE when the adapter is a log-only stub.
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md
	 */
	public function isDormant(): bool;
}//end interface
