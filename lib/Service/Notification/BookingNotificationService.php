<?php

/**
 * Booking notification orchestration service.
 *
 * Composes the declarative trigger config (BookingNotificationTrigger) and
 * the pure-logic gates (opt-out, rate-limit, dedupe, condition, template
 * renderer) in the order OR's notification engine would at runtime. Each
 * dispatch attempt is recorded in NotificationDelivery via the audit
 * writer; channel fallback walks the trigger's channels[] array on
 * adapter failure (REQ-BNT-004).
 *
 * The service exposes three public entry points:
 *   - `evaluateEventTrigger(event, booking)` — given a fired lifecycle event
 *     and a booking payload, return the list of triggers that match.
 *   - `dispatchNotification(trigger, recipient, template, booking)` —
 *     run the full gate stack and emit one or more NotificationDelivery
 *     records (one per channel attempt).
 *   - `recordAuditTrail(notification, status, reason)` — convenience wrapper
 *     around the audit writer for non-dispatch outcomes.
 *
 * No live OR or HTTP I/O lives in this class — everything goes through
 * the OpenconnectorAdapterInterface port; the orchestration itself is
 * unit-testable against an InMemoryNotificationCounterStore + a fake
 * adapter, mirroring the SmsReminderDispatcher pattern.
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

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Composes the declarative trigger config and pure-logic gates into one
 * orchestrated booking-notification dispatch.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
 */
final class BookingNotificationService
{
    /**
     * Constructor.
     *
     * @param NotificationOptOutPolicy      $optOutPolicy Opt-out gate.
     * @param NotificationRateLimiter       $rateLimiter  Rate-limit gate.
     * @param NotificationDeduplicator      $deduplicator Dedupe gate.
     * @param RecipientResolver             $recipients   Recipient resolver.
     * @param NotificationTemplateRenderer  $renderer     Template renderer.
     * @param OpenconnectorAdapterInterface $adapter      Channel adapter port.
     * @param NotificationAuditWriter       $auditWriter  Audit record builder.
     */
    public function __construct(
        private readonly NotificationOptOutPolicy $optOutPolicy,
        private readonly NotificationRateLimiter $rateLimiter,
        private readonly NotificationDeduplicator $deduplicator,
        private readonly RecipientResolver $recipients,
        private readonly NotificationTemplateRenderer $renderer,
        private readonly OpenconnectorAdapterInterface $adapter,
        private readonly NotificationAuditWriter $auditWriter
    ) {

    }//end __construct()

    /**
     * Filter an active trigger list to those that match a fired event.
     *
     * @param string                           $event    Event type (booking.created / changed / cancelled / reminder).
     * @param array<string, mixed>             $booking  Booking payload (slug, id).
     * @param array<int, array<string, mixed>> $triggers Available triggers (typically the active set).
     *
     * @return array<int, array<string, mixed>> Triggers that fire for this event + booking.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
     */
    public function evaluateEventTrigger(string $event, array $booking, array $triggers): array
    {
        $bookingSlug = (string) ($booking['slug'] ?? ($booking['id'] ?? ''));
        $matched     = [];
        foreach ($triggers as $trigger) {
            if (is_array($trigger) === false) {
                continue;
            }

            if (((string) ($trigger['status'] ?? '')) !== 'enabled') {
                continue;
            }

            if (((string) ($trigger['triggerType'] ?? '')) !== $event) {
                continue;
            }

            if (isset($trigger['appliesToBookingSlug']) === true) {
                $scope = (string) $trigger['appliesToBookingSlug'];
            } else {
                $scope = '';
            }

            if ($scope !== '' && $scope !== $bookingSlug) {
                continue;
            }

            $matched[] = $trigger;
        }//end foreach

        return $matched;
    }//end evaluateEventTrigger()

