<?php

/**
 * Value object returned by PaymentProviderInterface::createSession() (portal-payment-initiation).
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
 * The outcome of a payment-session request. `dormant` mirrors
 * MolliePaymentResult::$dormant — when true, `checkoutUrl` is empty and the
 * caller MUST surface a deferred/503 result rather than a fabricated URL
 * (REQ-SPPI-001).
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
 */
final class PaymentSessionResult {
	/**
	 * Construct the session result.
	 *
	 * @param bool $dormant True when the bound provider is a log-only stub.
	 * @param string $checkoutUrl Hosted-checkout URL — empty when
	 *                            dormant.
	 * @param string $paymentIntentId Provider-side intent id (synthetic when dormant).
	 * @param array<string, mixed> $extras Provider-specific extras.
	 */
	public function __construct(
		public readonly bool $dormant,
		public readonly string $checkoutUrl,
		public readonly string $paymentIntentId,
		public readonly array $extras = [],
	) {
	}//end __construct()
}//end class
