<?php

/**
 * Date ranges for the on-screen and generated financial statements.
 *
 * @category Reporting
 * @package  OCA\Shillinq\Reporting
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/reporting-financial-statements
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Reporting;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Validates statement dates and derives the comparison ranges: the previous
 * period of the same length, and the same period one year earlier.
 *
 * @spec openspec/changes/reporting-financial-statements
 */
final class StatementPeriods {

	/**
	 * The comparison kinds a profit and loss request may ask for.
	 */
	public const COMPARISONS = ['previous-period', 'previous-year'];

	/**
	 * The longest range one statement covers, in days.
	 */
	public const MAX_DAYS = 3660;

	/**
	 * Refuse a date that is not a real YYYY-MM-DD date.
	 *
	 * @param string $date  The date.
	 * @param string $field The parameter name, for the message.
	 *
	 * @return DateTimeImmutable The date at midnight UTC.
	 *
	 * @throws InvalidArgumentException When the date is malformed.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	public static function date(string $date, string $field): DateTimeImmutable {
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw new InvalidArgumentException($field . ' must be a date as YYYY-MM-DD.');
		}

		return $parsed;
	}//end date()

	/**
	 * Refuse a range whose dates are malformed, reversed or too far apart.
	 *
	 * @param string $from First date, inclusive.
	 * @param string $to   Last date, inclusive.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the range is not accepted.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	public static function assertRange(string $from, string $to): void {
		$start = self::date(date: $from, field: 'from');
		$end = self::date(date: $to, field: 'to');
		if ($end < $start) {
			throw new InvalidArgumentException('to must not be before from.');
		}

		if ((int)$start->diff($end)->days > self::MAX_DAYS) {
			throw new InvalidArgumentException('The range may span at most ten years.');
		}
	}//end assertRange()

	/**
	 * The comparison kinds named in a comma-separated list, in a fixed order.
	 *
	 * @param string $compare For example `previous-period,previous-year`.
	 *
	 * @return array<int, string> The kinds.
	 *
	 * @throws InvalidArgumentException When a kind is not known.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	public static function comparisons(string $compare): array {
		$asked = array_filter(array_map('trim', explode(',', $compare)), static fn (string $kind): bool => $kind !== '');
		foreach ($asked as $kind) {
			if (in_array($kind, self::COMPARISONS, true) === false) {
				throw new InvalidArgumentException('compare takes ' . implode(' and ', self::COMPARISONS) . '.');
			}
		}

		return array_values(array_intersect(self::COMPARISONS, $asked));
	}//end comparisons()

	/**
	 * The range a comparison column covers.
	 *
	 * The previous period has the same number of days and ends the day before
	 * `from`; the previous year is the same dates one year earlier, with
	 * 29 February read as 28 February.
	 *
	 * @param string $kind previous-period or previous-year.
	 * @param string $from First date of the chosen range.
	 * @param string $to   Last date of the chosen range.
	 *
	 * @return array{from: string, to: string} The comparison range.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	public static function comparisonRange(string $kind, string $from, string $to): array {
		$start = self::date(date: $from, field: 'from');
		$end = self::date(date: $to, field: 'to');

		if ($kind === 'previous-year') {
			return ['from' => self::yearEarlier(date: $start), 'to' => self::yearEarlier(date: $end)];
		}

		$days = (int)$start->diff($end)->days;
		$previousEnd = $start->modify('-1 day');

		return [
			'from' => $previousEnd->modify('-' . $days . ' days')->format('Y-m-d'),
			'to' => $previousEnd->format('Y-m-d'),
		];
	}//end comparisonRange()

	/**
	 * The range a report period label covers: `2026`, `2026-Q3` or `2026-09`.
	 *
	 * @param string $label The label, as the generate dialog sends it.
	 *
	 * @return array{from: string, to: string}|null The range, or null for an unreadable label.
	 *
	 * @spec openspec/changes/reporting-financial-statements
	 */
	public static function rangeOfLabel(string $label): ?array {
		$label = trim($label);
		if (preg_match('/^(\d{4})$/', $label, $match) === 1) {
			return ['from' => $match[1] . '-01-01', 'to' => $match[1] . '-12-31'];
		}

		if (preg_match('/^(\d{4})-Q([1-4])$/i', $label, $match) === 1) {
			$firstMonth = ((((int)$match[2]) - 1) * 3 + 1);
			$start = new DateTimeImmutable(sprintf('%s-%02d-01', $match[1], $firstMonth));
			return ['from' => $start->format('Y-m-d'), 'to' => $start->modify('+2 months')->format('Y-m-t')];
		}

		if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $label, $match) === 1) {
			$start = new DateTimeImmutable($match[1] . '-' . $match[2] . '-01');
			return ['from' => $start->format('Y-m-d'), 'to' => $start->format('Y-m-t')];
		}

		return null;
	}//end rangeOfLabel()

	/**
	 * The same calendar date one year earlier; 29 February becomes 28 February.
	 *
	 * @param DateTimeImmutable $date The date.
	 *
	 * @return string YYYY-MM-DD.
	 */
	private static function yearEarlier(DateTimeImmutable $date): string {
		$year = ((int)$date->format('Y') - 1);
		$month = (int)$date->format('n');
		$day = min((int)$date->format('j'), (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t'));

		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}//end yearEarlier()
}//end class
