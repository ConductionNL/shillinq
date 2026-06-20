<?php

/**
 * Dormant default Mollie Payments adapter.
 *
 * Records the would-be payment intent / webhook verification to the
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
 * @spec openspec/changes/bookings-deposits/specs/bookings-deposits/spec.md
 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs/bookkeeping-accounts-receivable-core/spec.md
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
 * @spec openspec/changes/bookings-deposits/specs/bookings-deposits/spec.md
 * @spec openspec/changes/bookkeeping-accounts-receivable-core/specs/bookkeeping-accounts-receivable-core/spec.md
 */
class LogMolliePaymentAdapter implements MolliePaymentAdapterInterface
{
    /**
     * Construct the log-backed Mollie adapter.
     *
     * @param LoggerInterface $logger Structured logger.
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
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
     */
    public function createPayment(array $payload): MolliePaymentResult
    {
        $sanitised = $payload;
        unset($sanitised['redirectUrl'], $sanitised['webhookUrl']);

        $mollieId = 'tr_log_'.bin2hex(random_bytes(7));
        $this->logger->info(
            'Shillinq Mollie createPayment deferred (no outbound connector bound)',
            [
                'molliePaymentId' => $mollieId,
                'payload'         => $sanitised,
            ]
        );

        return new MolliePaymentResult(
            paymentStatus: 'PAYMENT_DEFERRED',
            molliePaymentId: $mollieId,
            checkoutUrl: '',
            dormant: true,
            extras: [
                'reason' => 'no-outbound-connector-bound',
                'note'   => 'Bind openconnector source slug `mollie-payments` (Mollie Payments API v2, per-tenant API key + webhook HMAC secret) and override MolliePaymentAdapterInterface in Application::register() to enable real transport.',
            ],
        );
    }//end createPayment()

    /**
     * Log the webhook intent + synthesise a stub result.
     *
     * @param string               $mollieId Mollie paymentId from inbound webhook.
     * @param array<string,string> $headers  Request headers.
     *
     * @return MolliePaymentResult Stubbed payment record.
     */
    public function verifyWebhook(string $mollieId, array $headers): MolliePaymentResult
    {
        // The signature header itself is logged at DEBUG since a leaked
        // signature could enable replay; the rest of the headers go
        // through unredacted so the audit trail records the inbound
        // shape.
        $signature = $headers['Mollie-Signature'] ?? ($headers['mollie-signature'] ?? '');
        unset($headers['Mollie-Signature'], $headers['mollie-signature']);

        $this->logger->info(
            'Shillinq Mollie verifyWebhook deferred (no outbound connector bound)',
            [
                'molliePaymentId'   => $mollieId,
                'headers'           => $headers,
                'signaturePresence' => ($signature === '' ? 'absent' : 'present'),
            ]
        );

        return new MolliePaymentResult(
            paymentStatus: 'PAYMENT_DEFERRED',
            molliePaymentId: $mollieId,
            checkoutUrl: '',
            dormant: true,
            extras: [
                'reason' => 'no-outbound-connector-bound',
                'note'   => 'Bind openconnector source slug `mollie-payments` + verify webhook HMAC in openconnector to enable real verification.',
            ],
        );
    }//end verifyWebhook()

    /**
     * @inheritDoc
     */
    public function isDormant(): bool
    {
        return true;
    }//end isDormant()
}//end class
