<?php

/**
 * Unit tests for NotificationRateLimiter.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Notification
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

namespace OCA\Shillinq\Tests\Unit\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\Notification\InMemoryNotificationCounterStore;
use OCA\Shillinq\Service\Notification\NotificationRateLimiter;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the per-booking-hour and per-organizer-day caps + reset action.
 */
final class NotificationRateLimiterTest extends TestCase
{

    /**
     * Counter store backing the limiter.
     *
     * @var InMemoryNotificationCounterStore
     */
    private InMemoryNotificationCounterStore $store;

    /**
     * Subject under test.
     *
     * @var NotificationRateLimiter
     */
    private NotificationRateLimiter $limiter;

    /**
     * Frozen "now" for deterministic tests.
     *
     * @var DateTimeImmutable
     */
    private DateTimeImmutable $now;


    /**
     * Set up the subject.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->store   = new InMemoryNotificationCounterStore();
        $this->limiter = new NotificationRateLimiter(store: $this->store);
        $this->now     = new DateTimeImmutable('2026-06-06T10:00:00Z', new DateTimeZone('UTC'));

    }//end setUp()


    /**
     * Initial check allows the dispatch.
     *
     * @return void
     */
    public function testAllowsFirstDispatch(): void
    {
        $trigger = ['rateLimitPerBookingPerHour' => 10, 'rateLimitPerOrganizerPerDay' => 100];

        $decision = $this->limiter->check(
            trigger: $trigger,
            bookingId: 'book-1',
            organizer: 'jan@example.com',
            at: $this->now
        );

        self::assertSame(NotificationRateLimiter::DECISION_ALLOW, $decision);
    }//end testAllowsFirstDispatch()


    /**
     * Hits the per-booking-hour cap after `rateLimitPerBookingPerHour` records.
     *
     * @return void
     */
    public function testBlocksAtPerBookingHourCap(): void
    {
        $trigger = ['rateLimitPerBookingPerHour' => 3, 'rateLimitPerOrganizerPerDay' => 100];

        for ($i = 0; $i < 3; $i++) {
            $decision = $this->limiter->check(trigger: $trigger, bookingId: 'book-1', organizer: 'jan@example.com', at: $this->now);
            self::assertSame(NotificationRateLimiter::DECISION_ALLOW, $decision, 'attempt #'.$i);
            $this->limiter->record(bookingId: 'book-1', organizer: 'jan@example.com', at: $this->now);
        }

        // Fourth attempt is blocked.
        $decision = $this->limiter->check(trigger: $trigger, bookingId: 'book-1', organizer: 'jan@example.com', at: $this->now);
        self::assertSame(NotificationRateLimiter::DECISION_RATE_LIMIT_BOOKING, $decision);
    }//end testBlocksAtPerBookingHourCap()


    /**
     * Per-organizer-day cap fires independently of per-booking-hour.
     *
     * @return void
     */
    public function testBlocksAtPerOrganizerDayCap(): void
    {
        $trigger = ['rateLimitPerBookingPerHour' => 100, 'rateLimitPerOrganizerPerDay' => 2];

        $this->limiter->record(bookingId: 'book-1', organizer: 'jan@example.com', at: $this->now);
        $this->limiter->record(bookingId: 'book-2', organizer: 'jan@example.com', at: $this->now);

        $decision = $this->limiter->check(trigger: $trigger, bookingId: 'book-3', organizer: 'jan@example.com', at: $this->now);
        self::assertSame(NotificationRateLimiter::DECISION_RATE_LIMIT_ORGANIZER, $decision);
    }//end testBlocksAtPerOrganizerDayCap()


    /**
     * Counter resets at the next-hour boundary (calendar-window keys).
     *
     * @return void
     */
    public function testResetsAtNextHourBoundary(): void
    {
        $trigger = ['rateLimitPerBookingPerHour' => 1, 'rateLimitPerOrganizerPerDay' => 100];

        $this->limiter->record(bookingId: 'book-1', organizer: 'jan@example.com', at: $this->now);

        $blocked = $this->limiter->check(trigger: $trigger, bookingId: 'book-1', organizer: 'jan@example.com', at: $this->now);
        self::assertSame(NotificationRateLimiter::DECISION_RATE_LIMIT_BOOKING, $blocked);

        $nextHour = $this->now->modify('+1 hour');
        $decision = $this->limiter->check(trigger: $trigger, bookingId: 'book-1', organizer: 'jan@example.com', at: $nextHour);
        self::assertSame(NotificationRateLimiter::DECISION_ALLOW, $decision);
    }//end testResetsAtNextHourBoundary()


    /**
     * Admin reset clears both counters for one (booking, organizer).
     *
     * @return void
     */
    public function testResetClearsCounters(): void
    {
        $trigger = ['rateLimitPerBookingPerHour' => 1, 'rateLimitPerOrganizerPerDay' => 100];

        $this->limiter->record(bookingId: 'book-1', organizer: 'jan@example.com', at: $this->now);
        self::assertSame(NotificationRateLimiter::DECISION_RATE_LIMIT_BOOKING, $this->limiter->check(trigger: $trigger, bookingId: 'book-1', organizer: 'jan@example.com', at: $this->now));

        $this->limiter->reset(bookingId: 'book-1', organizer: 'jan@example.com', at: $this->now);
        self::assertSame(NotificationRateLimiter::DECISION_ALLOW, $this->limiter->check(trigger: $trigger, bookingId: 'book-1', organizer: 'jan@example.com', at: $this->now));
    }//end testResetClearsCounters()


}//end class
