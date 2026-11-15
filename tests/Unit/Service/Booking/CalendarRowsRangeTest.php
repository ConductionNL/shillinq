<?php

/**
 * CalendarRows::range refuses a start or end that is not a date as a bad
 * range, the same way it refuses an inverted one.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Booking
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

namespace OCA\Shillinq\Tests\Unit\Service\Booking;

use InvalidArgumentException;
use OCA\Shillinq\Service\Booking\CalendarRows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Range parsing of the calendar bookings list.
 *
 * @covers \OCA\Shillinq\Service\Booking\CalendarRows
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class CalendarRowsRangeTest extends TestCase {

	/**
	 * Ranges that are not dates.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function malformed(): array {
		return [
			'start is not a date' => ['whenever', '2026-06-02T00:00:00Z'],
			'end is not a date' => ['2026-06-01T00:00:00Z', 'later'],
			'start only, not a date' => ['soon', ''],
		];

	}//end malformed()

	/**
	 * A start or end that is not a date is a bad range.
	 *
	 * @param string $start The requested start.
	 * @param string $end The requested end.
	 *
	 * @return void
	 */
	#[DataProvider('malformed')]
	public function testMalformedDateIsABadRange(string $start, string $end): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('start and end must be ISO 8601 timestamps');

		(new CalendarRows())->range(start: $start, end: $end);

	}//end testMalformedDateIsABadRange()

	/**
	 * An inverted range keeps its own message.
	 *
	 * @return void
	 */
	public function testInvertedRangeKeepsItsMessage(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('end must be after start');

		(new CalendarRows())->range(start: '2026-06-02T00:00:00Z', end: '2026-06-01T00:00:00Z');

	}//end testInvertedRangeKeepsItsMessage()

	/**
	 * A valid range is returned in UTC.
	 *
	 * @return void
	 */
	public function testValidRangeIsReturnedInUtc(): void {
		[$start, $end] = (new CalendarRows())->range(start: '2026-06-01T02:00:00+02:00', end: '2026-06-02T00:00:00Z');

		self::assertSame(['2026-06-01T00:00:00+00:00', '2026-06-02T00:00:00+00:00'], [$start->format('c'), $end->format('c')]);

	}//end testValidRangeIsReturnedInUtc()

	/**
	 * Without an end the range runs 30 days; a date without a zone is read in UTC.
	 *
	 * @return void
	 */
	public function testOpenEndRunsThirtyDaysAndBareDatesAreUtc(): void {
		$timezone = date_default_timezone_get();
		date_default_timezone_set('Europe/Amsterdam');
		try {
			[$start, $end] = (new CalendarRows())->range(start: '2026-06-01 00:00', end: '');
		} finally {
			date_default_timezone_set($timezone);
		}

		self::assertSame(['2026-06-01T00:00:00+00:00', '2026-07-01T00:00:00+00:00'], [$start->format('c'), $end->format('c')]);

	}//end testOpenEndRunsThirtyDaysAndBareDatesAreUtc()

	/**
	 * Without a start the range starts today in UTC.
	 *
	 * @return void
	 */
	public function testOpenStartIsToday(): void {
		[$start] = (new CalendarRows())->range(start: '', end: '2999-01-01T00:00:00Z');

		self::assertSame(gmdate('Y-m-d') . 'T00:00:00+00:00', $start->format('c'));

	}//end testOpenStartIsToday()
}//end class
