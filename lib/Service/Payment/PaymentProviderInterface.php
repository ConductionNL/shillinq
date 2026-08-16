<?php

/**
 * Payment-provider port for the portal pay-now flow (portal-payment-initiation).
 *
 * This is the seam PortalPaymentSessionService drives to mint an iDEAL payment
 * session for a subject-initiated "pay my invoice" action. It sits ABOVE the
 * existing `MolliePaymentAdapterInterface` — the exact same layering
 * `DepositPaymentAdapterInterface` already established for the bookings-deposits
 * lifecycle (`lib/Service/External/DepositPayment/DepositPaymentAdapterInterface.php`)
 * — so the portal receiver never sees a Mollie-vs-other-PSP branch, only the
 * projected session outcome. Unlike DepositPaymentAdapterInterface's still-dormant
 * default, THIS port's shipped binding (`MolliePaymentProvider`) delegates to the
 * existing, verified `MolliePaymentAdapterInterface` from day one (REQ-SPPI-001) —
 * the whole point of this change is to CONSUME the already-shipped Mollie plumbing,
 * not add another dormant layer. Its dormancy therefore mirrors whatever
 * `MolliePaymentAdapterInterface` binding is currently active: dormant
 * (LogMolliePaymentAdapter) until the openconnector `mollie-payments` source is
 * bound, live automatically once it is — no further change needed here.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Payment
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Payment;

/**
 * Payment-provider port.
 *
 * Implementations MUST be side-effect-free when dormant — returning a
 * `PaymentSessionResult` with `dormant: true` and an empty `checkoutUrl`
 * rather than fabricating one, so the initiation endpoint can degrade
 * honestly (REQ-SPPI-001).
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
 */
interface PaymentProviderInterface {
	/**
	 * Request a payment session (iDEAL) for the given envelope.
	 *
	 * @param PaymentSessionRequest $request The session request — amount/currency
	 *                                       MUST already be server-resolved.
	 *
	 * @return PaymentSessionResult The session outcome.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
	 */
	public function createSession(PaymentSessionRequest $request): PaymentSessionResult;

	/**
	 * Whether the bound provider is dormant (not contacting a real PSP).
	 *
	 * @return bool True when the adapter is a log-only stub.
	 *
	 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
	 */
	public function isDormant(): bool;
}//end interface