    /**
     * Dispatch one trigger for one booking against its recipient list.
     *
     * Walks each resolved recipient, gates on opt-out / dedupe / rate-limit,
     * renders the selected template, and tries the channel priority list
     * until one succeeds. Every attempt produces a NotificationDelivery
     * record.
     *
     * @param array<string, mixed>                  $trigger            Trigger config.
     * @param array<string, mixed>                  $booking            Booking payload (incl. recipient maps).
     * @param array<string, string>                 $template           Selected template body (subject, body[, language]).
     * @param array<int, array<string, mixed>>|null $resolvedRecipients Optional pre-resolved recipients
     *                                                                  (typically provided by the engine; defaults to RecipientResolver::resolve).
     * @param DateTimeImmutable|null                $at                 Logical "now" (test injection).
     *
     * @return array<int, array<string, mixed>> Audit records (one per attempt).
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-2
     */
    public function dispatchNotification(
        array $trigger,
        array $booking,
        array $template,
        ?array $resolvedRecipients=null,
        ?DateTimeImmutable $at=null
    ): array {
        $now           = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $records       = [];
        $bookingId     = (string) ($booking['id'] ?? ($booking['slug'] ?? ''));
        $organizer     = (string) ($booking['organizerEmail'] ?? ($booking['organizer'] ?? ''));
        $recipients    = ($resolvedRecipients ?? $this->recipients->resolve(trigger: $trigger, booking: $booking));
        $dispatchGroup = $this->auditWriter->newDispatchGroupId();
        $templateName  = (string) ($template['name'] ?? '');

        foreach ($recipients as $recipient) {
            $address = (string) ($recipient['address'] ?? '');
            $skip    = ($recipient['skipReason'] ?? null);
            if ($skip !== null) {
                $records[] = $this->auditWriter->build(
                    trigger: $trigger,
                    bookingId: $bookingId,
                    recipient: $address,
                    channel: ((string) (($recipient['channels'][0] ?? '') ?? '')),
                    templateName: null,
                    result: new NotificationSendResult(
                        status: NotificationSendResult::STATUS_SKIPPED,
                        skipReason: (string) $skip
                    ),
                    dispatchGroupId: $dispatchGroup,
                    at: $now
                );
                continue;
            }

            // Build the variable map for opt-out lookup + rendering.
            $recipientVars = $this->buildRecipientVars(
                role: (string) $recipient['role'],
                address: $address,
                name: $recipient['name'] ?? null,
                booking: $booking
            );

            // Opt-out gate.
            if ($this->optOutPolicy->isOptedOut(trigger: $trigger, recipient: $recipientVars) === true) {
                $records[] = $this->auditWriter->build(
                    trigger: $trigger,
                    bookingId: $bookingId,
                    recipient: $address,
                    channel: ((string) ($recipient['channels'][0] ?? '')),
                    templateName: null,
                    result: new NotificationSendResult(status: NotificationSendResult::STATUS_SKIPPED, skipReason: 'opt-out'),
                    dispatchGroupId: $dispatchGroup,
                    at: $now
                );
                continue;
            }

            // Rate-limit gate.
            $decision = $this->rateLimiter->check(trigger: $trigger, bookingId: $bookingId, organizer: $organizer, at: $now);
            if ($decision !== NotificationRateLimiter::DECISION_ALLOW) {
                $records[] = $this->auditWriter->build(
                    trigger: $trigger,
                    bookingId: $bookingId,
                    recipient: $address,
                    channel: ((string) ($recipient['channels'][0] ?? '')),
                    templateName: null,
                    result: new NotificationSendResult(status: NotificationSendResult::STATUS_QUEUED, skipReason: $decision),
                    dispatchGroupId: $dispatchGroup,
                    at: $now
                );
                continue;
            }

            // Dedupe gate.
            if ($this->deduplicator->isDuplicate(trigger: $trigger, recipient: $address, bookingId: $bookingId, at: $now) === true) {
                $records[] = $this->auditWriter->build(
                    trigger: $trigger,
                    bookingId: $bookingId,
                    recipient: $address,
                    channel: ((string) ($recipient['channels'][0] ?? '')),
                    templateName: null,
                    result: new NotificationSendResult(status: NotificationSendResult::STATUS_SKIPPED, skipReason: 'deduplicated'),
                    dispatchGroupId: $dispatchGroup,
                    at: $now
                );
                continue;
            }

            // Render the template once per recipient.
            $variables = [
                'booking'   => $booking,
                'recipient' => $recipientVars,
                'system'    => ['appName' => 'Bookings'],
            ];

            try {
                $subject = $this->renderer->render(template: (string) ($template['subject'] ?? ''), variables: $variables);
                $body    = $this->renderer->render(template: (string) ($template['body'] ?? ''), variables: $variables);
            } catch (Throwable $e) {
                $records[] = $this->auditWriter->build(
                    trigger: $trigger,
                    bookingId: $bookingId,
                    recipient: $address,
                    channel: ((string) ($recipient['channels'][0] ?? '')),
                    templateName: $templateName,
                    result: new NotificationSendResult(
                        status: NotificationSendResult::STATUS_FAILED,
                        skipReason: 'template-render-error',
                        failureReason: $e->getMessage()
                    ),
                    dispatchGroupId: $dispatchGroup,
                    at: $now
                );
                continue;
            }

            // Channel-fallback loop.
            $channels = (array) ($recipient['channels'] ?? []);
            if (count($channels) === 0) {
                $channels = (array) ($trigger['channels'] ?? []);
            }

            $sent    = false;
            $attempt = 0;
            foreach ($channels as $channel) {
                $channel = (string) $channel;
                if ($this->optOutPolicy->canUseChannel(recipient: $recipientVars, channel: $channel) === false) {
                    $records[] = $this->auditWriter->build(
                        trigger: $trigger,
                        bookingId: $bookingId,
                        recipient: $address,
                        channel: $channel,
                        templateName: $templateName,
                        result: new NotificationSendResult(
                            status: NotificationSendResult::STATUS_SKIPPED,
                            channel: $channel,
                            skipReason: 'opt-out',
                            retryCount: $attempt
                        ),
                        dispatchGroupId: $dispatchGroup,
                        at: $now
                    );
                    $attempt++;
                    continue;
                }

                if ($this->adapter->isChannelAvailable(channel: $channel) === false) {
                    $records[] = $this->auditWriter->build(
                        trigger: $trigger,
                        bookingId: $bookingId,
                        recipient: $address,
                        channel: $channel,
                        templateName: $templateName,
                        result: new NotificationSendResult(
                            status: NotificationSendResult::STATUS_FAILED,
                            channel: $channel,
                            skipReason: 'adapter-unavailable',
                            retryCount: $attempt
                        ),
                        dispatchGroupId: $dispatchGroup,
                        at: $now
                    );
                    $attempt++;
                    continue;
                }

                $result    = $this->adapter->send(
                    channel: $channel,
                    recipient: $address,
                    subject: $subject,
                    body: $body,
                    metadata: [
                        'triggerType' => (string) ($trigger['triggerType'] ?? ''),
                        'bookingId'   => $bookingId,
                        'templateId'  => $templateName,
                    ]
                );
                $result    = new NotificationSendResult(
                    status: $result->status,
                    channel: $channel,
                    skipReason: $result->skipReason,
                    failureReason: $result->failureReason,
                    retryCount: $attempt
                );
                $records[] = $this->auditWriter->build(
                    trigger: $trigger,
                    bookingId: $bookingId,
                    recipient: $address,
                    channel: $channel,
                    templateName: $templateName,
                    result: $result,
                    dispatchGroupId: $dispatchGroup,
                    at: $now
                );

                if ($result->isSent() === true) {
                    $sent = true;
                    $this->rateLimiter->record(bookingId: $bookingId, organizer: $organizer, at: $now);
                    $this->deduplicator->record(trigger: $trigger, recipient: $address, bookingId: $bookingId, at: $now);
                    break;
                }

                $attempt++;
            }//end foreach

            if ($sent === false && count($channels) > 0) {
                // All channels failed — emit terminal failure marker.
                $records[] = $this->auditWriter->build(
                    trigger: $trigger,
                    bookingId: $bookingId,
                    recipient: $address,
                    channel: ((string) end($channels)),
                    templateName: $templateName,
                    result: new NotificationSendResult(
                        status: NotificationSendResult::STATUS_FAILED,
                        skipReason: 'all-channels-failed',
                        retryCount: $attempt
                    ),
                    dispatchGroupId: $dispatchGroup,
                    at: $now
                );
            }
        }//end foreach

        return $records;
    }//end dispatchNotification()

