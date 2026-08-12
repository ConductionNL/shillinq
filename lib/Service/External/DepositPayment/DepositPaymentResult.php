<?php

/**
 * Result value-object returned by a DepositPaymentAdapter call.
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
 * Result of a DepositPayment lifecycle operation
 * (request / status / refund).
 *
 * `lifecycleState` mirrors the `DepositPayment` register lifecycle
 * (REQ-DP-003/008): `draft`, `pending`, `authorized`, `captured`,
 * `failed`, `voided`. `gatewayStatus` is the raw provider state, e.g.
 * the Mollie payment status (`open`, `pending`, `authorized`, `paid`,
 * `expired`, `canceled`, `failed`, `refunded`). `dormant=true` means
 * the adapter logged the intent but did not contact the gateway —
 * `lifecycleState` is then a synthetic projection so the surrounding
 * lifecycle stays observable.
 *
 * @spec openspec/specs/bookings-deposits/spec.md
 */
final class DepositPaymentResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $lifecycleState DepositPayment lifecycle
	 *                               state (draft | pending |
	 *                               authorized | captured |
	 *                               failed | voided).
	 * @param string $gatewayStatus Raw gateway status (e.g.
	 *                              Mollie payment status,
	 *                              `PAYMENT_DEFERRED` for
	 *                              dormant).
	 * @param string $paymentIntentId Gateway-side intent id
	 *                                (synthetic for dormant).
	 * @param string $paymentLink Hosted-checkout URL
	 *                            — empty for
	 *                            non-request
	 *                            operations or
	 *                            dormant.
	 * @param string $gateway Payment gateway slug
	 *                        (`mollie`, `stripe`,
	 *                        `LOG_DEFERRED`).
	 * @param bool $dormant TRUE when the adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific extras
	 *                                    (method, refund amount,
	 *                                    lastErrorCode,
	 *                                    lastErrorMessage).
	 */
	public function __construct(
		public readonly string $lifecycleState,
		public readonly string $gatewayStatus,
		public readonly string $paymentIntentId,
		public readonly string $paymentLink,
		public readonly string $gateway,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
