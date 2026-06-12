<?php

/**
 * Unit tests for NotificationAuditWriter.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\Notification\NotificationAuditWriter;
use OCA\Shillinq\Service\Notification\NotificationSendResult;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the NotificationDelivery record builder (REQ-BNT-005).
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-8
 */
final class NotificationAuditWriterTest extends TestCase
{

    /**
     * Subject under test.
     */
    private NotificationAuditWriter $writer;


    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->writer = new NotificationAuditWriter();

    }//end setUp()


    /**
     * Happy path: a `sent` result writes the canonical envelope with the
     * supplied dispatch group + a frozen UTC timestamp.
     *
     * @return void
     */
    public function testBuildsSentRecordWithCanonicalEnvelope(): void
    {
        $result = new NotificationSendResult(NotificationSendResult::STATUS_SENT, channel: 'email', retryCount: 0);
        $at     = new DateTimeImmutable('2026-04-01T12:00:00', new DateTimeZone('UTC'));

        $record = $this->writer->build(
            ['name' => 'booking.confirmed', 'triggerType' => 'lifecycle'],
            'book-1',
            'cust@example.test',
            'email',
            'booking-confirmed-en',
            $result,
            'group-1234',
            $at
        );

        self::assertSame('booking.confirmed', $record['triggerName']);
        self::assertSame('lifecycle', $record['triggerType']);
        self::assertSame('book-1', $record['bookingId']);
        self::assertSame('cust@example.test', $record['recipient']);
        self::assertSame('email', $record['channel']);
        self::assertSame('booking-confirmed-en', $record['templateName']);
        self::assertSame(NotificationSendResult::STATUS_SENT, $record['status']);
        self::assertNull($record['skipReason']);
        self::assertNull($record['failureReason']);
        self::assertSame(0, $record['retryCount']);
        self::assertSame('group-1234', $record['dispatchGroupId']);
        self::assertSame('2026-04-01T12:00:00+00:00', $record['sentAt']);

    }//end testBuildsSentRecordWithCanonicalEnvelope()


    /**
     * A skipped result carries the skip reason; templateName may be null.
     *
     * @return void
     */
    public function testBuildsSkippedRecordWithSkipReason(): void
    {
        $result = new NotificationSendResult('skipped', skipReason: 'recipient opted out');

        $record = $this->writer->build(
            ['name' => 'booking.reminder.24h', 'triggerType' => 'schedule'],
            'book-2',
            '+31600000000',
            'sms',
            null,
            $result
        );

        self::assertSame('skipped', $record['status']);
        self::assertSame('recipient opted out', $record['skipReason']);
        self::assertNull($record['templateName']);
        self::assertSame(0, $record['retryCount']);

    }//end testBuildsSkippedRecordWithSkipReason()


    /**
     * A failed result carries the failure reason + retry count.
     *
     * @return void
     */
    public function testBuildsFailedRecordWithFailureReasonAndRetries(): void
    {
        $result = new NotificationSendResult(
            NotificationSendResult::STATUS_FAILED,
            channel: 'sms',
            failureReason: 'provider 502',
            retryCount: 3
        );

        $record = $this->writer->build(
            ['name' => 'booking.reminder.1h', 'triggerType' => 'schedule'],
            'book-3',
            '+31600000000',
            'sms',
            'reminder-1h-sms-en',
            $result
        );

        self::assertSame(NotificationSendResult::STATUS_FAILED, $record['status']);
        self::assertSame('provider 502', $record['failureReason']);
        self::assertSame(3, $record['retryCount']);
        // Auto-allocated dispatch group id when caller didn't supply one.
        self::assertNotEmpty($record['dispatchGroupId']);

    }//end testBuildsFailedRecordWithFailureReasonAndRetries()


    /**
     * Default `sentAt` is "now in UTC" (ATOM format ends with +00:00).
     *
     * @return void
     */
    public function testDefaultSentAtIsUtcAtom(): void
    {
        $result = new NotificationSendResult(NotificationSendResult::STATUS_SENT);

        $record = $this->writer->build(
            ['name' => 't'],
            'book-4',
            'r',
            'email',
            null,
            $result
        );

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/',
            $record['sentAt']
        );

    }//end testDefaultSentAtIsUtcAtom()


    /**
     * `newDispatchGroupId()` produces a v4-shaped UUID and successive calls
     * produce distinct ids.
     *
     * @return void
     */
    public function testNewDispatchGroupIdProducesV4ShapedUuid(): void
    {
        $a = $this->writer->newDispatchGroupId();
        $b = $this->writer->newDispatchGroupId();

        self::assertNotSame($a, $b);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $a
        );

    }//end testNewDispatchGroupIdProducesV4ShapedUuid()


    /**
     * Trigger envelope keys are optional — missing → '' so the audit row
     * has a stable shape.
     *
     * @return void
     */
    public function testMissingTriggerKeysDefaultToEmptyString(): void
    {
        $result = new NotificationSendResult(NotificationSendResult::STATUS_SENT);

        $record = $this->writer->build([], 'b', 'r', 'email', null, $result);

        self::assertSame('', $record['triggerName']);
        self::assertSame('', $record['triggerType']);

    }//end testMissingTriggerKeysDefaultToEmptyString()

}//end class
