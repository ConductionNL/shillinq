<?php

/**
 * Openconnector Notification
 *
 * One rendered notification for openconnector's channel adapter API
 * (REQ-BNT-004): the channel, recipient, subject, body and template.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
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

namespace OCA\Shillinq\Service;

/**
 * A rendered notification on one channel.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-5
 */
final class OpenconnectorNotification {
	/**
	 * Hold the notification.
	 *
	 * @param string $channel Delivery channel: email|sms|chat.
	 * @param string $recipient Recipient address.
	 * @param string $subject Rendered notification subject.
	 * @param string $body Rendered notification body.
	 * @param string $templateId UUID of the template used.
	 */
	public function __construct(
		public readonly string $channel,
		public readonly string $recipient,
		public readonly string $subject,
		public readonly string $body,
		public readonly string $templateId,
	) {
	}//end __construct()

	/**
	 * The JSON payload openconnector's send endpoint takes.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-5
	 */
	public function payload(): array {
		return [
			'channelAdapter' => $this->channel,
			'recipient' => $this->recipient,
			'subject' => $this->subject,
			'body' => $this->body,
			'templateId' => $this->templateId,
		];
	}//end payload()
}//end class
