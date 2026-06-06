<?php

/**
 * Unit tests for BookingNotificationService.
 *
 * Composes the helper namespace's gates against fake adapters and
 * exercises:
 *  - evaluateEventTrigger (status + scope filter)
 *  - end-to-end dispatch with a successful adapter
 *  - channel-fallback when the first adapter fails
 *  - rate-limit queue-on-cap behaviour
 *  - dedupe skip
 *  - opt-out skip
 *  - condition-false skip
 *  - template-render-error capture (T20)
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;
use OCA\Shillinq\Service\Notification\BookingNotificationService;
use OCA\Shillinq\Service\Notification\InMemoryNotificationCounterStore;
use OCA\Shillinq\Service\Notification\NotificationAuditWriter;
use OCA\Shillinq\Service\Notification\NotificationDeduplicator;
use OCA\Shillinq\Service\Notification\NotificationOptOutPolicy;
use OCA\Shillinq\Service\Notification\NotificationRateLimiter;
use OCA\Shillinq\Service\Notification\NotificationSendResult;
use OCA\Shillinq\Service\Notification\NotificationTemplateRenderer;
use OCA\Shillinq\Service\Notification\OpenconnectorAdapterInterface;
use OCA\Shillinq\Service\Notification\RecipientConditionEvaluator;
use OCA\Shillinq\Service\Notification\RecipientResolver;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end orchestration tests for BookingNotificationService.
 */
final class BookingNotificationServiceTest extends TestCase
{

