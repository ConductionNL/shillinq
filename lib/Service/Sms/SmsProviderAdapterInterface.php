<?php

/**
 * SMS provider adapter interface.
 *
 * Abstracts the act of handing a rendered SMS message to an SMS provider
 * (MessageBird, Twilio, or a custom openconnector connector). The booking
 * SMS reminder pipeline (SmsReminderDispatcher) talks only to this interface,
 * so the scheduling/templating/opt-out logic is provider-agnostic and unit
 * testable. Live gateway integration lives in OpenRegister's notification
 * engine + openconnector (ADR-022); within this app the LogSmsProviderAdapter
 * is the wired implementation and simply records the (masked) send.
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
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-6
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sms;

/**
 * Contract for sending one SMS message through a configured provider.
 *
 * Implementations MUST NOT log message bodies or unmasked phone numbers
 * (personal data, ADR-005). Credentials are owned by the referenced
 * openconnector connector, never passed here in cleartext.
 */
interface SmsProviderAdapterInterface {
	/**
	 * Dispatch one rendered SMS message.
	 *
	 * @param string $connectorId Slug/id of the openconnector connector holding provider credentials.
	 * @param string $e164Recipient Recipient phone number in E.164 form (already validated/normalized).
	 * @param string $body Fully rendered message body (≤ provider limit).
	 * @param string|null $senderId Optional alphanumeric sender id (≤11 chars).
	 *
	 * @return SmsSendResult Outcome of the send attempt (status + provider reference, no PII).
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-6
	 */
	public function send(
		string $connectorId,
		string $e164Recipient,
		string $body,
		?string $senderId = null,
	): SmsSendResult;
}//end interface
