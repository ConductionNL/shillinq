<?php

/**
 * Report Period
 *
 * The first and last day of a report period written as `2026`, `2026-Q3` or
 * `2026-09`.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting\CostSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://shillinq.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting\CostSource;

/**
 * Parses a report period into an inclusive date range.
 *
 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
 */
final class ReportPeriod {

	/**
	 * The first and last day of a period, as `Y-m-d`, or null when unreadable.
	 *
	 * @param string $period A year (`2026`), quarter (`2026-Q3`) or month (`2026-09`).
	 *
	 * @return array{0: string, 1: string}|null The inclusive range.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
	 */
	public static function range(string $period): ?array {
		$period = strtoupper(trim($period));
		if (preg_match('/^(\d{4})$/', $period, $match) === 1) {
			return [$match[1] . '-01-01', $match[1] . '-12-31'];
		}

		if (preg_match('/^(\d{4})-Q([1-4])$/', $period, $match) === 1) {
			$firstMonth = ((((int)$match[2]) - 1) * 3 + 1);
			return [
				sprintf('%s-%02d-01', $match[1], $firstMonth),
				self::lastDay(year: (int)$match[1], month: ($firstMonth + 2)),
			];
		}

		if (preg_match('/^(\d{4})-(\d{2})$/', $period, $match) === 1 && (int)$match[2] >= 1 && (int)$match[2] <= 12) {
			return [sprintf('%s-%s-01', $match[1], $match[2]), self::lastDay(year: (int)$match[1], month: (int)$match[2])];
		}

		return null;
	}//end range()

	/**
	 * Whether a date falls in an inclusive range.
	 *
	 * @param mixed $date The date, `Y-m-d` or a longer ISO 8601 string.
	 * @param array{0: string, 1: string} $range The inclusive range.
	 *
	 * @return bool True when inside.
	 *
	 * @spec openspec/changes/iv3-report-takes-case-costs-by-case-type/specs/bookkeeping-iv3-reporting/spec.md#requirement-the-same-case-cost-source-is-grouped-per-case-type-per-period-req-iv3-012
	 */
	public static function contains(mixed $date, array $range): bool {
		if (is_string($date) === false || preg_match('/^\d{4}-\d{2}-\d{2}/', trim($date)) !== 1) {
			return false;
		}

		$day = substr(trim($date), 0, 10);

		return ($day >= $range[0] && $day <= $range[1]);
	}//end contains()

	/**
	 * The last day of a month, as `Y-m-d`.
	 *
	 * @param int $year The year.
	 * @param int $month The month.
	 *
	 * @return string The day.
	 */
	private static function lastDay(int $year, int $month): string {
		return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('Y-m-t');
	}//end lastDay()
}//end class
