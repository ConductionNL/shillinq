<?php

/**
 * Rate-limit gate for booking notifications.
 *
 * REQ-BNT-006 caps dispatches at:
 *   - `rateLimitPerBookingPerHour`   (calendar hour, UTC, default 10)
 *   - `rateLimitPerOrganizerPerDay`  (calendar day, UTC, default 100)
 *
 * The gate composes counts from a NotificationCounterStoreInterface, keyed
 * by the calendar-window bucket so the count resets at the next hour /
 * day boundary without an explicit TTL. When a cap is reached the gate
 * returns a `queued` decision — the caller records that as a
 * NotificationDelivery with status=queued for admin review (REQ-BNT-008).
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
 * Calendar-window rate-limit gate (per-booking-hour + per-organizer-day).
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
 */
final class NotificationRateLimiter
{

    /**
     * Decision: dispatch allowed.
     */
    public const DECISION_ALLOW = 'allow';

    /**
     * Decision: blocked by per-booking-hour cap.
     */
    public const DECISION_RATE_LIMIT_BOOKING = 'rate-limit-booking';

    /**
     * Decision: blocked by per-organizer-day cap.
     */
    public const DECISION_RATE_LIMIT_ORGANIZER = 'rate-limit-organizer';

    /**
     * Constructor.
     *
     * @param NotificationCounterStoreInterface $store Counter store binding.
     */
    public function __construct(private readonly NotificationCounterStoreInterface $store)
    {

    }//end __construct()

    /**
     * Check whether a dispatch is allowed under the trigger's caps.
     *
     * @param array<string, mixed>   $trigger   Trigger config (rateLimit* fields).
     * @param string                 $bookingId Booking identifier.
     * @param string                 $organizer Organizer identifier (email / username).
     * @param DateTimeImmutable|null $at        Logical "now" (test injection); defaults to wall-clock UTC.
     *
     * @return string One of DECISION_*.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
     */
    public function check(array $trigger, string $bookingId, string $organizer, ?DateTimeImmutable $at=null): string
    {
        $now          = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $bookingCap   = (int) ($trigger['rateLimitPerBookingPerHour'] ?? 10);
        $organizerCap = (int) ($trigger['rateLimitPerOrganizerPerDay'] ?? 100);
        $bookingKey   = $this->bookingKey(bookingId: $bookingId, now: $now);
        $organizerKey = $this->organizerKey(organizer: $organizer, now: $now);
        $bookingCount = $this->store->get(key: $bookingKey);
        $organizerCnt = $this->store->get(key: $organizerKey);

        if ($bookingCap > 0 && $bookingCount >= $bookingCap) {
            return self::DECISION_RATE_LIMIT_BOOKING;
        }

        if ($organizerCap > 0 && $organizerCnt >= $organizerCap) {
            return self::DECISION_RATE_LIMIT_ORGANIZER;
        }

        return self::DECISION_ALLOW;
    }//end check()

    /**
     * Record a dispatch under both counters (call AFTER allow + send).
     *
     * @param string                 $bookingId Booking identifier.
     * @param string                 $organizer Organizer identifier.
     * @param DateTimeImmutable|null $at        Logical "now".
     *
     * @return void
     */
    public function record(string $bookingId, string $organizer, ?DateTimeImmutable $at=null): void
    {
        $now = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->store->increment(key: $this->bookingKey(bookingId: $bookingId, now: $now), ttl: 3600);
        $this->store->increment(key: $this->organizerKey(organizer: $organizer, now: $now), ttl: 86400);
    }//end record()

    /**
     * Reset both counters for one booking + organizer (admin action).
     *
     * @param string                 $bookingId Booking identifier.
     * @param string                 $organizer Organizer identifier.
     * @param DateTimeImmutable|null $at        Logical "now".
     *
     * @return void
     */
    public function reset(string $bookingId, string $organizer, ?DateTimeImmutable $at=null): void
    {
        $now = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->store->reset(key: $this->bookingKey(bookingId: $bookingId, now: $now));
        $this->store->reset(key: $this->organizerKey(organizer: $organizer, now: $now));
    }//end reset()

    /**
     * Build the per-booking-hour key (YYYYMMDDHH bucket).
     *
     * @param string            $bookingId Booking identifier.
     * @param DateTimeImmutable $now       Logical now.
     *
     * @return string
     */
    private function bookingKey(string $bookingId, DateTimeImmutable $now): string
    {
        return 'shillinq:notif:booking-hour:'.$bookingId.':'.$now->format('YmdH');
    }//end bookingKey()

    /**
     * Build the per-organizer-day key (YYYYMMDD bucket).
     *
     * @param string            $organizer Organizer identifier.
     * @param DateTimeImmutable $now       Logical now.
     *
     * @return string
     */
    private function organizerKey(string $organizer, DateTimeImmutable $now): string
    {
        return 'shillinq:notif:organizer-day:'.$organizer.':'.$now->format('Ymd');
    }//end organizerKey()
}//end class