    /**
     * Convenience wrapper for callers that want to record a one-off skip
     * (e.g. a customer fully opted-out, no rule match).
     *
     * @param array<string, mixed>   $trigger Trigger config.
     * @param array<string, mixed>   $booking Booking payload.
     * @param string                 $status  One of NotificationSendResult::STATUS_*.
     * @param string                 $reason  Machine-readable skip / failure reason.
     * @param DateTimeImmutable|null $at      Logical "now".
     *
     * @return array<string, mixed> The audit record body.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-8
     */
    public function recordAuditTrail(array $trigger, array $booking, string $status, string $reason, ?DateTimeImmutable $at=null): array
    {
        $bookingId = (string) ($booking['id'] ?? ($booking['slug'] ?? ''));
        $result    = new NotificationSendResult(status: $status, skipReason: $reason);
        return $this->auditWriter->build(
            trigger: $trigger,
            bookingId: $bookingId,
            recipient: ((string) ($booking['recipient'] ?? '')),
            channel: ((string) (($trigger['channels'][0] ?? '') ?? '')),
            templateName: null,
            result: $result,
            dispatchGroupId: null,
            at: $at
        );
    }//end recordAuditTrail()

    /**
     * Build the recipient variable map for opt-out lookup + rendering.
     *
     * @param string               $role    Recipient role.
     * @param string               $address Resolved address.
     * @param string|null          $name    Optional display name.
     * @param array<string, mixed> $booking Booking payload (for opt-out lookup by role).
     *
     * @return array<string, mixed> Recipient variable map.
     */
    private function buildRecipientVars(string $role, string $address, ?string $name, array $booking): array
    {
        // Map roles onto booking-payload keys for opt-out lookup.
        $optOuts = (array) ($booking['notificationOptOut'][$role] ?? []);
        if (count($optOuts) === 0) {
            $optOuts = (array) ($booking['notificationOptOut'] ?? []);
        }

        return [
            'role'               => $role,
            'address'            => $address,
            'email'              => $address,
            'name'               => $name,
            'languagePreference' => (string) ($booking['recipientLanguage'][$role] ?? ($booking['recipientLanguage'] ?? 'nl')),
            'notificationOptOut' => $optOuts,
        ];
    }//end buildRecipientVars()
}//end class