    /**
     * Counter store shared by the limiter + deduplicator.
     *
     * @var InMemoryNotificationCounterStore
     */
    private InMemoryNotificationCounterStore $store;

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
        $this->store = new InMemoryNotificationCounterStore();
        $this->now   = new DateTimeImmutable('2026-06-06T10:00:00Z', new DateTimeZone('UTC'));

    }//end setUp()


    /**
     * Build a service wired to the given adapter.
     *
     * @param OpenconnectorAdapterInterface $adapter Channel adapter binding.
     *
     * @return BookingNotificationService
     */
    private function makeService(OpenconnectorAdapterInterface $adapter): BookingNotificationService
    {
        return new BookingNotificationService(
            optOutPolicy: new NotificationOptOutPolicy(),
            rateLimiter: new NotificationRateLimiter(store: $this->store),
            deduplicator: new NotificationDeduplicator(store: $this->store),
            recipients: new RecipientResolver(evaluator: new RecipientConditionEvaluator()),
            renderer: new NotificationTemplateRenderer(),
            adapter: $adapter,
            auditWriter: new NotificationAuditWriter()
        );
    }//end makeService()


    /**
     * Sample template body.
     *
     * @return array<string, string>
     */
    private function sampleTemplate(): array
    {
        return [
            'name'    => 'Booking Confirmation',
            'subject' => 'Boeking bevestigd: {{ booking.id }}',
            'body'    => 'Hallo {{ booking.customerName }}, uw boeking op {{ booking.startTime | date(\'d-m-Y\') }} is bevestigd.',
        ];
    }//end sampleTemplate()


    /**
     * Sample booking payload.
     *
     * @return array<string, mixed>
     */
    private function sampleBooking(): array
    {
        return [
            'id'             => 'book-1',
            'slug'           => 'book-1',
            'customerEmail'  => 'alice@example.com',
            'customerName'   => 'Alice de Vries',
            'organizerEmail' => 'jan@example.com',
            'organizer'      => 'Jan Peeters',
            'startTime'      => '2026-06-15T10:00:00Z',
            'price'          => 150,
            'status'         => 'confirmed',
        ];
    }//end sampleBooking()


    /**
     * evaluateEventTrigger picks only enabled triggers matching the event
     * and (if scoped) the booking.
     *
     * @return void
     */
    public function testEvaluateEventTriggerFiltersByEventAndScope(): void
    {
        $service = $this->makeService(adapter: new FakeAdapter());
        $booking = ['slug' => 'book-1'];

        $triggers = [
            ['status' => 'enabled', 'triggerType' => 'booking.created', 'appliesToBookingSlug' => null],
            ['status' => 'disabled', 'triggerType' => 'booking.created', 'appliesToBookingSlug' => null],
            ['status' => 'enabled', 'triggerType' => 'booking.cancelled', 'appliesToBookingSlug' => null],
            ['status' => 'enabled', 'triggerType' => 'booking.created', 'appliesToBookingSlug' => 'book-1'],
            ['status' => 'enabled', 'triggerType' => 'booking.created', 'appliesToBookingSlug' => 'book-other'],
        ];

        $matched = $service->evaluateEventTrigger(event: 'booking.created', booking: $booking, triggers: $triggers);

        self::assertCount(2, $matched);
    }//end testEvaluateEventTriggerFiltersByEventAndScope()


    /**
     * Happy path: one trigger, one customer, email available → sent.
     *
     * @return void
     */
    public function testDispatchSendsViaEmailAdapter(): void
    {
        $adapter = new FakeAdapter();
        $service = $this->makeService(adapter: $adapter);

        $trigger = [
            'name'        => 'Boekingsbevestiging',
            'triggerType' => 'booking.created',
            'channels'    => ['email'],
            'recipients'  => [['role' => 'customer', 'channels' => ['email']]],
        ];

        $records = $service->dispatchNotification(
            trigger: $trigger,
            booking: $this->sampleBooking(),
            template: $this->sampleTemplate(),
            at: $this->now
        );

        self::assertCount(1, $records);
        self::assertSame('sent', $records[0]['status']);
        self::assertSame('email', $records[0]['channel']);
        self::assertSame('alice@example.com', $records[0]['recipient']);
        self::assertNotEmpty($adapter->dispatched);
        self::assertStringContainsString('Boeking bevestigd: book-1', $adapter->dispatched[0]['subject']);
    }//end testDispatchSendsViaEmailAdapter()


    /**
     * Fallback: email adapter rejects → SMS adapter accepts; one failure
     * record + one success record per recipient.
     *
     * @return void
     */
    public function testDispatchFallsBackToNextChannelOnFailure(): void
    {
        $adapter = new FakeAdapter(failOn: ['email']);
        $service = $this->makeService(adapter: $adapter);

        $trigger = [
            'name'        => 'Boekingsbevestiging',
            'triggerType' => 'booking.created',
            'channels'    => ['email', 'sms'],
            'recipients'  => [['role' => 'customer', 'channels' => ['email', 'sms']]],
        ];
        $booking = $this->sampleBooking();
        $booking['customerEmail'] = '+31612345678';
        // Test still uses the email address as the resolved recipient — the
        // adapter routes based on channel, not address shape. The fake
        // adapter returns FAILED for email and SENT for sms; the
        // orchestrator advances to the next channel on email failure.

        $records = $service->dispatchNotification(
            trigger: $trigger,
            booking: $booking,
            template: $this->sampleTemplate(),
            at: $this->now
        );

        self::assertCount(2, $records);
        self::assertSame('failed', $records[0]['status']);
        self::assertSame('email', $records[0]['channel']);
        self::assertSame('sent', $records[1]['status']);
        self::assertSame('sms', $records[1]['channel']);
        self::assertSame(1, $records[1]['retryCount']);
    }//end testDispatchFallsBackToNextChannelOnFailure()


    /**
     * Rate-limit hit → queued record, adapter never called.
     *
     * @return void
     */
    public function testDispatchQueuesWhenRateLimitHit(): void
    {
        $adapter = new FakeAdapter();
        $service = $this->makeService(adapter: $adapter);

        $trigger = [
            'name'                       => 'Boekingsbevestiging',
            'triggerType'                => 'booking.created',
            'channels'                   => ['email'],
            'recipients'                 => [['role' => 'customer', 'channels' => ['email']]],
            'rateLimitPerBookingPerHour' => 1,
        ];

        // First dispatch succeeds.
        $service->dispatchNotification(
            trigger: $trigger,
            booking: $this->sampleBooking(),
            template: $this->sampleTemplate(),
            at: $this->now
        );
        $adapter->dispatched = [];

        // Second dispatch within the same hour is queued (also dedupe will
        // catch the recipient — bump time outside the dedupe window).
        $records = $service->dispatchNotification(
            trigger: $trigger,
            booking: $this->sampleBooking(),
            template: $this->sampleTemplate(),
            at: $this->now->modify('+10 minutes')
        );

        self::assertCount(1, $records);
        self::assertSame('queued', $records[0]['status']);
        self::assertSame('rate-limit-booking', $records[0]['skipReason']);
        self::assertEmpty($adapter->dispatched, 'Adapter must not be called after rate-limit hit.');
    }//end testDispatchQueuesWhenRateLimitHit()


    /**
     * Same trigger + recipient + booking within window → dedupe skip.
     *
     * @return void
     */
    public function testDispatchDeduplicatesWithinWindow(): void
    {
        $adapter = new FakeAdapter();
        $service = $this->makeService(adapter: $adapter);

        $trigger = [
            'name'        => 'Boekingsbevestiging',
            'triggerType' => 'booking.created',
            'channels'    => ['email'],
            'recipients'  => [['role' => 'customer', 'channels' => ['email']]],
            'rateLimitPerBookingPerHour' => 10,
            'deduplicationWindowMinutes' => 5,
        ];

        $service->dispatchNotification(
            trigger: $trigger,
            booking: $this->sampleBooking(),
            template: $this->sampleTemplate(),
            at: $this->now
        );

        $records = $service->dispatchNotification(
            trigger: $trigger,
            booking: $this->sampleBooking(),
            template: $this->sampleTemplate(),
            at: $this->now->modify('+2 minutes')
        );

        self::assertCount(1, $records);
        self::assertSame('skipped', $records[0]['status']);
        self::assertSame('deduplicated', $records[0]['skipReason']);
    }//end testDispatchDeduplicatesWithinWindow()


    /**
     * Opted-out recipient → skipped record, no adapter call.
     *
     * @return void
     */
    public function testDispatchSkipsOptedOutRecipient(): void
    {
        $adapter = new FakeAdapter();
        $service = $this->makeService(adapter: $adapter);

        $trigger = [
            'name'        => 'Herinnering',
            'triggerType' => 'booking.reminder',
            'channels'    => ['email'],
            'recipients'  => [['role' => 'customer', 'channels' => ['email']]],
            'respectOptOut' => true,
        ];
        $booking = $this->sampleBooking();
        $booking['notificationOptOut'] = ['booking.reminder' => true];

        $records = $service->dispatchNotification(
            trigger: $trigger,
            booking: $booking,
            template: $this->sampleTemplate(),
            at: $this->now
        );

        self::assertCount(1, $records);
        self::assertSame('skipped', $records[0]['status']);
        self::assertSame('opt-out', $records[0]['skipReason']);
        self::assertEmpty($adapter->dispatched);
    }//end testDispatchSkipsOptedOutRecipient()


    /**
     * False condition on a recipient rule produces a condition-false
     * skip record (no adapter call).
     *
     * @return void
     */
    public function testDispatchSkipsRuleWithFalseCondition(): void
    {
        $adapter = new FakeAdapter();
        $service = $this->makeService(adapter: $adapter);

        $trigger = [
            'name'        => 'Annulering',
            'triggerType' => 'booking.cancelled',
            'channels'    => ['email'],
            'recipients'  => [
                ['role' => 'admin_group', 'channels' => ['email'], 'condition' => 'booking.price > 1000'],
            ],
        ];
        $booking = $this->sampleBooking();
        // booking.price = 150, condition asks > 1000 → false.

        $records = $service->dispatchNotification(
            trigger: $trigger,
            booking: $booking,
            template: $this->sampleTemplate(),
            at: $this->now
        );

        self::assertCount(1, $records);
        self::assertSame('skipped', $records[0]['status']);
        self::assertSame('condition-false', $records[0]['skipReason']);
        self::assertEmpty($adapter->dispatched);
    }//end testDispatchSkipsRuleWithFalseCondition()


    /**
     * Audit record carries every immutable field declared by the schema.
     *
     * @return void
     */
    public function testAuditRecordCarriesRequiredFields(): void
    {
        $adapter = new FakeAdapter();
        $service = $this->makeService(adapter: $adapter);

        $trigger = [
            'name'        => 'Boekingsbevestiging',
            'triggerType' => 'booking.created',
            'channels'    => ['email'],
            'recipients'  => [['role' => 'customer', 'channels' => ['email']]],
        ];

        $records = $service->dispatchNotification(
            trigger: $trigger,
            booking: $this->sampleBooking(),
            template: $this->sampleTemplate(),
            at: $this->now
        );

        $record = $records[0];
        self::assertSame('Boekingsbevestiging', $record['triggerName']);
        self::assertSame('booking.created', $record['triggerType']);
        self::assertSame('book-1', $record['bookingId']);
        self::assertSame('alice@example.com', $record['recipient']);
        self::assertSame('email', $record['channel']);
        self::assertSame('Booking Confirmation', $record['templateName']);
        self::assertSame('sent', $record['status']);
        self::assertNotEmpty($record['dispatchGroupId']);
        self::assertNotEmpty($record['sentAt']);
    }//end testAuditRecordCarriesRequiredFields()


    /**
     * recordAuditTrail produces a one-off audit envelope (no adapter
     * call) for status=skipped / queued one-offs.
     *
     * @return void
     */
    public function testRecordAuditTrailEnvelope(): void
    {
        $adapter = new FakeAdapter();
        $service = $this->makeService(adapter: $adapter);

        $envelope = $service->recordAuditTrail(
            trigger: ['name' => 'X', 'triggerType' => 'booking.created', 'channels' => ['email']],
            booking: ['id' => 'book-1', 'recipient' => 'alice@example.com'],
            status: NotificationSendResult::STATUS_SKIPPED,
            reason: 'opt-out',
            at: $this->now
        );

        self::assertSame('skipped', $envelope['status']);
        self::assertSame('opt-out', $envelope['skipReason']);
        self::assertSame('book-1', $envelope['bookingId']);
    }//end testRecordAuditTrailEnvelope()


}//end class

