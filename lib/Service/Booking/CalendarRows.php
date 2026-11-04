<?php

/**
 * Calendar rows.
 *
 * Pure helpers over stored Calendar and Booking rows for the calendar
 * endpoints: the requested date range, the bookings that intersect it, the
 * public shape of a calendar and of a booking, and the pending Booking object
 * a create request stores.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Booking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Booking;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Range selection, formatting and payload building for Calendar and Booking rows.
 *
 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
 */
final class CalendarRows {

	/**
	 * Days a range runs when the caller gives no end.
	 */
	private const DEFAULT_RANGE_DAYS = 30;

	/**
	 * The formatted bookings whose window intersects the half-open range,
	 * sorted by startTime. Rows without a parseable start and end are skipped.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored bookings.
	 * @param array{0: DateTimeImmutable, 1: DateTimeImmutable} $range The range.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
	 */
	public function inRange(array $rows, array $range): array {
		$bookings = [];
		foreach ($rows as $row) {
			$window = $this->window(row: $row);
			if ($window !== null && $window[0] < $range[1] && $window[1] > $range[0]) {
				$bookings[] = $this->format(row: $row);
			}
		}

		usort(
			$bookings,
			static fn (array $a, array $b): int => strcmp((string)$a['startTime'], (string)$b['startTime'])
		);

		return $bookings;
	}//end inRange()

	/**
	 * Shape a calendar OR record into the API response payload.
	 *
	 * @param array<string,mixed> $row The stored calendar.
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
	 */
	public function calendar(array $row): array {
		return [
			'id' => (string)($row['calendarId'] ?? ''),
			'calendarId' => (string)($row['calendarId'] ?? ''),
			'resource' => (string)($row['resource'] ?? ''),
			'administrationId' => (string)($row['administrationId'] ?? ''),
			'timeZone' => (string)($row['timeZone'] ?? 'Europe/Amsterdam'),
			'workingHours' => ($row['workingHours'] ?? null),
			'status' => (string)($row['status'] ?? 'active'),
			'createdAt' => (string)($row['createdAt'] ?? ''),
			'updatedAt' => (string)($row['updatedAt'] ?? ''),
		];

	}//end calendar()

	/**
	 * The public shape of a booking.
	 *
	 * @param array<string, mixed> $row The stored booking.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
	 */
	public function format(array $row): array {
		return [
			'id' => (string)($row['bookingId'] ?? ''),
			'bookingId' => (string)($row['bookingId'] ?? ''),
			'calendar' => (string)($row['calendar'] ?? ''),
			'resource' => (string)($row['resource'] ?? ''),
			'administrationId' => (string)($row['administrationId'] ?? ''),
			'title' => (string)($row['title'] ?? ''),
			'startTime' => (string)($row['startTime'] ?? ''),
			'endTime' => (string)($row['endTime'] ?? ''),
			'attendee' => (string)($row['attendee'] ?? ''),
			'status' => (string)($row['status'] ?? 'pending'),
			'externalId' => ($row['externalId'] ?? null),
			'createdAt' => (string)($row['createdAt'] ?? ''),
			'updatedAt' => (string)($row['updatedAt'] ?? ''),
		];

	}//end format()

	/**
	 * The pending Booking object for a create request; a posted status is ignored.
	 *
	 * @param array<string, mixed> $booking The parsed request plus its calendar scope.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
	 */
	public function payload(array $booking): array {
		return [
			'administrationId' => $booking['administrationId'],
			'bookingId' => $this->newBookingId(),
			'calendar' => $booking['calendar'],
			'resource' => $booking['resource'],
			'title' => $booking['title'],
			'startTime' => $booking['start']->format('Y-m-d\\TH:i:s\\Z'),
			'endTime' => $booking['end']->format('Y-m-d\\TH:i:s\\Z'),
			'attendee' => $booking['attendee'],
			'status' => 'pending',
		];
	}//end payload()

	/**
	 * Default a missing range to today + 30 days and normalise to UTC.
	 *
	 * @param string $start Caller-supplied start (may be empty).
	 * @param string $end Caller-supplied end (may be empty).
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable} Inclusive lower / exclusive upper bounds.
	 *
	 * @throws InvalidArgumentException When a date is malformed or the range inverted.
	 *
	 * @spec openspec/changes/bookings-resource-calendar/tasks.md#task-3
	 */
	public function range(string $start, string $end): array {
		$tz = new DateTimeZone('UTC');

		$startDt = $this->parseDate(date: $start, fallback: new DateTimeImmutable('today', $tz));
		$endDt   = $this->parseDate(date: $end, fallback: $startDt->modify('+' . self::DEFAULT_RANGE_DAYS . ' days'));

		if ($endDt <= $startDt) {
			throw new InvalidArgumentException('end must be after start');
		}

		return [$startDt->setTimezone($tz), $endDt->setTimezone($tz)];
	}//end range()

	/**
	 * A requested date in UTC, or the fallback when none is given.
	 *
	 * @param string $date The requested date, '' when absent.
	 * @param DateTimeImmutable $fallback The date to use when absent.
	 *
	 * @return DateTimeImmutable
	 *
	 * @throws InvalidArgumentException When the date cannot be parsed.
	 */
	private function parseDate(string $date, DateTimeImmutable $fallback): DateTimeImmutable {
		if ($date === '') {
			return $fallback;
		}

		try {
			return new DateTimeImmutable($date, new DateTimeZone('UTC'));
		} catch (\DateMalformedStringException $e) {
			throw new InvalidArgumentException('start and end must be ISO 8601 timestamps', 0, $e);
		}
	}//end parseDate()

	/**
	 * Generate a stable, sortable booking id for a new booking.
	 *
	 * @return string Booking id (bk-YYYYMMDDHHMMSS-rrrr).
	 */
	private function newBookingId(): string {
		return 'bk-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(2));
	}//end newBookingId()

	/**
	 * A stored booking's start and end in UTC, null when either is missing or malformed.
	 *
	 * @param array<string, mixed> $row The stored booking.
	 *
	 * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}|null
	 */
	private function window(array $row): ?array {
		$startRaw = (string)($row['startTime'] ?? '');
		$endRaw   = (string)($row['endTime'] ?? '');
		if ($startRaw === '' || $endRaw === '') {
			return null;
		}

		try {
			$utc = new DateTimeZone('UTC');
			return [(new DateTimeImmutable($startRaw))->setTimezone($utc), (new DateTimeImmutable($endRaw))->setTimezone($utc)];
		} catch (\Throwable) {
			return null;
		}
	}//end window()
}//end class
