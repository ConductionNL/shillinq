<?php

/**
 * Recurring Appointment Series Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
		$freq  = ($parts['FREQ'] ?? '');
		$rule  = $this->recurrenceFor(parts: $parts, seriesStart: $seriesStart);

		return match ($freq) {
			'DAILY', 'WEEKLY' => $this->walkDays(rule: $rule),
			'MONTHLY' => $this->expandMonthly(rule: $rule),
			default => throw new InvalidArgumentException('Unsupported or missing RRULE FREQ: "' . $freq . '"'),
		};

	}//end expandRule()

	/**
	 * The parsed rule anchored on the series start in UTC: INTERVAL (at
	 * least 1), BYDAY (WEEKLY only), UNTIL, and the cap from COUNT bounded by
	 * {@see MAX_OCCURRENCES}.
	 *
	 * @param array<string, string> $parts       The upper-cased RRULE parts.
	 * @param DateTimeImmutable     $seriesStart First occurrence start.
	 *
	 * @return RecurrenceRule
	 */
	private function recurrenceFor(array $parts, DateTimeImmutable $seriesStart): RecurrenceRule {
		$rule = new RecurrenceRule();
		$rule->interval = max(1, (int)($parts['INTERVAL'] ?? 1));
		$count          = (int)($parts['COUNT'] ?? 0);
		$rule->until    = $this->parseUntil(value: (string)($parts['UNTIL'] ?? ''));
		$rule->weekly   = (($parts['FREQ'] ?? '') === 'WEEKLY');
		$rule->byDay    = $this->parseByDay(value: (string)($parts['BYDAY'] ?? ''));
		$rule->hardCap  = self::MAX_OCCURRENCES;
		if ($count > 0) {
			$rule->hardCap = min($count, self::MAX_OCCURRENCES);
		}

		$rule->seriesStart = $seriesStart->setTimezone(new DateTimeZone('UTC'));
		return $rule;
	}//end recurrenceFor()

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
		$plan = $this->planFor(seriesDef: $seriesDef);
		foreach ($this->expandRule(rrule: (string)$seriesDef['recurrenceRule'], seriesStart: $this->seriesStartOf(plan: $plan)) as $occStart) {
			$this->planOccurrence(plan: $plan, occStart: $occStart);
		}

		$this->logger->info(
			'Shillinq: recurring series planned',
			[
				'seriesId' => (string)($seriesDef['seriesId'] ?? ''),
				'generated' => count($plan->generated),
				'skipped' => count($plan->skipped),
			]
		);

		return [
			'generated' => $plan->generated,
			'skipped' => $plan->skipped,
		];

	}//end planSeries()

	/**
	 * The planning run for a series definition, refusing one without a start,
	 * a duration or a rule.
	 *
	 * @param array<string, mixed> $seriesDef The series definition.
	 *
	 * @return SeriesPlan
	 *
	 * @throws InvalidArgumentException When the series definition is incomplete.
	 */
	private function planFor(array $seriesDef): SeriesPlan {
		$duration = (int)($seriesDef['durationMinutes'] ?? 0);
		if ((string)($seriesDef['startTime'] ?? '') === '' || $duration <= 0 || (string)($seriesDef['recurrenceRule'] ?? '') === '') {
			throw new InvalidArgumentException('planSeries requires startTime, durationMinutes and recurrenceRule');
		}

		$plan = new SeriesPlan();
		$plan->seriesDef       = $seriesDef;
		$plan->durationMinutes = $duration;
		$this->readHours(plan: $plan);
		return $plan;
	}//end planFor()

	/**
	 * The series start in UTC.
	 *
	 * @param SeriesPlan $plan The planning run.
	 *
	 * @return DateTimeImmutable
	 */
	private function seriesStartOf(SeriesPlan $plan): DateTimeImmutable {
		return (new DateTimeImmutable((string)$plan->seriesDef['startTime']))->setTimezone(new DateTimeZone('UTC'));
	}//end seriesStartOf()

	/**
	 * Read the opening hours, the overlap flag and the existing appointments
	 * onto the plan.
	 *
	 * @param SeriesPlan $plan The planning run.
	 *
	 * @return void
	 */
	private function readHours(SeriesPlan $plan): void {
		$seriesDef          = $plan->seriesDef;
		$plan->openingTime  = (string)($seriesDef['openingTime'] ?? '00:00');
		$plan->closingTime  = (string)($seriesDef['closingTime'] ?? '23:59');
		$plan->allowOverlap = (bool)($seriesDef['allowOverlap'] ?? false);
		$plan->existing     = $this->normaliseExisting(value: ($seriesDef['existingAppointments'] ?? []));
	}//end readHours()

	/**
	 * Plan one occurrence: an appointment when its slot is free, else a skip.
	 * A planned occurrence is folded into the existing set so a later
	 * occurrence cannot double-book the same window.
	 *
	 * @param SeriesPlan        $plan     The planning run.
	 * @param DateTimeImmutable $occStart The occurrence start (UTC).
	 *
	 * @return void
	 */
	private function planOccurrence(SeriesPlan $plan, DateTimeImmutable $occStart): void {
		$window = [
			'startTime' => $occStart->format('Y-m-d\TH:i:s\Z'),
			'endTime' => $occStart->modify('+' . $plan->durationMinutes . ' minutes')->format('Y-m-d\TH:i:s\Z'),
		];

		if ($this->isOccurrenceFree(plan: $plan, occStart: $occStart) === false) {
			$plan->skipped[] = ['startTime' => $window['startTime'], 'reason' => 'unavailable'];
			$plan->index++;
			return;
		}

		$plan->generated[] = $this->appointmentFor(plan: $plan, window: $window);
		$plan->existing[]  = $window;
		$plan->index++;
	}//end planOccurrence()

	/**
	 * Whether the occurrence's exact start is one of the slots SlotService
	 * enumerates for its day (opening/closing hours + overlap with existing
	 * appointments) — the single source of truth for availability.
	 *
	 * @param SeriesPlan        $plan     The planning run.
	 * @param DateTimeImmutable $occStart The occurrence start (UTC).
	 *
	 * @return bool
	 */
	private function isOccurrenceFree(SeriesPlan $plan, DateTimeImmutable $occStart): bool {
		$date  = $occStart->format('Y-m-d');
		$slots = $this->slotService->enumerateSlotsPublic(
			date: $date,
			openingTime: $plan->openingTime,
			closingTime: $plan->closingTime,
			durationMinutes: $plan->durationMinutes,
			existingAppointments: $this->filterExistingForDate(existing: $plan->existing, date: $date),
			allowOverlap: $plan->allowOverlap,
		);

		return $this->slotIsAvailable(slots: $slots, wantedStart: $occStart->format('Y-m-d\TH:i:s\Z'));
	}//end isOccurrenceFree()

	/**
	 * The Appointment payload of a planned occurrence.
	 *
	 * @param SeriesPlan            $plan   The planning run.
	 * @param array<string, string> $window The occurrence's startTime and endTime.
	 *
	 * @return array<string, mixed>
	 */
	private function appointmentFor(SeriesPlan $plan, array $window): array {
		$seriesDef = $plan->seriesDef;

		return [
			'administrationId' => (string)($seriesDef['administrationId'] ?? ''),
			'serviceId' => (string)($seriesDef['serviceId'] ?? ''),
			'resourceId' => (string)($seriesDef['resourceId'] ?? ''),
			'customerId' => (string)($seriesDef['customerId'] ?? ''),
			'startTime' => $window['startTime'],
			'endTime' => $window['endTime'],
			'status' => 'confirmed',
			'seriesId' => (string)($seriesDef['seriesId'] ?? ''),
			'recurrenceIndex' => $plan->index,
		];
	}//end appointmentFor()

	/**
	 * Expand a DAILY or WEEKLY rule by walking day-by-day from the series
	 * start, up to the cap, UNTIL, or {@see MAX_OCCURRENCES} weeks of days.
	 *
	 * @param RecurrenceRule $rule The rule.
	 *
	 * @return array<int, DateTimeImmutable>
	 *
	 * @SuppressWarnings(PHPMD.CountInLoopExpression) $occurrences grows
	 *     conditionally inside the loop body — the loop bound is genuinely
	 *     the running count, it cannot be hoisted.
	 */
	private function walkDays(RecurrenceRule $rule): array {
		$occurrences = [];
		$cursor      = $rule->seriesStart;
		$safety      = 0;
		$maxDays     = (self::MAX_OCCURRENCES * 7);

		while (count($occurrences) < $rule->hardCap && $safety < $maxDays) {
			if ($this->isPastUntil(rule: $rule, moment: $cursor) === true) {
				break;
			}

			if ($this->dayQualifies(rule: $rule, day: $cursor) === true) {
				$occurrences[] = $cursor;
			}

			$cursor = $cursor->modify('+1 day');
			$safety++;
		}

		return $occurrences;
	}//end walkDays()

	/**
	 * Whether a day of the walk is an occurrence.
	 *
	 * DAILY: its whole-day offset from the start is a multiple of INTERVAL.
	 * WEEKLY without BYDAY: same weekday, every INTERVAL weeks. WEEKLY with
	 * BYDAY: any listed weekday in an active week (week offset from the start
	 * week is a multiple of INTERVAL).
	 *
	 * @param RecurrenceRule    $rule The rule.
	 * @param DateTimeImmutable $day  The day of the walk.
	 *
	 * @return bool
	 */
	private function dayQualifies(RecurrenceRule $rule, DateTimeImmutable $day): bool {
		$startMidnight = $rule->seriesStart->setTime(0, 0, 0);
		if ($rule->weekly === false) {
			$dayOffset = (int)floor(($day->setTime(0, 0, 0)->getTimestamp() - $startMidnight->getTimestamp()) / 86400);
			return ($dayOffset % $rule->interval === 0);
		}

		$activeWeek = (($this->weekOffset(anchor: $this->weekAnchor(day: $startMidnight), day: $day) % $rule->interval) === 0);
		if ($rule->byDay === []) {
			return $activeWeek && ((int)$day->format('N') === (int)$rule->seriesStart->format('N'));
		}

		return $activeWeek && in_array((int)$day->format('N'), $rule->byDay, true);
	}//end dayQualifies()

	/**
	 * Expand a MONTHLY rule by stepping INTERVAL months from the series start,
	 * preserving the day-of-month and time-of-day. Months without the target
	 * day (e.g. day 31 in a 30-day month) are skipped.
	 *
	 * @param RecurrenceRule $rule The rule.
	 *
	 * @return array<int, DateTimeImmutable>
	 *
	 * @SuppressWarnings(PHPMD.CountInLoopExpression) $occurrences grows
	 *     conditionally inside the loop body — the loop bound is genuinely
	 *     the running count, it cannot be hoisted.
	 */
	private function expandMonthly(RecurrenceRule $rule): array {
		$occurrences  = [];
		$start        = $rule->seriesStart;
		$firstOfMonth = $start->setDate((int)$start->format('Y'), (int)$start->format('n'), 1)->setTime(0, 0, 0);

		$step = 0;
		while (count($occurrences) < $rule->hardCap && $step < self::MAX_OCCURRENCES) {
			$monthBase = $firstOfMonth->modify('+' . ($step * $rule->interval) . ' months');
			$step++;

			$occurrence = $this->dayInMonth(rule: $rule, monthBase: $monthBase);
			if ($this->isPastUntil(rule: $rule, moment: ($occurrence ?? $monthBase)) === true) {
				break;
			}

			if ($this->startsInSeries(rule: $rule, occurrence: $occurrence) === true) {
				$occurrences[] = $occurrence;
			}
		}

		return $occurrences;
	}//end expandMonthly()

	/**
	 * The series' day and time in a month, or null when the month has no
	 * such day (e.g. day 31 in April).
	 *
	 * @param RecurrenceRule    $rule      The rule.
	 * @param DateTimeImmutable $monthBase Midnight on the first of the month.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function dayInMonth(RecurrenceRule $rule, DateTimeImmutable $monthBase): ?DateTimeImmutable {
		$start     = $rule->seriesStart;
		$targetDay = (int)$start->format('j');
		if ($targetDay > (int)$monthBase->format('t')) {
			return null;
		}

		return $monthBase
			->setDate((int)$monthBase->format('Y'), (int)$monthBase->format('n'), $targetDay)
			->setTime((int)$start->format('H'), (int)$start->format('i'), (int)$start->format('s'));
	}//end dayInMonth()

	/**
	 * Whether a moment lies past the rule's inclusive UNTIL.
	 *
	 * @param RecurrenceRule    $rule   The rule.
	 * @param DateTimeImmutable $moment The moment.
	 *
	 * @return bool
	 */
	private function isPastUntil(RecurrenceRule $rule, DateTimeImmutable $moment): bool {
		return $rule->until !== null && $moment > $rule->until;
	}//end isPastUntil()

	/**
	 * Whether a monthly occurrence exists and is not before the series start.
	 *
	 * @param RecurrenceRule         $rule       The rule.
	 * @param DateTimeImmutable|null $occurrence The occurrence, or null.
	 *
	 * @return bool
	 */
	private function startsInSeries(RecurrenceRule $rule, ?DateTimeImmutable $occurrence): bool {
		return $occurrence !== null && $occurrence >= $rule->seriesStart;
	}//end startsInSeries()

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

		try {
			if (preg_match('/^\d{8}(T\d{6}Z?)?$/', $value) === 1) {
				return $this->parseCompactUntil(value: $value);
			}

			return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
		} catch (\Exception $e) {
			$this->logger->warning('Shillinq: unparseable RRULE UNTIL: ' . $value);
			return null;
		}//end try

	}//end parseUntil()

	/**
	 * Parse a compact UNTIL (YYYYMMDD or YYYYMMDDTHHMMSS[Z]) as UTC; a
	 * date-only UNTIL is inclusive of the whole day.
	 *
	 * @param string $value The compact UNTIL value.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function parseCompactUntil(string $value): ?DateTimeImmutable {
		$fmt = 'Ymd';
		if (strlen($value) > 8) {
			$fmt = 'Ymd\THis';
		}

		$until = DateTimeImmutable::createFromFormat('!' . $fmt, rtrim($value, 'Z'), new DateTimeZone('UTC'));
		if ($until === false) {
			return null;
		}

		if (strlen($value) === 8) {
			return $until->setTime(23, 59, 59);
		}

		return $until;
	}//end parseCompactUntil()

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
