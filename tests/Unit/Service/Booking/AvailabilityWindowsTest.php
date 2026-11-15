<?php

/**
 * The availability policy on its own: which rules apply, which wins, which
 * breaks fall on a day, the strictest notice, and whether a slot is admitted.
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
 * @spec openspec/changes/bookings-slots-follow-availability/specs/bookings-availability-rules/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\Booking;

use DateTimeImmutable;
use OCA\Shillinq\Service\Booking\AvailabilityWindows;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * AvailabilityWindows.
 */
#[CoversClass(AvailabilityWindows::class)]
final class AvailabilityWindowsTest extends TestCase {

	/**
	 * The policy under test.
	 *
	 * @var AvailabilityWindows
	 */
	private AvailabilityWindows $windows;

	/**
	 * Build the policy.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->windows = new AvailabilityWindows();
	}//end setUp()

	/**
	 * An active rule on the given days and hours.
	 *
	 * @param mixed  $days  The daysOfWeek value.
	 * @param string $start The start time.
	 * @param string $end   The end time.
	 * @param array  $extra Further fields.
	 *
	 * @return array<string,mixed>
	 */
	private static function rule(mixed $days, string $start, string $end, array $extra = []): array {
		return $extra + ['status' => 'active', 'daysOfWeek' => $days, 'startTime' => $start, 'endTime' => $end];
	}//end rule()

	/**
	 * Only an active rule counts as having rules.
	 *
	 * @return void
	 */
	public function testOnlyAnActiveRuleCounts(): void {
		self::assertFalse($this->windows->hasActiveRule([]));
		self::assertFalse($this->windows->hasActiveRule([['status' => 'draft'], ['status' => 'archived']]));
		self::assertTrue($this->windows->hasActiveRule([['status' => 'draft'], ['status' => 'active']]));
	}//end testOnlyAnActiveRuleCounts()

	/**
	 * The rule naming the fewest weekdays wins; equally specific rules add their windows.
	 *
	 * @return void
	 */
	public function testTheMostSpecificRulesWin(): void {
		$rules = [
			self::rule(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], '09:00', '17:00'),
			self::rule(['wednesday'], '13:30', '16:00'),
			self::rule('Wednesday', '09:00', '12:00'),
			self::rule(['daily'], '07:00', '22:00'),
		];

