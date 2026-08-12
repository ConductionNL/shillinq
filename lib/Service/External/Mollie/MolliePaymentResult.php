<?php

/**
 * Result value-object returned by a Mollie Payments adapter call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\External\Mollie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
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
 * Result of a Mollie payment-intent / webhook-verification attempt.
 *
 * `paymentStatus` is one of `open`, `pending`, `authorized`,
 * `paid`, `failed`, `canceled`, `expired`, `refunded`,
 * `chargeback`, `PAYMENT_DEFERRED`. The first nine track the
 * Mollie-side state machine 1:1; `PAYMENT_DEFERRED` is the dormant
 * default so callers can persist a non-null payment reference even
 * when no outbound call took place.
 *
 * @spec openspec/specs/bookings-deposits/spec.md
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */
final class MolliePaymentResult {
	/**
	 * Construct the result value-object.
	 *
	 * @param string $paymentStatus Mollie-side state.
	 * @param string $molliePaymentId Mollie-side intent id
	 *                                (synthetic for dormant).
	 * @param string $checkoutUrl Hosted-checkout URL the
	 *                            payer is redirected to
	 *                            — empty string for
	 *                            dormant.
	 * @param bool $dormant TRUE when the adapter was
	 *                      dormant.
	 * @param array<string,mixed> $extras Provider-specific extras
	 *                                    (e.g. mode
	 *                                    `live`/`test`, method
	 *                                    actually selected,
	 *                                    paidAt, amountRefunded).
	 */
	public function __construct(
		public readonly string $paymentStatus,
		public readonly string $molliePaymentId,
		public readonly string $checkoutUrl,
		public readonly bool $dormant,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
