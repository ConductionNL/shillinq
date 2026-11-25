<?php

/**
 * Dates and periods of the rows a compliance deadline is read from.
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
 * @spec openspec/changes/archive/2026-11-25-integration-leaves-consume/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use Throwable;

/**
 * Reads the period of a return row (periodType month, quarter or year, with
 * periodYear and periodMonth or periodQuarter, or a periodEnd date): its end
 * date and its label ("2026-Q2", "2026-03", "2026").
 *
 * @spec openspec/changes/archive/2026-11-25-integration-leaves-consume/tasks.md
 */
final class FilingPeriodRow {

	/**
	 * The period end: the row's periodEnd, else the last day of its month,
	 * quarter or year.
	 *
	 * @param array<string,mixed> $row The return row.
	 *
	 * @return string|null The period end (Y-m-d), or null when neither is usable.
	 *
	 * @spec openspec/changes/archive/2026-11-25-integration-leaves-consume/tasks.md
	 */
	public function periodEnd(array $row): ?string {
		$end = $this->date(value: (string)($row['periodEnd'] ?? ''));
		if ($end !== null) {
			return $end;
		}

		$year  = (int)($row['periodYear'] ?? 0);
		$month = $this->endMonth(row: $row);
		if ($year < 1 || $month < 1 || $month > 12) {
			return null;
		}

		return $this->lastDayOf(year: $year, month: $month);
	}//end periodEnd()

	/**
	 * The period label. Year, quarter and month come from the row, else
	 * from its periodEnd.
	 *
	 * @param array<string,mixed> $row The return row.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/archive/2026-11-25-integration-leaves-consume/tasks.md
	 */
	public function label(array $row): string {
		$type  = (string)($row['periodType'] ?? 'quarter');
		$end   = (string)$this->date(value: (string)($row['periodEnd'] ?? ''));
		$month = (int)substr($end, 5, 2);
		$year  = (string)($row['periodYear'] ?? substr($end, 0, 4));

		if ($type === 'month') {
			return $year . '-' . str_pad((string)($row['periodMonth'] ?? $month), 2, '0', STR_PAD_LEFT);
		}

		if ($type === 'year') {
			return $year;
		}

		return $year . '-Q' . (string)($row['periodQuarter'] ?? (int)ceil($month / 3));
	}//end label()

	/**
	 * The last month of the period (12 for a year).
	 *
	 * @param array<string,mixed> $row The return row.
	 *
	 * @return int The month, or 0 when the period number is missing.
	 */
	private function endMonth(array $row): int {
		$type = (string)($row['periodType'] ?? 'quarter');
		if ($type === 'month') {
			return (int)($row['periodMonth'] ?? 0);
		}

		if ($type === 'year') {
			return 12;
		}

		return ((int)($row['periodQuarter'] ?? 0) * 3);
	}//end endMonth()

	/**
	 * The filing due date of a period: the last day of the month after the
	 * period end (BTW and ICP rule, REQ-CDC-002).
	 *
	 * @param string $periodEnd The period end (Y-m-d-ish).
	 *
	 * @return string|null The due date (Y-m-d), or null when unparseable.
	 *
	 * @spec openspec/specs/compliance-deadline-calendar/spec.md
	 */
	public function dueDateAfter(string $periodEnd): ?string {
		$end = $this->date(value: $periodEnd);
		if ($end === null) {
			return null;
		}

		return (new DateTimeImmutable($end))
			->modify('first day of next month')
			->modify('last day of this month')
			->format('Y-m-d');
	}//end dueDateAfter()

	/**
	 * The last day of a year and month as Y-m-d.
	 *
	 * @param int $year  The year.
	 * @param int $month The month (1-12).
	 *
	 * @return string|null The date, or null on failure.
	 *
	 * @spec openspec/changes/archive/2026-11-25-integration-leaves-consume/tasks.md
	 */
	public function lastDayOf(int $year, int $month): ?string {
		try {
			return (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
				->modify('last day of this month')
				->format('Y-m-d');
		} catch (Throwable $e) {
			return null;
		}
	}//end lastDayOf()

	/**
	 * Today, at midnight.
	 *
	 * @return DateTimeImmutable Today.
	 *
	 * @spec openspec/changes/archive/2026-11-25-integration-leaves-consume/tasks.md
	 */
	public function today(): DateTimeImmutable {
		return new DateTimeImmutable('today');
	}//end today()

	/**
	 * A date-ish string as Y-m-d, or null when empty or unparseable.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string|null Y-m-d, or null.
	 *
	 * @spec openspec/changes/archive/2026-11-25-integration-leaves-consume/tasks.md
	 */
	public function date(string $value): ?string {
		if ($value === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->format('Y-m-d');
		} catch (Throwable $e) {
			return null;
		}
	}//end date()
}//end class
