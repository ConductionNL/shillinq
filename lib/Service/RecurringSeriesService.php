<?php

/**
 * Recurring Appointment Series Service
 *
 * Closes the bookings-depth "recurring-appointment-series" gap: bookings/spec.md
 * self-declared recurring bookings DEFERRED to Tier-2. This service realises them.
 * It expands an RRULE-style recurrence string (RFC 5545 subset) into individual
 * appointment occurrences and, for each occurrence, decides whether it may be
 * booked by REUSING the existing availability/conflict engine — SlotService's
 * slot enumeration (opening/closing hours + overlap with existing appointments)
 * — rather than forking a second conflict implementation. Occurrences whose slot
 * is unavailable (out of hours or overlapping an existing/earlier-generated
 * booking) are skipped; the remainder become individual Appointment payloads,
 * each tagged with the seriesId and its zero-based recurrenceIndex.
 *
 * Pure and OR-agnostic: it operates on plain arrays and returns the generated +
 * skipped occurrences so any caller (admin "create series" action, import) can
 * persist the generated appointments through OpenRegister's ObjectService
 * (ADR-022).
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
 * @spec openspec/specs/bookings-recurring-series/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Expands an RRULE-style recurrence into individual appointments, reusing
 * SlotService for the availability/conflict decision (no fork).
 *
 * @spec openspec/specs/bookings-recurring-series/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class RecurringSeriesService {

	/**
	 * Hard safety cap on generated occurrences when the rule is open-ended
	 * (no COUNT and no UNTIL). Prevents runaway expansion.
	 *
	 * @var int
	 */
	public const MAX_OCCURRENCES = 366;

	/**
	 * RRULE BYDAY token → ISO-8601 weekday number (Mon=1 .. Sun=7).
	 *
	 * @var array<string, int>
	 */
	private const BYDAY_MAP = [
		'MO' => 1,
		'TU' => 2,
		'WE' => 3,
		'TH' => 4,
		'FR' => 5,
		'SA' => 6,
		'SU' => 7,
	];

	/**
	 * Construct the service.
	 *
	 * @param SlotService $slotService The existing availability/conflict
	 *                                 engine — reused for the per-occurrence
	 *                                 slot-availability decision (no fork).
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SlotService $slotService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Expand an RRULE string into the list of occurrence start instants (UTC).
	 *
	 * Supported RFC 5545 subset: `FREQ=DAILY|WEEKLY|MONTHLY`, `INTERVAL=n`
	 * (default 1), `COUNT=n`, `UNTIL=YYYYMMDD[THHMMSS[Z]]` or `YYYY-MM-DD`, and
	 * `BYDAY=MO,TU,...` (WEEKLY only). The first occurrence is always the series
	 * start. Expansion stops at COUNT, past UNTIL, or {@see MAX_OCCURRENCES}.
	 *
	 * @param string $rrule RRULE string.
	 * @param DateTimeImmutable $seriesStart First occurrence start (UTC).
	 *
	 * @return array<int, DateTimeImmutable> Ordered occurrence start instants.
	 *
	 * @throws InvalidArgumentException When FREQ is missing or unsupported.
	 *
	 * @spec openspec/specs/bookings-recurring-series/spec.md
	 */
	public function expandRule(string $rrule, DateTimeImmutable $seriesStart): array {
		$parts = $this->parseRule(rrule: $rrule);
		$freq = ($parts['FREQ'] ?? '');

		$interval = max(1, (int)($parts['INTERVAL'] ?? 1));
		$count = (int)($parts['COUNT'] ?? 0);
		$until = $this->parseUntil(value: (string)($parts['UNTIL'] ?? ''));
		$byDay = $this->parseByDay(value: (string)($parts['BYDAY'] ?? ''));
		$hardCap = self::MAX_OCCURRENCES;
		if ($count > 0) {
			$hardCap = min($count, self::MAX_OCCURRENCES);
		}

		$utc = new DateTimeZone('UTC');
		$seriesStart = $seriesStart->setTimezone($utc);

		switch ($freq) {
			case 'DAILY':
				return $this->expandDailyWeekly(
					seriesStart: $seriesStart,
					interval: $interval,
					weekly: false,
					byDay: [],
					until: $until,
					hardCap: $hardCap,
				);
			case 'WEEKLY':
				return $this->expandDailyWeekly(
					seriesStart: $seriesStart,
					interval: $interval,
					weekly: true,
					byDay: $byDay,
					until: $until,
					hardCap: $hardCap,
				);
			case 'MONTHLY':
				return $this->expandMonthly(
					seriesStart: $seriesStart,
					interval: $interval,
					until: $until,
					hardCap: $hardCap,
				);
			default:
				throw new InvalidArgumentException('Unsupported or missing RRULE FREQ: "' . $freq . '"');
		}//end switch

	}//end expandRule()

	/**
	 * Plan a recurring series: expand the rule and, for each occurrence, reuse
	 * SlotService to decide whether the occurrence's slot is available.
	 *
	 * Returns `{generated: [...appointment payloads...], skipped: [...]}`.
	 * Available occurrences become individual Appointment payloads tagged with
	 * `seriesId` + `recurrenceIndex`; earlier-generated occurrences are folded
	 * back into the existing-appointment set so a later occurrence cannot double
	 * -book the same slot. Unavailable occurrences (out of hours or overlapping)
	 * are skipped with a reason.
	 *
	 * @param array<string, mixed> $seriesDef Series definition — `seriesId`,
	 *                                        `serviceId`, `resourceId`,
	 *                                        `administrationId`, `customerId`
	 *                                        (optional), `startTime` (ISO UTC),
	 *                                        `durationMinutes`, `recurrenceRule`,
	 *                                        `openingTime` (HH:MM),
	 *                                        `closingTime` (HH:MM),
	 *                                        `existingAppointments`
	 *                                        (list of {startTime,endTime}),
	 *                                        `allowOverlap` (bool).
	 *
	 * @return array{generated: array<int, array<string, mixed>>, skipped: array<int, array<string, string>>}
	 *
	 * @throws InvalidArgumentException When the series definition is incomplete or the rule is invalid.
	 *
	 * @spec openspec/specs/bookings-recurring-series/spec.md
	 */
	public function planSeries(array $seriesDef): array {
		$startRaw = (string)($seriesDef['startTime'] ?? '');
		$duration = (int)($seriesDef['durationMinutes'] ?? 0);
		$rrule = (string)($seriesDef['recurrenceRule'] ?? '');
		if ($startRaw === '' || $duration <= 0 || $rrule === '') {
			throw new InvalidArgumentException('planSeries requires startTime, durationMinutes and recurrenceRule');
		}

		$utc = new DateTimeZone('UTC');
		$seriesStart = (new DateTimeImmutable($startRaw))->setTimezone($utc);
		$occurrences = $this->expandRule(rrule: $rrule, seriesStart: $seriesStart);

		$openingTime = (string)($seriesDef['openingTime'] ?? '00:00');
		$closingTime = (string)($seriesDef['closingTime'] ?? '23:59');
		$allowOverlap = (bool)($seriesDef['allowOverlap'] ?? false);
		$existing = $this->normaliseExisting(value: ($seriesDef['existingAppointments'] ?? []));

		$generated = [];
		$skipped = [];
		$index = 0;

		foreach ($occurrences as $occStart) {
			$date = $occStart->format('Y-m-d');
			$wantedIso = $occStart->format('Y-m-d\TH:i:s\Z');
			$slotEnd = $occStart->modify('+' . $duration . ' minutes');
			$endIso = $slotEnd->format('Y-m-d\TH:i:s\Z');

			$existingForDate = $this->filterExistingForDate(existing: $existing, date: $date);

			// Reuse SlotService's slot enumeration (opening/closing hours +
			// overlap with existing appointments) — the single source of truth
			// for availability. The occurrence is bookable iff its exact start
			// is one of the enumerated available slots.
			$slots = $this->slotService->enumerateSlotsPublic(
				date: $date,
				openingTime: $openingTime,
				closingTime: $closingTime,
				durationMinutes: $duration,
				existingAppointments: $existingForDate,
				allowOverlap: $allowOverlap,
			);
			$available = $this->slotIsAvailable(slots: $slots, wantedStart: $wantedIso);

			if ($available === false) {
				$skipped[] = [
					'startTime' => $wantedIso,
					'reason' => 'unavailable',
				];
				$index++;
				continue;
			}

			$generated[] = [
				'administrationId' => (string)($seriesDef['administrationId'] ?? ''),
				'serviceId' => (string)($seriesDef['serviceId'] ?? ''),
				'resourceId' => (string)($seriesDef['resourceId'] ?? ''),
				'customerId' => (string)($seriesDef['customerId'] ?? ''),
				'startTime' => $wantedIso,
				'endTime' => $endIso,
				'status' => 'confirmed',
				'seriesId' => (string)($seriesDef['seriesId'] ?? ''),
				'recurrenceIndex' => $index,
			];

			// Fold the just-generated occurrence into the existing set so a
			// later occurrence cannot double-book the same window.
			$existing[] = ['startTime' => $wantedIso, 'endTime' => $endIso];
			$index++;
		}//end foreach

		$this->logger->info(
			'Shillinq: recurring series planned',
			[
				'seriesId' => (string)($seriesDef['seriesId'] ?? ''),
				'generated' => count($generated),
				'skipped' => count($skipped),
			]
		);

		return [
			'generated' => $generated,
			'skipped' => $skipped,
		];

	}//end planSeries()

	/**
	 * Expand a DAILY or WEEKLY rule by walking day-by-day from the series start.
	 *
	 * DAILY: a day qualifies when its whole-day offset from the start is a
	 * multiple of INTERVAL. WEEKLY without BYDAY: same weekday, every INTERVAL
	 * weeks. WEEKLY with BYDAY: any listed weekday in an active week (week offset
	 * from the start week is a multiple of INTERVAL).
	 *
	 * @param DateTimeImmutable $seriesStart First occurrence (UTC).
	 * @param int $interval Recurrence interval.
	 * @param bool $weekly TRUE for WEEKLY, FALSE for DAILY.
	 * @param array<int, int> $byDay ISO weekday numbers (WEEKLY+BYDAY).
	 * @param DateTimeImmutable|null $until Inclusive upper bound, or null.
	 * @param int $hardCap Max occurrences.
	 *
	 * @return array<int, DateTimeImmutable>
	 *
	 * @SuppressWarnings(PHPMD.CountInLoopExpression) $occurrences grows
	 *     conditionally inside the loop body — the loop bound is genuinely
	 *     the running count, it cannot be hoisted.
	 */
	private function expandDailyWeekly(
		DateTimeImmutable $seriesStart,
		int $interval,
		bool $weekly,
		array $byDay,
		?DateTimeImmutable $until,
		int $hardCap,
	): array {
		$occurrences = [];
		$startMidnight = $seriesStart->setTime(0, 0, 0);
		$weekAnchor = $this->weekAnchor(day: $startMidnight);

		$cursor = $seriesStart;
		$safety = 0;
		$maxDays = (self::MAX_OCCURRENCES * 7);

		while (count($occurrences) < $hardCap && $safety < $maxDays) {
			if ($until !== null && $cursor > $until) {
				break;
			}

			$qualifies = false;
			if ($weekly === false) {
				// DAILY.
				$dayOffset = (int)floor(($cursor->setTime(0, 0, 0)->getTimestamp() - $startMidnight->getTimestamp()) / 86400);
				$qualifies = ($dayOffset % $interval === 0);
			} elseif ($byDay === []) {
				// WEEKLY, same weekday.
				$weekOffset = $this->weekOffset(anchor: $weekAnchor, day: $cursor);
				$qualifies = (($weekOffset % $interval) === 0 && ((int)$cursor->format('N') === (int)$seriesStart->format('N')));
			} else {
				// WEEKLY with BYDAY.
				$weekOffset = $this->weekOffset(anchor: $weekAnchor, day: $cursor);
				$qualifies = (($weekOffset % $interval) === 0 && in_array((int)$cursor->format('N'), $byDay, true));
			}

			if ($qualifies === true && $cursor >= $seriesStart) {
				$occurrences[] = $cursor;
			}

			$cursor = $cursor->modify('+1 day');
			$safety++;
		}//end while

		return $occurrences;
	}//end expandDailyWeekly()

	/**
	 * Expand a MONTHLY rule by stepping INTERVAL months from the series start,
	 * preserving the day-of-month and time-of-day. Months without the target
	 * day (e.g. day 31 in a 30-day month) are skipped.
	 *
	 * @param DateTimeImmutable $seriesStart First occurrence (UTC).
	 * @param int $interval Month interval.
	 * @param DateTimeImmutable|null $until Inclusive upper bound, or null.
	 * @param int $hardCap Max occurrences.
	 *
	 * @return array<int, DateTimeImmutable>
	 *
	 * @SuppressWarnings(PHPMD.CountInLoopExpression) $occurrences grows
	 *     conditionally inside the loop body — the loop bound is genuinely
	 *     the running count, it cannot be hoisted.
	 */
	private function expandMonthly(
		DateTimeImmutable $seriesStart,
		int $interval,
		?DateTimeImmutable $until,
		int $hardCap,
	): array {
		$occurrences = [];
		$targetDay = (int)$seriesStart->format('j');
		$hour = (int)$seriesStart->format('H');
		$minute = (int)$seriesStart->format('i');
		$second = (int)$seriesStart->format('s');
		$firstOfMonth = $seriesStart->setDate((int)$seriesStart->format('Y'), (int)$seriesStart->format('n'), 1)->setTime(0, 0, 0);

		$step = 0;
		while (count($occurrences) < $hardCap && $step < self::MAX_OCCURRENCES) {
			$monthBase = $firstOfMonth->modify('+' . ($step * $interval) . ' months');
			$year = (int)$monthBase->format('Y');
			$month = (int)$monthBase->format('n');
			$daysInMonth = (int)$monthBase->format('t');
			$step++;

			if ($targetDay > $daysInMonth) {
				// This month has no such day (e.g. day 31 in April) — skip.
				if ($until !== null && $monthBase > $until) {
					break;
				}

				continue;
			}

			$occ = $monthBase->setDate($year, $month, $targetDay)->setTime($hour, $minute, $second);
			if ($until !== null && $occ > $until) {
				break;
			}

			if ($occ >= $seriesStart) {
				$occurrences[] = $occ;
			}
		}//end while

		return $occurrences;
	}//end expandMonthly()

	/**
	 * Parse an RRULE string into an upper-cased key => value map.
	 *
	 * @param string $rrule RRULE string (optionally prefixed with "RRULE:").
	 *
	 * @return array<string, string>
	 */
	private function parseRule(string $rrule): array {
		$rrule = trim($rrule);
		if (stripos($rrule, 'RRULE:') === 0) {
			$rrule = substr($rrule, 6);
		}

		$out = [];
		foreach (explode(';', $rrule) as $segment) {
			$segment = trim($segment);
			if ($segment === '' || strpos($segment, '=') === false) {
				continue;
			}

			[$key, $value] = explode('=', $segment, 2);
			$out[strtoupper(trim($key))] = strtoupper(trim($value));
		}

		return $out;
	}//end parseRule()

	/**
	 * Parse a BYDAY value into a list of ISO-8601 weekday numbers.
	 *
	 * @param string $value Comma-separated BYDAY tokens (e.g. "MO,WE,FR").
	 *
	 * @return array<int, int>
	 */
	private function parseByDay(string $value): array {
		if ($value === '') {
			return [];
		}

		$out = [];
		foreach (explode(',', $value) as $token) {
			$token = strtoupper(trim($token));
			if (isset(self::BYDAY_MAP[$token]) === true) {
				$out[] = self::BYDAY_MAP[$token];
			}
		}

		return $out;
	}//end parseByDay()

	/**
	 * Parse an RRULE UNTIL value (YYYYMMDD[THHMMSS[Z]] or YYYY-MM-DD) into UTC.
	 *
	 * @param string $value UNTIL value, or empty.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parseUntil(string $value): ?DateTimeImmutable {
		if ($value === '') {
			return null;
		}

		$utc = new DateTimeZone('UTC');
		try {
			if (preg_match('/^\d{8}(T\d{6}Z?)?$/', $value) === 1) {
				$fmt = 'Ymd';
				if (strlen($value) > 8) {
					$fmt = 'Ymd\THis';
				}

				$dt = DateTimeImmutable::createFromFormat('!' . $fmt, rtrim($value, 'Z'), $utc);
				if ($dt === false) {
					return null;
				}

				// Date-only UNTIL is inclusive of the whole day.
				if (strlen($value) === 8) {
					return $dt->setTime(23, 59, 59);
				}

				return $dt;
			}

			return (new DateTimeImmutable($value))->setTimezone($utc);
		} catch (\Exception $e) {
			$this->logger->warning('Shillinq: unparseable RRULE UNTIL: ' . $value);
			return null;
		}//end try

	}//end parseUntil()

	/**
	 * Monday-midnight anchor of the week containing a given day (UTC).
	 *
	 * @param DateTimeImmutable $day The day.
	 *
	 * @return DateTimeImmutable
	 */
	private function weekAnchor(DateTimeImmutable $day): DateTimeImmutable {
		$isoDow = (int)$day->format('N');
		$anchor = $day->setTime(0, 0, 0)->modify('-' . ($isoDow - 1) . ' days');
		if ($anchor === false) {
			// Unreachable: `-0..-6 days` is always a valid relative modifier;
			// guard only to satisfy the DateTimeImmutable|false return contract.
			throw new RuntimeException('weekAnchor: unexpected DateTime modify failure');
		}

		return $anchor;
	}//end weekAnchor()

	/**
	 * Whole-week offset of a day from a Monday anchor.
	 *
	 * @param DateTimeImmutable $anchor Monday-midnight anchor of the start week.
	 * @param DateTimeImmutable $day The day under test.
	 *
	 * @return int
	 */
	private function weekOffset(DateTimeImmutable $anchor, DateTimeImmutable $day): int {
		$seconds = ($day->setTime(0, 0, 0)->getTimestamp() - $anchor->getTimestamp());
		return (int)floor($seconds / (7 * 86400));
	}//end weekOffset()

	/**
	 * Whether the wanted start instant matches an enumerated available slot.
	 *
	 * @param array<int, array<string, string>> $slots Enumerated slots.
	 * @param string $wantedStart Occurrence start ISO-8601 UTC.
	 *
	 * @return bool
	 */
	private function slotIsAvailable(array $slots, string $wantedStart): bool {
		foreach ($slots as $slot) {
			if ((string)($slot['startTime'] ?? '') === $wantedStart) {
				return true;
			}
		}

		return false;
	}//end slotIsAvailable()

	/**
	 * Normalise the existing-appointment input to a list of {startTime,endTime}.
	 *
	 * @param mixed $value Raw existing-appointment input.
	 *
	 * @return array<int, array{startTime: string, endTime: string}>
	 */
	private function normaliseExisting(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $row) {
			if (is_array($row) === false) {
				continue;
			}

			$start = (string)($row['startTime'] ?? '');
			$end = (string)($row['endTime'] ?? '');
			if ($start === '' || $end === '') {
				continue;
			}

			$out[] = ['startTime' => $start, 'endTime' => $end];
		}

		return $out;
	}//end normaliseExisting()

	/**
	 * Filter existing appointments to those overlapping a given calendar date.
	 *
	 * @param array<int, array{startTime: string, endTime: string}> $existing Existing windows.
	 * @param string $date Calendar date YYYY-MM-DD UTC.
	 *
	 * @return array<int, array{startTime: string, endTime: string}>
	 */
	private function filterExistingForDate(array $existing, string $date): array {
		$dayStart = ($date . 'T00:00:00Z');
		$dayEnd = ($date . 'T23:59:59Z');
		$out = [];
		foreach ($existing as $row) {
			if ($row['endTime'] < $dayStart || $row['startTime'] > $dayEnd) {
				continue;
			}

			$out[] = $row;
		}

		return $out;
	}//end filterExistingForDate()
}//end class
