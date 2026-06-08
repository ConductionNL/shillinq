<?php

/**
 * Recipient opt-out gate (REQ-BNT-009).
 *
 * GDPR / AVG mandates honouring an explicit opt-out before dispatch. The
 * gate inspects the recipient's `notificationOptOut` map; an entry
 * matching the firing trigger type returns true and the engine skips the
 * dispatch (audited as `skipped (opt-out)`). When `respectOptOut` is
 * false on the trigger (strictly transactional channels), the gate
 * always allows the dispatch.
 *
 * The `notificationOptOut` map can be:
 *   - `["booking.reminder" => true]`     — opted out of one trigger type.
 *   - `["all" => true]`                   — global opt-out.
 *   - `["channels" => ["sms"]]`           — channel opt-out (skips SMS but
 *     allows email/chat) — checked separately in canUseChannel().
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-13
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Notification;

/**
 * Opt-out gate (per trigger type + per channel).
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-13
 */
final class NotificationOptOutPolicy
{
    /**
     * True when the recipient is opted out of this trigger type entirely.
     *
     * @param array<string, mixed> $trigger   Trigger config (respectOptOut, triggerType).
     * @param array<string, mixed> $recipient Recipient payload (notificationOptOut map).
     *
     * @return bool True = skip dispatch, false = allow.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-13
     */
    public function isOptedOut(array $trigger, array $recipient): bool
    {
        $respect = (bool) ($trigger['respectOptOut'] ?? true);
        if ($respect === false) {
            return false;
        }

        $map = (array) ($recipient['notificationOptOut'] ?? []);
        if ((bool) ($map['all'] ?? false) === true) {
            return true;
        }

        $type = (string) ($trigger['triggerType'] ?? '');
        if ($type === '') {
            return false;
        }

        return ((bool) ($map[$type] ?? false));
    }//end isOptedOut()

    /**
     * True when the recipient has not opted out of the named channel.
     *
     * Used during channel fallback — `canUseChannel(recipient, 'sms')`
     * returns false for an SMS-opted-out recipient so the fallback skips
     * SMS and proceeds to the next channel.
     *
     * @param array<string, mixed> $recipient Recipient payload.
     * @param string               $channel   Channel name.
     *
     * @return bool True = allowed, false = blocked.
     *
     * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-13
     */
    public function canUseChannel(array $recipient, string $channel): bool
    {
        $map      = (array) ($recipient['notificationOptOut'] ?? []);
        $channels = (array) ($map['channels'] ?? []);
        if (in_array($channel, $channels, true) === true) {
            return false;
        }

        return true;
    }//end canUseChannel()
}//end class
