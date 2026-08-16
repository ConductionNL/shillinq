<?php

/**
 * Logging SMS provider adapter.
 *
 * The wired SmsProviderAdapterInterface implementation inside Shillinq. It does
 * not contact a live gateway — live dispatch is owned by OpenRegister's
 * notification engine + openconnector (ADR-022) — but it exercises the full
 * provider-adapter contract so the scheduling/templating/opt-out pipeline is
 * real and testable end to end. It records a PII-free audit line (masked phone,
 * never the body) and returns a pending result with a synthetic provider
 * reference.
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
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-22
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Sms;

use Psr\Log\LoggerInterface;

/**
 * Provider adapter that records masked sends to the application log.
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-22
 */
final class LogSmsProviderAdapter implements SmsProviderAdapterInterface {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Application logger.
	 * @param SmsPhoneNumberNormalizer $normalizer Phone masking helper.
	 * @param SmsTemplateRenderer $renderer Segment-count helper.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly SmsPhoneNumberNormalizer $normalizer,
		private readonly SmsTemplateRenderer $renderer,
	) {

	}//end __construct()

	/**
	 * Record one SMS "send" as a masked, body-free log line.
	 *
	 * @param string $connectorId openconnector connector id holding the credentials.
	 * @param string $e164Recipient Validated E.164 recipient number.
	 * @param string $body Rendered message body (length only is logged, never the text).
	 * @param string|null $senderId Optional sender id.
	 *
	 * @return SmsSendResult Pending result with a synthetic reference and segment count.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-22
	 */
	public function send(
		string $connectorId,
		string $e164Recipient,
		string $body,
		?string $senderId = null,
	): SmsSendResult {
		$segments = $this->renderer->segmentCount($body);

		$this->logger->info(
			'SMS reminder queued via log adapter',
			[
				'app' => 'shillinq',
				'connector' => $connectorId,
				'recipient' => $this->normalizer->mask($e164Recipient),
				'sender' => $senderId,
				'length' => mb_strlen($body),
				'segments' => $segments,
			]
		);

		$reference = 'log-' . bin2hex(random_bytes(8));

		return new SmsSendResult(
			SmsSendResult::STATUS_PENDING,
			$reference,
			'queued via log adapter (no live gateway wired)',
			$segments
		);

	}//end send()
}//end class
