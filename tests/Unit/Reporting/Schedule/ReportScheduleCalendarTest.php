<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * reporting-data-delivery REQ-RDD-001 and REQ-RDD-002: when a schedule runs
 * next and which period a run reports on. The same cases run in
 * tests/vitest/reportingDataDelivery.spec.js against the browser twin.
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Reporting\Schedule;

use DateTimeImmutable;
use OCA\Shillinq\Reporting\Schedule\ReportScheduleCalendar;
use PHPUnit\Framework\TestCase;

class ReportScheduleCalendarTest extends TestCase {

	/**
	 * A monthly schedule made on 29 September for day 5 runs first on 5 October.
	 *
	 * @return void
	 */
	public function testTheMonthlyScheduleRunsOnTheFifthOfNextMonth(): void {
		$calendar = new ReportScheduleCalendar();
		$next     = $calendar->nextRunAfter('monthly', 5, new DateTimeImmutable('2026-09-29T14:00:00Z'));

		self::assertSame('2026-10-05T00:00:00+00:00', $next->format(DATE_ATOM));

	}//end testTheMonthlyScheduleRunsOnTheFifthOfNextMonth()

	/**
	 * The run of 5 October moves the next run to 5 November and reports on September.
	 *
	 * @return void
	 */
	public function testTheRunOfTheFifthOfOctoberReportsOnSeptember(): void {
		$calendar = new ReportScheduleCalendar();
		$runAt    = new DateTimeImmutable('2026-10-05T00:00:00Z');

		self::assertSame('2026-11-05', $calendar->nextRunAfter('monthly', 5, $runAt->modify('+1 hour'))->format('Y-m-d'));
		self::assertSame('2026-09', $calendar->periodFor('monthly', 'previous-period', $runAt));
		self::assertSame('2026', $calendar->periodFor('monthly', 'year-to-date', $runAt));

	}//end testTheRunOfTheFifthOfOctoberReportsOnSeptember()

	/**
	 * A quarterly schedule runs in the first month of a quarter and reports on the quarter before.
	 *
	 * @return void
	 */
	public function testAQuarterlyScheduleReportsOnThePreviousQuarter(): void {
		$calendar = new ReportScheduleCalendar();

		self::assertSame('2026-10-10', $calendar->nextRunAfter('quarterly', 10, new DateTimeImmutable('2026-08-15T00:00:00Z'))->format('Y-m-d'));
		self::assertSame('2027-01-10', $calendar->nextRunAfter('quarterly', 10, new DateTimeImmutable('2026-10-10T01:00:00Z'))->format('Y-m-d'));
		self::assertSame('2026-Q3', $calendar->periodFor('quarterly', 'previous-period', new DateTimeImmutable('2026-10-10T00:00:00Z')));
		self::assertSame('2025-Q4', $calendar->periodFor('quarterly', 'previous-period', new DateTimeImmutable('2026-01-10T00:00:00Z')));

	}//end testAQuarterlyScheduleReportsOnThePreviousQuarter()

	/**
	 * A weekly schedule runs on its weekday.
	 *
	 * @return void
	 */
	public function testAWeeklyScheduleRunsOnItsWeekday(): void {
		$calendar = new ReportScheduleCalendar();

		// 2026-09-29 is a Tuesday; Monday (1) is next on 5 October.
		self::assertSame('2026-10-05', $calendar->nextRunAfter('weekly', 1, new DateTimeImmutable('2026-09-29T09:00:00Z'))->format('Y-m-d'));
		self::assertSame('2026-10-01', $calendar->nextRunAfter('weekly', 4, new DateTimeImmutable('2026-09-29T09:00:00Z'))->format('Y-m-d'));
		self::assertSame('2026-09', $calendar->periodFor('weekly', 'previous-period', new DateTimeImmutable('2026-10-01T00:00:00Z')));

	}//end testAWeeklyScheduleRunsOnItsWeekday()
}//end class
