<?php

/**
 * Notification Dedup Key
 *
 * What makes a booking notification a duplicate (REQ-BNT-006): the same
 * booking, recipient and trigger type within a window, and the
 * NotificationDelivery query that finds one.
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
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A booking, recipient and trigger type within a deduplication window.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
 */
final class NotificationDedupKey {
	/**
	 * Hold the key.
	 *
	 * @param string $bookingId UUID of the booking.
	 * @param string $recipient Recipient address.
	 * @param string $triggerType Trigger event type.
	 * @param int $windowMinutes Deduplication window in minutes.
	 */
	public function __construct(
		public readonly string $bookingId,
		public readonly string $recipient,
		public readonly string $triggerType,
		public readonly int $windowMinutes,
	) {
	}//end __construct()

	/**
	 * The query for one matching send since a moment.
	 *
	 * @param string $since ISO timestamp the window starts at.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
	 */
	public function query(string $since): array {
		return [
			'filters' => [
				'bookingId' => $this->bookingId,
				'recipient' => $this->recipient,
				'triggerType' => $this->triggerType,
				'sentAt[gte]' => $since,
			],
			'limit' => 1,
		];
	}//end query()
}//end class
