<?php

/**
 * Notification Rate Limit
 *
 * The rate limits one booking notification is checked against
 * (REQ-BNT-006): sends per booking per hour and per organizer per day, and
 * the NotificationDelivery queries that count them.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A booking and organizer with their send limits.
 *
 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
 */
final class NotificationRateLimit {
	/**
	 * Hold the limits.
	 *
	 * @param string $bookingId UUID of the booking ('' to skip the booking limit).
	 * @param string $organizerId Organizer user ID ('' to skip the organizer limit).
	 * @param int $perBooking Max sends per booking per hour.
	 * @param int $perOrganizer Max sends per organizer per day.
	 */
	public function __construct(
		public readonly string $bookingId,
		public readonly string $organizerId,
		public readonly int $perBooking,
		public readonly int $perOrganizer,
	) {
	}//end __construct()

	/**
	 * The query for the booking's sends since a moment, one past the limit.
	 *
	 * @param string $since ISO timestamp the window starts at.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
	 */
	public function bookingQuery(string $since): array {
		return [
			'filters' => [
				'bookingId' => $this->bookingId,
				'sentAt[gte]' => $since,
			],
			'limit' => ($this->perBooking + 1),
		];
	}//end bookingQuery()

	/**
	 * The query for the organizer's sends since a moment, one past the limit.
	 *
	 * @param string $since ISO timestamp the window starts at.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/bookings-notification-triggers/tasks.md#task-9
	 */
	public function organizerQuery(string $since): array {
		return [
			'filters' => [
				'organizerId' => $this->organizerId,
				'sentAt[gte]' => $since,
			],
			'limit' => ($this->perOrganizer + 1),
		];
	}//end organizerQuery()
}//end class
