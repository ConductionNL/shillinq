<?php

/**
 * Default openconnector adapter binding: log + always-success.
 *
 * Used in dev and tests as a stand-in for the real HTTP adapter. Records
 * the dispatch attempt through Nextcloud's logger (without leaking the
 * recipient PII into the log payload — only a masked identifier) and
 * returns a `sent` result. A separate `FailingOpenconnectorAdapter` test
 * double exercises the failure-fallback path.
 *
 * Production deployments swap this for an HTTP-backed implementation that
 * invokes the openconnector REST API.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Notification;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Log-only openconnector adapter (default DI binding).
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-5
 */
final class LogOpenconnectorAdapter implements OpenconnectorAdapterInterface
{

    /**
     * Logger sink.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param LoggerInterface|null $logger Optional logger (defaults to NullLogger).
     */
    public function __construct(?LoggerInterface $logger=null)
    {
        $this->logger = ($logger ?? new NullLogger());
    }//end __construct()

    /**
     * @inheritDoc
     */
    public function send(string $channel, string $recipient, string $subject, string $body, array $metadata=[]): NotificationSendResult
    {
        unset($body);
        $this->logger->info(
            'shillinq.notification.dispatch',
            [
                'channel'   => $channel,
                'recipient' => $this->mask(address: $recipient),
                'subject'   => $subject,
                'metadata'  => $metadata,
            ]
        );
        return new NotificationSendResult(
            status: NotificationSendResult::STATUS_SENT,
            channel: $channel
        );
    }//end send()

    /**
     * @inheritDoc
     */
    public function isChannelAvailable(string $channel): bool
    {
        unset($channel);
        // The log-only adapter is always "available" for dev / tests.
        return true;
    }//end isChannelAvailable()

    /**
     * Mask an address for log payloads (PII-safe).
     *
     * email   `alice@example.com` → `a***@example.com`
     * phone   `+31612345678`      → `+31***5678`
     * other   `xyz`               → `***`
     *
     * @param string $address Raw address.
     *
     * @return string Masked address.
     */
    private function mask(string $address): string
    {
        if (strpos($address, '@') !== false) {
            [$local, $domain] = explode('@', $address, 2);
            return (substr($local, 0, 1).'***@'.$domain);
        }

        if (strlen($address) > 4 && (str_starts_with($address, '+') === true || ctype_digit($address) === true)) {
            return (substr($address, 0, 3).'***'.substr($address, -4));
        }

        return '***';
    }//end mask()
}//end class
