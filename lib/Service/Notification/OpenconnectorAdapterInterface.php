<?php

/**
 * openconnector channel-adapter port for booking notifications.
 *
 * REQ-BNT-004 routes every dispatch through openconnector's adapter API
 * (POST /openconnector/api/notifications/send). The adapter is wrapped
 * behind a narrow port so the dispatch helper stays unit-testable and
 * the production binding (LogOpenconnectorAdapter) can be swapped for an
 * HTTP-backed implementation without touching the orchestration code.
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

/**
 * Channel-adapter port. One method = one dispatch attempt.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-5
 */
interface OpenconnectorAdapterInterface
{
    /**
     * Send one notification through the named channel adapter.
     *
     * @param string               $channel   Channel id (email/sms/chat/teams/slack).
     * @param string               $recipient Recipient address (email / E.164 phone / chat id / group reference).
     * @param string               $subject   Rendered subject (may be empty for SMS).
     * @param string               $body      Rendered body.
     * @param array<string, mixed> $metadata  Optional metadata (templateId, triggerType, bookingId).
     *
     * @return NotificationSendResult The attempt outcome.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-5
     */
    public function send(string $channel, string $recipient, string $subject, string $body, array $metadata=[]): NotificationSendResult;

    /**
     * Quick reachability check used by the dispatcher to short-circuit
     * obviously-unavailable channels before composing the payload.
     *
     * @param string $channel Channel id.
     *
     * @return bool True when the adapter is configured + reachable.
     */
    public function isChannelAvailable(string $channel): bool;
}//end interface
