<?php

/**
 * Booking SMS reminder dispatcher.
 *
 * Orchestrates one booking reminder send: it gates on channel status and
 * recipient opt-out (fail-closed), resolves and validates the recipient phone
 * number (E.164/NL), renders the template, and hands the result to a
 * SmsProviderAdapterInterface. The pure-logic pieces it composes (opt-out
 * policy, phone normalizer, template renderer) are individually unit tested;
 * this class wires them in the order the notification engine would at runtime,
 * keeping the scheduling/templating/opt-out behaviour real without requiring a
 * live gateway. No message body or unmasked number is ever logged (ADR-005).
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
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-31
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sms;

/**
 * Composes the opt-out gate, phone normalizer, template renderer and provider
 * adapter into a single reminder dispatch.
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-31
 */
final class SmsReminderDispatcher {
	/**
	 * Constructor.
	 *
	 * @param SmsOptOutPolicy $optOutPolicy Eligibility gate.
	 * @param SmsPhoneNumberNormalizer $normalizer Phone validation/normalization.
	 * @param SmsTemplateRenderer $renderer Template rendering.
	 * @param SmsProviderAdapterInterface $adapter Provider send adapter.
	 */
	public function __construct(
		private readonly SmsOptOutPolicy $optOutPolicy,
		private readonly SmsPhoneNumberNormalizer $normalizer,
		private readonly SmsTemplateRenderer $renderer,
		private readonly SmsProviderAdapterInterface $adapter,
	) {

	}//end __construct()

	/**
	 * Dispatch a booking reminder for one channel + recipient.
	 *
	 * Order of operations (each step fails closed with a skipped/failed result
	 * and never proceeds to the next):
	 *   1. opt-out / channel-status gate;
	 *   2. resolve recipient phone (recipient number, else channel fallback);
	 *   3. validate/normalize to E.164;
	 *   4. render the template;
	 *   5. send via the provider adapter.
	 *
	 * @param array<string, mixed> $channel The SMS channel object.
	 * @param array<string, mixed> $recipient The recipient contact (phone, smsOptOut).
	 * @param array<string, scalar> $variables Booking variables for the template.
	 *
	 * @return SmsSendResult The dispatch outcome (PII-free).
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-31
	 */
	public function dispatch(array $channel, array $recipient, array $variables): SmsSendResult {
		// 1. Eligibility gate.
		if ($this->optOutPolicy->isSendAllowed($channel, $recipient) === false) {
			return new SmsSendResult(
				SmsSendResult::STATUS_SKIPPED,
				null,
				$this->optOutPolicy->skipReason($channel, $recipient)
			);
		}

		// 2. Resolve the recipient number (recipient first, then channel fallback).
		$rawNumber = $this->resolveRawNumber(channel: $channel, recipient: $recipient);
		if ($rawNumber === '') {
			return new SmsSendResult(
				SmsSendResult::STATUS_SKIPPED,
				null,
				'no recipient phone number and no channel fallback'
			);
		}

		// 3. Validate / normalize to E.164 (NL-focused per the channel format).
		$e164 = $this->normalizer->toE164($rawNumber, 'NL');
		if ($e164 === null) {
			return new SmsSendResult(
				SmsSendResult::STATUS_FAILED,
				null,
				'recipient phone number failed E.164/NL validation'
			);
		}

		// 4. Render the template.
		$body = $this->renderer->render((string)($channel['messageTemplate'] ?? ''), $variables);
		if ($body === '') {
			return new SmsSendResult(
				SmsSendResult::STATUS_FAILED,
				null,
				'rendered message body is empty'
			);
		}

		// 5. Send via the provider adapter.
		$connectorId = (string)($channel['providerConfig']['connectorId'] ?? '');
		if ($connectorId === '') {
			return new SmsSendResult(
				SmsSendResult::STATUS_FAILED,
				null,
				'channel has no provider connector configured'
			);
		}

		return $this->adapter->send($connectorId, $e164, $body, $this->resolveSenderId(channel: $channel));
	}//end dispatch()

	/**
	 * Resolve the raw recipient number: the recipient's own number if present,
	 * otherwise the channel fallback, otherwise an empty string.
	 *
	 * @param array<string, mixed> $channel The SMS channel object.
	 * @param array<string, mixed> $recipient The recipient contact.
	 *
	 * @return string The raw (un-normalized) number, or '' when none is available.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-4
	 */
	private function resolveRawNumber(array $channel, array $recipient): string {
		if (isset($recipient['phone']) === true && (string)$recipient['phone'] !== '') {
			return (string)$recipient['phone'];
		}

		if (isset($channel['fallbackPhoneNumber']) === true) {
			return (string)$channel['fallbackPhoneNumber'];
		}

		return '';
	}//end resolveRawNumber()

	/**
	 * Resolve the optional sender id for a channel.
	 *
	 * @param array<string, mixed> $channel The SMS channel object.
	 *
	 * @return string|null The sender id, or null when not set.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-15
	 */
	private function resolveSenderId(array $channel): ?string {
		if (isset($channel['senderId']) === true && (string)$channel['senderId'] !== '') {
			return (string)$channel['senderId'];
		}

		return null;
	}//end resolveSenderId()
}//end class