		self::assertSame([['09:00', '12:00'], ['13:30', '16:00']], $this->windows->windowsOn($rules, '2026-12-02'));
		self::assertSame([['09:00', '17:00']], $this->windows->windowsOn($rules, '2026-12-03'));
		self::assertSame([['07:00', '22:00']], $this->windows->windowsOn($rules, '2026-12-05'));
	}//end testTheMostSpecificRulesWin()

	/**
	 * A rule outside its effective window, inactive or with unusable hours does not apply.
	 *
	 * @return void
	 */
	public function testARuleAppliesOnlyInsideItsEffectiveWindowAndWithHours(): void {
		$rules = [
			self::rule(['thursday'], '09:00', '12:00', ['effectiveFrom' => '2026-12-04']),
			self::rule(['thursday'], '10:00', '11:00', ['effectiveUntil' => '2026-12-02T00:00:00Z']),
			self::rule(['thursday'], '12:00', '11:00'),
			self::rule(['thursday'], '9:00', '11:00'),
			self::rule(['thursday'], '13:00', '14:00', ['status' => 'draft']),
			self::rule(42, '13:00', '14:00'),
		];

		self::assertSame([], $this->windows->windowsOn($rules, '2026-12-03'));
		self::assertSame([['09:00', '12:00']], $this->windows->windowsOn($rules, '2026-12-10'));
		self::assertSame([], $this->windows->windowsOn($rules, 'not-a-date'));
	}//end testARuleAppliesOnlyInsideItsEffectiveWindowAndWithHours()

	/**
	 * The breaks of a day: its weekday and the daily ones, active and well-formed.
	 *
	 * @return void
	 */
	public function testTheBreaksOfADay(): void {
		$breaks = [
			['dayOfWeek' => 'daily', 'startTime' => '12:00', 'endTime' => '12:30'],
			['dayOfWeek' => 'Thursday', 'startTime' => '15:00', 'endTime' => '15:15', 'status' => 'active'],
			['dayOfWeek' => 'friday', 'startTime' => '10:00', 'endTime' => '10:15'],
			['dayOfWeek' => 'thursday', 'startTime' => '16:00', 'endTime' => '16:15', 'status' => 'archived'],
			['dayOfWeek' => 'thursday', 'startTime' => '17:00', 'endTime' => '16:00'],
		];

		self::assertSame([['12:00', '12:30'], ['15:00', '15:15']], $this->windows->breaksOn($breaks, '2026-12-03'));
	}//end testTheBreaksOfADay()

	/**
	 * The strictest active constraint sets each limit.
	 *
	 * @return void
	 */
	public function testTheStrictestActiveConstraintSetsTheLimits(): void {
		self::assertSame(['minHours' => 0, 'minDays' => 0, 'maxDays' => null], $this->windows->noticeLimits([]));
		self::assertSame(
			['minHours' => 4, 'minDays' => 1, 'maxDays' => 30],
			$this->windows->noticeLimits([
				['minAdvanceNoticeHours' => 2, 'maxAdvanceNoticeDays' => 60],
				['minAdvanceNoticeHours' => 4, 'minAdvanceNoticeDays' => 1, 'maxAdvanceNoticeDays' => 30, 'status' => 'active'],
				['minAdvanceNoticeHours' => 48, 'maxAdvanceNoticeDays' => 1, 'status' => 'archived'],
				['maxAdvanceNoticeDays' => null],
			])
		);
	}//end testTheStrictestActiveConstraintSetsTheLimits()

	/**
	 * A slot is refused across a break, inside the notice and beyond the days ahead; admitted otherwise.
	 *
	 * @return void
	 */
	public function testWhetherASlotIsAdmitted(): void {
		$now = new DateTimeImmutable('2026-12-03T10:10:00Z');
		$slot = static fn (string $start, int $minutes): array => [
			new DateTimeImmutable($start),
			(new DateTimeImmutable($start))->modify('+' . $minutes . ' minutes'),
		];
		$breaks = [['12:00', '12:30']];
		$none = ['minHours' => 0, 'minDays' => 0, 'maxDays' => null];

		[$start, $end] = $slot('2026-12-03T11:45:00Z', 30);
		self::assertFalse($this->windows->admits($start, $end, $breaks, $none, $now));
		[$start, $end] = $slot('2026-12-03T11:30:00Z', 30);
		self::assertTrue($this->windows->admits($start, $end, $breaks, $none, $now));
		self::assertFalse($this->windows->admits($start, $end, [], ['minHours' => 2] + $none, $now));
		[$start, $end] = $slot('2026-12-03T12:15:00Z', 30);
		self::assertTrue($this->windows->admits($start, $end, [], ['minHours' => 2] + $none, $now));

		[$start, $end] = $slot('2026-12-03T15:00:00Z', 30);
		self::assertFalse($this->windows->admits($start, $end, [], ['minDays' => 1] + $none, $now));
		[$start, $end] = $slot('2026-12-04T09:00:00Z', 30);
		self::assertTrue($this->windows->admits($start, $end, [], ['minDays' => 1] + $none, $now));

		[$start, $end] = $slot('2026-12-10T09:00:00Z', 30);
		self::assertTrue($this->windows->admits($start, $end, [], ['maxDays' => 7] + $none, $now));
		[$start, $end] = $slot('2026-12-11T09:00:00Z', 30);
		self::assertFalse($this->windows->admits($start, $end, [], ['maxDays' => 7] + $none, $now));
	}//end testWhetherASlotIsAdmitted()
}//end class
