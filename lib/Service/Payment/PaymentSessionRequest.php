<?php

/**
 * Value object requesting an iDEAL payment session (portal-payment-initiation).
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
 * Envelope handed to PaymentProviderInterface::createSession(). Every value is
 * server-derived — the caller MUST populate amount/currency from the
 * server-resolved ARInvoice/PaymentRequest, never from client input
 * (REQ-SPPI-004).
 *
 * @spec openspec/specs/portal-payment-initiation/spec.md (REQ-SPPI-001)
 */
final class PaymentSessionRequest {
	/**
	 * Construct the session request.
	 *
	 * @param float $amount Server-resolved outstanding amount.
	 * @param string $currency ISO 4217 currency code.
	 * @param string $description Payer-facing description (e.g. invoice number).
	 * @param string $redirectUrl Where the payer lands after the PSP flow.
	 * @param string $webhookUrl The shared, signature-verified webhook endpoint.
	 * @param string $method Payment method — always 'ideal' for this
	 *                       flow.
	 * @param array<string, mixed> $metadata Correlation metadata (invoiceId, administrationId, correlationId).
	 */
	public function __construct(
		public readonly float $amount,
		public readonly string $currency,
		public readonly string $description,
		public readonly string $redirectUrl,
		public readonly string $webhookUrl,
		public readonly string $method,
		public readonly array $metadata = [],
	) {
	}//end __construct()
}//end class