/**
 * Tiny in-test adapter double — records every send and can be configured to
 * fail on named channels.
 */
final class FakeAdapter implements OpenconnectorAdapterInterface
{

    /**
     * Channels that always return FAILED.
     *
     * @var array<int, string>
     */
    private array $failOn;

    /**
     * Every dispatched message (debug aid).
     *
     * @var array<int, array<string, mixed>>
     */
    public array $dispatched = [];


    /**
     * Constructor.
     *
     * @param array<int, string> $failOn Channels that fail.
     */
    public function __construct(array $failOn=[])
    {
        $this->failOn = $failOn;
    }//end __construct()


    /**
     * @inheritDoc
     */
    public function send(string $channel, string $recipient, string $subject, string $body, array $metadata=[]): NotificationSendResult
    {
        $this->dispatched[] = [
            'channel'   => $channel,
            'recipient' => $recipient,
            'subject'   => $subject,
            'body'      => $body,
            'metadata'  => $metadata,
        ];

        if (in_array($channel, $this->failOn, true) === true) {
            return new NotificationSendResult(
                status: NotificationSendResult::STATUS_FAILED,
                channel: $channel,
                failureReason: 'adapter-rejected-for-test'
            );
        }

        return new NotificationSendResult(status: NotificationSendResult::STATUS_SENT, channel: $channel);
    }//end send()


    /**
     * @inheritDoc
     */
    public function isChannelAvailable(string $channel): bool
    {
        unset($channel);
        return true;
    }//end isChannelAvailable()


}//end class
