<?php

/**
 * Availability windows: where a resource's availability rules, breaks and
 * booking constraints allow a slot.
 *
 * Pure: it reads the rows it is given and the moment it is told, and decides.
 * SlotService loads the rows and enumerates the slots.
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
 * @spec openspec/changes/bookings-slots-follow-availability/tasks.md#task-1.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Booking;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Decides the open windows of a date and whether a slot inside them may be offered.
 *
 * - A rule applies on a date when it is active, the date lies inside its
 *   effective window and its `daysOfWeek` names the weekday (or `daily`).
 * - When several rules apply, the most specific wins: the rules naming the
 *   fewest weekdays. A Wednesday-only rule therefore replaces a Monday to
 *   Friday one on Wednesdays; rules equally specific add their windows up.
 * - A slot overlapping an active break of the day is not offered.
 * - The strictest active constraint bounds the slots: no slot starts inside
 *   the minimum notice (hours, and days counted in calendar days), and no
 *   slot lies beyond the maximum days ahead.
 *
 * @spec openspec/changes/bookings-slots-follow-availability/specs/bookings-availability-rules/spec.md
 */
final class AvailabilityWindows {

	/**
	 * The value of `daysOfWeek` / `dayOfWeek` meaning every day.
	 *
	 * @var string
	 */
	public const DAILY = 'daily';

	/**
	 * The time-of-day shape, HH:MM on a 24-hour clock.
	 *
	 * @var string
	 */
	private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

	/**
	 * Whether any of the rows is an active rule; a resource without one keeps its own hours.
	 *
	 * @param array<int,array<string,mixed>> $rules The resource's AvailabilityRule rows.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookings-slots-follow-availability/tasks.md#task-1.1
	 */
	public function hasActiveRule(array $rules): bool {
		foreach ($rules as $rule) {
			if (($rule['status'] ?? '') === 'active') {
				return true;
			}
		}

		return false;
	}//end hasActiveRule()

	/**
	 * The open windows on a date, as HH:MM pairs, from the most specific applying rules.
	 *
	 * @param array<int,array<string,mixed>> $rules The resource's AvailabilityRule rows.
	 * @param string                         $date  The date, YYYY-MM-DD.
	 *
	 * @return array<int,array{0:string,1:string}> The windows; none when no rule applies.
	 *
	 * @spec openspec/changes/bookings-slots-follow-availability/tasks.md#task-1.1
	 */
	public function windowsOn(array $rules, string $date): array {
		$weekday = self::weekdayOf(date: $date);
		$bySpecificity = [];
		foreach ($rules as $rule) {
			$days = self::days(value: $rule['daysOfWeek'] ?? []);
			$window = self::window(start: $rule['startTime'] ?? null, end: $rule['endTime'] ?? null);
			if ($window === null || self::appliesOn(rule: $rule, date: $date) === false || self::names(days: $days, weekday: $weekday) === false) {
				continue;
			}

			$bySpecificity[self::dayCount(days: $days)][] = $window;
		}

		if ($bySpecificity === []) {
			return [];
		}

		ksort($bySpecificity);
		$windows = reset($bySpecificity);
		sort($windows);

		return $windows;
	}//end windowsOn()

	/**
	 * The breaks on a date, as HH:MM pairs.
	 *
	 * @param array<int,array<string,mixed>> $breaks The ResourceBreak rows of the resource's rules.
	 * @param string                         $date   The date, YYYY-MM-DD.
	 *
	 * @return array<int,array{0:string,1:string}> The breaks.
	 *
	 * @spec openspec/changes/bookings-slots-follow-availability/tasks.md#task-1.1
	 */
	public function breaksOn(array $breaks, string $date): array {
		$weekday = self::weekdayOf(date: $date);
		$windows = [];
		foreach ($breaks as $break) {
			$day = strtolower((string)($break['dayOfWeek'] ?? ''));
			$window = self::window(start: $break['startTime'] ?? null, end: $break['endTime'] ?? null);
			if (($break['status'] ?? 'active') !== 'active' || $window === null || ($day !== $weekday && $day !== self::DAILY)) {
				continue;
			}

			$windows[] = $window;
		}

		return $windows;
	}//end breaksOn()

	/**
	 * The strictest notice limits of the active constraints.
	 *
	 * @param array<int,array<string,mixed>> $constraints The BookingConstraint rows of the resource's rules.
	 *
	 * @return array{minHours:int,minDays:int,maxDays:int|null} The limits; no limit is 0, 0 and null.
	 *
	 * @spec openspec/changes/bookings-slots-follow-availability/tasks.md#task-1.2
	 */
	public function noticeLimits(array $constraints): array {
		$limits = ['minHours' => 0, 'minDays' => 0, 'maxDays' => null];
		foreach ($constraints as $constraint) {
			if (($constraint['status'] ?? 'active') !== 'active') {
				continue;
			}

			$limits['minHours'] = max($limits['minHours'], (int)($constraint['minAdvanceNoticeHours'] ?? 0));
			$limits['minDays'] = max($limits['minDays'], (int)($constraint['minAdvanceNoticeDays'] ?? 0));
			$maxDays = $constraint['maxAdvanceNoticeDays'] ?? null;
			if (is_numeric($maxDays) === true) {
				$limits['maxDays'] = min($limits['maxDays'] ?? PHP_INT_MAX, (int)$maxDays);
			}
		}

		return $limits;
	}//end noticeLimits()

