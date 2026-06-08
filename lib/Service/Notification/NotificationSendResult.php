<?php

/**
 * Immutable result of one booking notification dispatch attempt.
 *
 * A value object describing the outcome of a single (recipient, channel,
 * trigger) attempt produced by BookingNotificationService::dispatch. The
 * orchestration layer maps these onto NotificationDelivery audit records;
 * the helpers themselves stay free of OR-side state so the gate semantics
 * (opt-out / rate-limit / dedupe / condition) can be unit-tested in
 * isolation. PII is never put on the wire — the channel/adapter records
 * its own masked recipient identifier; this object only carries a
 * machine-readable status + reason.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Notification;

/**
 * PII-free outcome value object for one booking-notification dispatch attempt.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
 */
final class NotificationSendResult
{

    /**
     * Notification was accepted by the channel adapter.
     */
    public const STATUS_SENT = 'sent';

    /**
     * Notification rejected by the adapter after retries.
     */
    public const STATUS_FAILED = 'failed';

    /**
     * Notification gated by opt-out / dedupe / rate-limit / condition.
     */
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Notification rate-limited and held for admin review.
     */
    public const STATUS_QUEUED = 'queued';

    /**
     * Constructor.
     *
     * @param string      $status        One of STATUS_*.
     * @param string|null $channel       Channel used (email/sms/chat/…). Null when no channel reached.
     * @param string|null $skipReason    Machine-readable skip code (opt-out, deduplicated, rate-limit-booking, …).
     * @param string|null $failureReason Adapter / render error message (≤500 chars). Free of PII.
     * @param int         $retryCount    Number of fallback / retry attempts before this outcome (0 = first attempt).
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $channel=null,
        public readonly ?string $skipReason=null,
        public readonly ?string $failureReason=null,
        public readonly int $retryCount=0
    ) {

    }//end __construct()

    /**
     * True for sent results.
     *
     * @return bool
     */
    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }//end isSent()

    /**
     * True for skipped results (audited but not dispatched).
     *
     * @return bool
     */
    public function isSkipped(): bool
    {
        return $this->status === self::STATUS_SKIPPED;
    }//end isSkipped()

    /**
     * True for terminal failure (all channels / retries exhausted).
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }//end isFailed()
}//end class
