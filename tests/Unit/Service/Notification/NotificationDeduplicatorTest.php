<?php

/**
 * Unit tests for NotificationDeduplicator.
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
use OCA\Shillinq\Service\Notification\NotificationDeduplicator;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the (recipient, triggerType, bookingId) dedupe gate over a
 * sliding window.
 */
final class NotificationDeduplicatorTest extends TestCase
{

    /**
     * Counter store binding.
     *
     * @var InMemoryNotificationCounterStore
     */
    private InMemoryNotificationCounterStore $store;

    /**
     * Subject under test.
     *
     * @var NotificationDeduplicator
     */
    private NotificationDeduplicator $dedupe;


    /**
     * Set up the subject.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->store  = new InMemoryNotificationCounterStore();
        $this->dedupe = new NotificationDeduplicator(store: $this->store);

    }//end setUp()


    /**
     * Fresh tuple is not a duplicate.
     *
     * @return void
     */
    public function testFreshTupleIsNotDuplicate(): void
    {
        $trigger = ['triggerType' => 'booking.created', 'deduplicationWindowMinutes' => 5];

        $isDup = $this->dedupe->isDuplicate(
            trigger: $trigger,
            recipient: 'alice@example.com',
            bookingId: 'book-1',
            at: new DateTimeImmutable('2026-06-06T10:00:00Z', new DateTimeZone('UTC'))
        );

        self::assertFalse($isDup);
    }//end testFreshTupleIsNotDuplicate()


    /**
     * Re-dispatch inside the window is flagged as a duplicate.
     *
     * @return void
     */
    public function testRecordThenSecondCheckIsDuplicate(): void
    {
        $trigger = ['triggerType' => 'booking.created', 'deduplicationWindowMinutes' => 5];
        $now     = new DateTimeImmutable('2026-06-06T10:00:00Z', new DateTimeZone('UTC'));

        $this->dedupe->record(trigger: $trigger, recipient: 'alice@example.com', bookingId: 'book-1', at: $now);

        $isDup = $this->dedupe->isDuplicate(
            trigger: $trigger,
            recipient: 'alice@example.com',
            bookingId: 'book-1',
            at: $now->modify('+2 minutes')
        );

        self::assertTrue($isDup);
    }//end testRecordThenSecondCheckIsDuplicate()


    /**
     * Different recipient → different dedupe key → no duplicate.
     *
     * @return void
     */
    public function testDifferentRecipientNotDuplicate(): void
    {
        $trigger = ['triggerType' => 'booking.created', 'deduplicationWindowMinutes' => 5];
        $now     = new DateTimeImmutable('2026-06-06T10:00:00Z', new DateTimeZone('UTC'));

        $this->dedupe->record(trigger: $trigger, recipient: 'alice@example.com', bookingId: 'book-1', at: $now);

        $isDup = $this->dedupe->isDuplicate(
            trigger: $trigger,
            recipient: 'bob@example.com',
            bookingId: 'book-1',
            at: $now
        );

        self::assertFalse($isDup);
    }//end testDifferentRecipientNotDuplicate()


    /**
     * Different trigger type → different dedupe key → no duplicate.
     *
     * @return void
     */
    public function testDifferentTriggerTypeNotDuplicate(): void
    {
        $now = new DateTimeImmutable('2026-06-06T10:00:00Z', new DateTimeZone('UTC'));

        $this->dedupe->record(
            trigger: ['triggerType' => 'booking.created', 'deduplicationWindowMinutes' => 5],
            recipient: 'alice@example.com',
            bookingId: 'book-1',
            at: $now
        );

        $isDup = $this->dedupe->isDuplicate(
            trigger: ['triggerType' => 'booking.cancelled', 'deduplicationWindowMinutes' => 5],
            recipient: 'alice@example.com',
            bookingId: 'book-1',
            at: $now
        );

        self::assertFalse($isDup);
    }//end testDifferentTriggerTypeNotDuplicate()


    /**
     * Re-dispatch after the window expires is allowed.
     *
     * @return void
     */
    public function testNextWindowIsFresh(): void
    {
        $trigger = ['triggerType' => 'booking.created', 'deduplicationWindowMinutes' => 5];
        $now     = new DateTimeImmutable('2026-06-06T10:00:00Z', new DateTimeZone('UTC'));

        $this->dedupe->record(trigger: $trigger, recipient: 'alice@example.com', bookingId: 'book-1', at: $now);

        $isDup = $this->dedupe->isDuplicate(
            trigger: $trigger,
            recipient: 'alice@example.com',
            bookingId: 'book-1',
            at: $now->modify('+10 minutes')
        );

        self::assertFalse($isDup);
    }//end testNextWindowIsFresh()


}//end class