	/**
	 * Whether a slot may be offered: outside every break and inside the notice limits.
	 *
	 * @param DateTimeImmutable                   $start  The slot start.
	 * @param DateTimeImmutable                   $end    The slot end.
	 * @param array<int,array{0:string,1:string}> $breaks The breaks of the slot's date.
	 * @param array{minHours:int,minDays:int,maxDays:int|null} $limits The notice limits.
	 * @param DateTimeImmutable                   $now    The current moment.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookings-slots-follow-availability/tasks.md#task-1.2
	 */
	public function admits(DateTimeImmutable $start, DateTimeImmutable $end, array $breaks, array $limits, DateTimeImmutable $now): bool {
		$date = $start->format('Y-m-d');
		$zone = $start->getTimezone();
		foreach ($breaks as [$from, $until]) {
			$breakStart = self::momentOf(date: $date, time: $from, zone: $zone);
			$breakEnd = self::momentOf(date: $date, time: $until, zone: $zone);
			if ($start < $breakEnd && $end > $breakStart) {
				return false;
			}
		}

		if ($start < $now->modify('+' . $limits['minHours'] . ' hours')) {
			return false;
		}

		$today = $now->setTimezone($start->getTimezone())->setTime(0, 0);
		$day = $start->setTime(0, 0);
		if ($day < $today->modify('+' . $limits['minDays'] . ' days')) {
			return false;
		}

		return $limits['maxDays'] === null || $day <= $today->modify('+' . $limits['maxDays'] . ' days');
	}//end admits()

	/**
	 * Whether a rule is active and its effective window holds the date.
	 *
	 * @param array<string,mixed> $rule The rule.
	 * @param string              $date The date, YYYY-MM-DD.
	 *
	 * @return bool
	 */
	private static function appliesOn(array $rule, string $date): bool {
		if (($rule['status'] ?? '') !== 'active') {
			return false;
		}

		$from = substr((string)($rule['effectiveFrom'] ?? ''), 0, 10);
		$until = substr((string)($rule['effectiveUntil'] ?? ''), 0, 10);

		return ($from === '' || $from <= $date) && ($until === '' || $date <= $until);
	}//end appliesOn()

	/**
	 * The lower-cased weekday names of a `daysOfWeek` value (an array, or one name).
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<int,string> The names.
	 */
	private static function days(mixed $value): array {
		if (is_string($value) === true) {
			$value = [$value];
		}

		if (is_array($value) === false) {
			return [];
		}

		return array_values(array_unique(array_map(static fn ($day): string => strtolower((string)$day), $value)));
	}//end days()

	/**
	 * Whether the names include the weekday, or every day.
	 *
	 * @param array<int,string> $days    The names.
	 * @param string            $weekday The weekday.
	 *
	 * @return bool
	 */
	private static function names(array $days, string $weekday): bool {
		return in_array($weekday, $days, true) === true || in_array(self::DAILY, $days, true) === true;
	}//end names()

	/**
	 * How many weekdays the names cover; `daily` covers seven.
	 *
	 * @param array<int,string> $days The names.
	 *
	 * @return int
	 */
	private static function dayCount(array $days): int {
		if (in_array(self::DAILY, $days, true) === true) {
			return 7;
		}

		return count($days);
	}//end dayCount()

	/**
	 * A window from two HH:MM times, or null when either is malformed or it is empty.
	 *
	 * @param mixed $start The start.
	 * @param mixed $end   The end.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private static function window(mixed $start, mixed $end): ?array {
		$start = (string)$start;
		$end = (string)$end;
		if (preg_match(self::TIME_PATTERN, $start) !== 1 || preg_match(self::TIME_PATTERN, $end) !== 1 || $start >= $end) {
			return null;
		}

		return [$start, $end];
	}//end window()

	/**
	 * The English lower-case weekday of a date; empty when the date does not parse.
	 *
	 * @param string $date The date, YYYY-MM-DD.
	 *
	 * @return string
	 */
	private static function weekdayOf(string $date): string {
		try {
			return strtolower((new DateTimeImmutable($date, new DateTimeZone('UTC')))->format('l'));
		} catch (Throwable $e) {
			return '';
		}
	}//end weekdayOf()

	/**
	 * A moment on a date at an HH:MM time.
	 *
	 * @param string       $date The date.
	 * @param string       $time The time.
	 * @param DateTimeZone $zone The zone.
	 *
	 * @return DateTimeImmutable
	 */
	private static function momentOf(string $date, string $time, DateTimeZone $zone): DateTimeImmutable {
		return new DateTimeImmutable($date . 'T' . $time . ':00', $zone);
	}//end momentOf()
}//end class
