<?php

/**
 * Immutable result of an SMS send attempt.
 *
 * A small value object describing the outcome of one dispatch: a status
 * (sent / pending / failed / skipped), an optional provider reference, and a
 * human-readable, PII-free reason. The booking SMS reminder pipeline returns
 * one of these per recipient so callers (and OR's notification history) can
 * record success/failure without ever touching the message body or an
 * unmasked phone number.
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

/**
 * PII-free outcome value object for one SMS dispatch attempt.
 *
 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-22
 */
final class SmsSendResult {

	/**
	 * Message was accepted by the provider.
	 */
	public const STATUS_SENT = 'sent';

	/**
	 * Message is queued/in-flight at the provider.
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Send attempt failed (transient or permanent).
	 */
	public const STATUS_FAILED = 'failed';

	/**
	 * Send was intentionally not attempted (opt-out, inactive channel, no number).
	 */
	public const STATUS_SKIPPED = 'skipped';

	/**
	 * Constructor.
	 *
	 * @param string $status One of the STATUS_* constants.
	 * @param string|null $providerReference Provider message id, when available (no PII).
	 * @param string|null $reason PII-free explanation (e.g. "recipient opted out").
	 * @param int|null $segments Number of SMS segments the body required.
	 */
	public function __construct(
		public readonly string $status,
		public readonly ?string $providerReference = null,
		public readonly ?string $reason = null,
		public readonly ?int $segments = null,
	) {

	}//end __construct()

	/**
	 * Whether the attempt resulted in an accepted/queued message.
	 *
	 * @return bool True for sent or pending.
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-22
	 */
	public function isDelivered(): bool {
		return in_array($this->status, [self::STATUS_SENT, self::STATUS_PENDING], true);
	}//end isDelivered()

	/**
	 * Serialise to a plain array for the notification history / cost log.
	 *
	 * @return array{status: string, providerReference: ?string, reason: ?string, segments: ?int}
	 *
	 * @spec openspec/changes/bookings-sms-reminder-channel/tasks.md#task-34
	 */
	public function toArray(): array {
		return [
			'status' => $this->status,
			'providerReference' => $this->providerReference,
			'reason' => $this->reason,
			'segments' => $this->segments,
		];

	}//end toArray()
}//end class
