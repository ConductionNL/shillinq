<?php

/**
 * Sliding-window deduplication gate for booking notifications.
 *
 * REQ-BNT-006 mandates that if the same (recipient, triggerType, bookingId)
 * tuple already dispatched within `deduplicationWindowMinutes` (default 5
 * minutes), the second dispatch is skipped. This guards against duplicate
 * lifecycle events firing the same trigger twice (e.g. on a retry).
 *
 * The gate composes a SHA-1 of the tuple keyed by the window bucket; if
 * the counter has been seen this window, the dispatch is deduplicated.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Sliding-window dedupe gate.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
 */
final class NotificationDeduplicator
{
    /**
     * Constructor.
     *
     * @param NotificationCounterStoreInterface $store Counter store binding.
     */
    public function __construct(private readonly NotificationCounterStoreInterface $store)
    {

    }//end __construct()

    /**
     * Check whether the (recipient, triggerType, bookingId) tuple has
     * been dispatched within the trigger's dedupe window.
     *
     * @param array<string, mixed>   $trigger   Trigger config.
     * @param string                 $recipient Recipient address.
     * @param string                 $bookingId Booking identifier.
     * @param DateTimeImmutable|null $at        Logical "now".
     *
     * @return bool True when this is a duplicate (skip), false when fresh.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
     */
    public function isDuplicate(array $trigger, string $recipient, string $bookingId, ?DateTimeImmutable $at=null): bool
    {
        $key = $this->key(trigger: $trigger, recipient: $recipient, bookingId: $bookingId, at: $at);
        return ($this->store->get(key: $key) > 0);
    }//end isDuplicate()

    /**
     * Record a fresh dispatch in the dedupe window.
     *
     * @param array<string, mixed>   $trigger   Trigger config.
     * @param string                 $recipient Recipient address.
     * @param string                 $bookingId Booking identifier.
     * @param DateTimeImmutable|null $at        Logical "now".
     *
     * @return void
     */
    public function record(array $trigger, string $recipient, string $bookingId, ?DateTimeImmutable $at=null): void
    {
        $key    = $this->key(trigger: $trigger, recipient: $recipient, bookingId: $bookingId, at: $at);
        $window = (int) ($trigger['deduplicationWindowMinutes'] ?? 5);
        $ttl    = max(60, ($window * 60));
        $this->store->increment(key: $key, ttl: $ttl);
    }//end record()

    /**
     * Build the dedupe key.
     *
     * @param array<string, mixed>   $trigger   Trigger config.
     * @param string                 $recipient Recipient address.
     * @param string                 $bookingId Booking identifier.
     * @param DateTimeImmutable|null $at        Logical "now".
     *
     * @return string
     */
    private function key(array $trigger, string $recipient, string $bookingId, ?DateTimeImmutable $at=null): string
    {
        $now       = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $window    = max(1, (int) ($trigger['deduplicationWindowMinutes'] ?? 5));
        $type      = (string) ($trigger['triggerType'] ?? '');
        $bucket    = (int) floor(((int) $now->format('U')) / ($window * 60));
        $tuple     = $type.'|'.$recipient.'|'.$bookingId;
        $tupleHash = sha1($tuple);
        return 'shillinq:notif:dedupe:'.$bucket.':'.$tupleHash;
    }//end key()
}//end class
