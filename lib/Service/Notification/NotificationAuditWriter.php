<?php

/**
 * Builder for NotificationDelivery audit records.
 *
 * REQ-BNT-005 / ADR-022 require every notification attempt (sent, failed,
 * skipped, queued) to land in OR's audit-trail-immutable
 * NotificationDelivery schema. The writer is split into a pure builder
 * (this class — produces the record body) and the OR-side persistence
 * (delegated to the engine via the declarative x-openregister-audit
 * block on the trigger). Keeping the builder pure means the unit tests
 * don't need an OR runtime.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Notification;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Builds a NotificationDelivery audit record body from a dispatch attempt.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-8
 */
final class NotificationAuditWriter
{

    /**
     * Build the audit record body for one dispatch attempt.
     *
     * The returned shape matches the NotificationDelivery schema declared
     * in lib/Settings/register.d/bookings-notification-triggers.json
     * (REQ-BNT-005). The caller (OR engine in prod, the orchestrator
     * service in tests) persists it.
     *
     * @param array<string, mixed>   $trigger         Trigger config (name, triggerType).
     * @param string                 $bookingId       Booking identifier.
     * @param string                 $recipient       Recipient address (raw — masked by the schema calculation).
     * @param string                 $channel         Channel attempted.
     * @param string|null            $templateName    Template used (null when status=queued/skipped pre-template).
     * @param NotificationSendResult $result          Dispatch outcome.
     * @param string|null            $dispatchGroupId UUID linking fallback attempts (auto-generated when null).
     * @param DateTimeImmutable|null $at              Logical "now".
     *
     * @return array<string, mixed> Audit record body.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-8
     */
    public function build(
        array $trigger,
        string $bookingId,
        string $recipient,
        string $channel,
        ?string $templateName,
        NotificationSendResult $result,
        ?string $dispatchGroupId=null,
        ?DateTimeImmutable $at=null
    ): array {
        $now    = ($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $record = [
            'triggerName'     => (string) ($trigger['name'] ?? ''),
            'triggerType'     => (string) ($trigger['triggerType'] ?? ''),
            'bookingId'       => $bookingId,
            'recipient'       => $recipient,
            'channel'         => $channel,
            'templateName'    => $templateName,
            'status'          => $result->status,
            'skipReason'      => $result->skipReason,
            'failureReason'   => $result->failureReason,
            'retryCount'      => $result->retryCount,
            'dispatchGroupId' => ($dispatchGroupId ?? $this->newDispatchGroupId()),
            'sentAt'          => $now->format(DateTimeImmutable::ATOM),
        ];

        return $record;
    }//end build()


    /**
     * Generate a fresh dispatch group id (UUID v4-ish).
     *
     * @return string Hex group id.
     */
    public function newDispatchGroupId(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (\Throwable $e) {
            $bytes = pack('N4', mt_rand(), mt_rand(), mt_rand(), mt_rand());
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }//end newDispatchGroupId()


}//end class
