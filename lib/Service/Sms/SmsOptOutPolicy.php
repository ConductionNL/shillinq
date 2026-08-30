<?php

/**
 * SMS opt-out / send-eligibility policy.
 *
 * Pure-logic helper deciding whether a reminder may be sent to a recipient on
 * a given channel. Phone numbers are personal data; a recipient who has opted
 * out of SMS reminders MUST be skipped unless the operator has explicitly
 * disabled opt-out respect for a transactional-only channel (REQ-SMS-021,
 * GDPR / ADR-005). This is the fail-closed gate the dispatcher consults before
 * any rendering or provider call.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Sms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-21
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sms;

/**
 * Side-effect-free eligibility gate for SMS reminder dispatch.
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-21
 */
final class SmsOptOutPolicy {
	/**
	 * Whether a reminder is allowed to be sent to a recipient on a channel.
	 *
	 * Returns false (skip) when the channel is not active, or when the
	 * recipient has opted out and the channel respects opt-out. The default
	 * is to respect opt-out, so a missing/unknown respectOptOut flag is
	 * treated as true (fail-closed).
	 *
	 * @param array<string, mixed> $channel The channel object (status, respectOptOut).
	 * @param array<string, mixed> $recipient The recipient contact (smsOptOut).
	 *
	 * @return bool True when dispatch is permitted.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-21
	 */
	public function isSendAllowed(array $channel, array $recipient): bool {
		$status = ($channel['status'] ?? '');
		if ($status !== 'active') {
			return false;
		}

		$respectOptOut = (bool)($channel['respectOptOut'] ?? true);
		$optedOut = (bool)($recipient['smsOptOut'] ?? false);

		if ($respectOptOut === true && $optedOut === true) {
			return false;
		}

		return true;
	}//end isSendAllowed()

	/**
	 * A PII-free reason a recipient was skipped, or null when sending is
	 * allowed. Used for the notification history without revealing the number.
	 *
	 * @param array<string, mixed> $channel The channel object.
	 * @param array<string, mixed> $recipient The recipient contact.
	 *
	 * @return string|null Skip reason, or null when sending is allowed.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-32
	 */
	public function skipReason(array $channel, array $recipient): ?string {
		if (($channel['status'] ?? '') !== 'active') {
			return 'channel not active';
		}

		$respectOptOut = (bool)($channel['respectOptOut'] ?? true);
		if ($respectOptOut === true && (bool)($recipient['smsOptOut'] ?? false) === true) {
			return 'recipient opted out of SMS reminders';
		}

		return null;
	}//end skipReason()
}//end class
