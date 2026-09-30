<?php

/**
 * Report Schedule Calendar
 *
 * The two date rules of a report schedule (reporting-data-delivery
 * REQ-RDD-001, REQ-RDD-002): when it runs next, and which period a run
 * reports on. The same rules run in the browser (src/utils/reportSchedule.js)
 * so a new schedule shows its first run before the job has seen it.
 *
 * Runs fall on the run day at 00:00 UTC: a day of the month (1 to 28) for a
 * monthly schedule, the same day in the first month of each quarter for a
 * quarterly one, and an ISO weekday (1 Monday to 7 Sunday) for a weekly one.
 * A run reports on the period before its own date, or on the year to date.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\Schedule
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\Schedule;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Next run and reported period of a report schedule.
 */
class ReportScheduleCalendar {

	public const FREQUENCIES = ['weekly', 'monthly', 'quarterly'];

	public const PERIOD_RULES = ['previous-period', 'year-to-date'];

	/**
	 * The first run strictly after a moment.
	 *
	 * @param string            $frequency weekly, monthly or quarterly.
	 * @param int               $runDay    Day of the month (1 to 28) or ISO weekday (1 to 7).
	 * @param DateTimeImmutable $after     The moment the next run must follow.
	 *
	 * @return DateTimeImmutable The next run, at 00:00 UTC.
	 *
	 * @throws InvalidArgumentException For an unknown frequency.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function nextRunAfter(string $frequency, int $runDay, DateTimeImmutable $after): DateTimeImmutable {
		$after = $after->setTimezone(new DateTimeZone('UTC'));
		if ($frequency === 'weekly') {
			$weekday   = max(1, min(7, $runDay));
			$candidate = $after->setISODate((int)$after->format('o'), (int)$after->format('W'), $weekday)->setTime(0, 0);
			while ($candidate <= $after) {
				$candidate = $candidate->add(new DateInterval('P7D'));
			}

			return $candidate;
		}

		if (in_array($frequency, ['monthly', 'quarterly'], true) === false) {
			throw new InvalidArgumentException('Unknown report schedule frequency: ' . $frequency);
		}

		$day   = max(1, min(28, $runDay));
		$step  = 1;
		$month = (int)$after->format('n');
		if ($frequency === 'quarterly') {
			$step  = 3;
			$month = ((intdiv($month - 1, 3)) * 3) + 1;
		}

		$candidate = $after->setDate((int)$after->format('Y'), $month, $day)->setTime(0, 0);
		while ($candidate <= $after) {
			$candidate = $candidate->add(new DateInterval('P' . $step . 'M'));
		}

		return $candidate;

	}//end nextRunAfter()

	/**
	 * The period a run reports on, in the notation of ReportGenerationService.
	 *
	 * @param string            $frequency  weekly, monthly or quarterly.
	 * @param string            $periodRule previous-period or year-to-date.
	 * @param DateTimeImmutable $runAt      The run's scheduled moment.
	 *
	 * @return string 2026-09 for a month, 2026-Q3 for a quarter, 2026 for a year.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function periodFor(string $frequency, string $periodRule, DateTimeImmutable $runAt): string {
		$day = $runAt->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0)->modify('-1 day');
		if ($periodRule === 'year-to-date') {
			return $day->format('Y');
		}

		if ($frequency === 'quarterly') {
			$previous = $runAt->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month')->modify('-3 months');
			return $previous->format('Y') . '-Q' . (intdiv((int)$previous->format('n') - 1, 3) + 1);
		}

		if ($frequency === 'monthly') {
			return $runAt->setTimezone(new DateTimeZone('UTC'))->modify('first day of this month')->modify('-1 month')->format('Y-m');
		}

		// A weekly run reports on the month its previous week ended in; the
		// report catalogue knows months, quarters and years, not weeks.
		return $day->format('Y-m');

	}//end periodFor()
}//end class
