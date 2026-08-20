<?php

/**
 * Dormant default Mollie Payments adapter.
 *
 * Records the would-be payment intent to the
 * structured logger and returns a synthetic PAYMENT_DEFERRED result
 * so the surrounding lifecycle (AR collection, booking-deposit
 * confirmation) stays observable until an openconnector-backed
 * binding to Mollie is wired in via `Application::register()`.
 * Mirrors the `LogIb47Adapter` / `LogCbsBestandenAdapter`
 * dormant-default pattern used across the Shillinq external surface.
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

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Mollie Payments adapter.
 *
 * @spec openspec/specs/bookings-deposits/spec.md
 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
 */
class LogMolliePaymentAdapter implements MolliePaymentAdapterInterface {
	/**
	 * Construct the log-backed Mollie adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the intent + synthesise a PAYMENT_DEFERRED result.
	 *
	 * The payment payload is logged in full minus the redirect /
	 * webhook URLs (which may carry tenant-scoped tokens). The
	 * customer-side `description` may surface an invoice number; we
	 * keep it because the audit trail needs it to correlate the
	 * dormant intent back to a Shillinq record once the live binding
	 * is provisioned.
	 *
	 * @param array<string,mixed> $payload Payment envelope.
	 *
	 * @return MolliePaymentResult The dispatch outcome.
	 *
	 * @spec openspec/specs/bookkeeping-accounts-receivable-core/spec.md
	 */
	public function createPayment(array $payload): MolliePaymentResult {
		$sanitised = $payload;
		unset($sanitised['redirectUrl'], $sanitised['webhookUrl']);

		$mollieId = 'tr_log_' . bin2hex(random_bytes(7));
		$this->logger->info(
			'Shillinq Mollie createPayment deferred (no outbound connector bound)',
			[
				'molliePaymentId' => $mollieId,
				'payload' => $sanitised,
			]
		);

		return new MolliePaymentResult(
			paymentStatus: 'PAYMENT_DEFERRED',
			molliePaymentId: $mollieId,
			checkoutUrl: '',
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind openconnector source slug `mollie-payments` (Mollie Payments API v2, '
					. 'per-tenant API key + webhook HMAC secret) and override MolliePaymentAdapterInterface '
					. 'in Application::register() to enable real transport.',
			],
		);
	}//end createPayment()

	/**
	 * Report whether this adapter is dormant.
	 *
	 * @return bool True when no outbound connector is bound.
	 *
	 * @inheritDoc
	 *
	 * @spec openspec/specs/bookings-deposits/spec.md
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
