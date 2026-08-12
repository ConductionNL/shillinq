<?php

/**
 * Unit tests for RecurringSeriesService.
 *
 * Covers the bookings-depth recurring-appointment-series requirement: an
 * RRULE-style recurrence expands into the correct individual occurrences, and
 * the series planner (reusing SlotService's availability/conflict engine)
 * generates individual appointments while SKIPPING occurrences that violate
 * the availability (opening/closing hours) or conflict (overlap) rules.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookings-depth/specs/bookings-recurring-series/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Shillinq\Service\RecurringSeriesService;
use OCA\Shillinq\Service\SettingsService;
use OCA\Shillinq\Service\SlotService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for RRULE expansion + series planning with availability/conflict reuse.
 *
 * @spec openspec/changes/bookings-depth/specs/bookings-recurring-series/spec.md
 */
final class RecurringSeriesServiceTest extends TestCase {

	/**
	 * Service under test.
	 *
	 * @var RecurringSeriesService
	 */
	private RecurringSeriesService $service;

	/**
	 * Build a real SlotService (its enumeration logic is the reused engine) with
	 * the time frozen well before the 2030 test dates so nothing is filtered as
	 * "past", then wrap it in the RecurringSeriesService.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$container = $this->createMock(ContainerInterface::class);
		$settings = $this->createMock(SettingsService::class);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$time = $this->createMock(ITimeFactory::class);
		$logger = $this->createMock(LoggerInterface::class);

		$cacheFactory->method('isLocalCacheAvailable')->willReturn(false);
		$time->method('getTime')->willReturn(strtotime('2029-01-01T00:00:00Z'));

		$slotService = new SlotService(
			container: $container,
			settings: $settings,
			cacheFactory: $cacheFactory,
			time: $time,
			logger: $logger,
		);

		$this->service = new RecurringSeriesService(
			slotService: $slotService,
			logger: $logger,
		);

	}//end setUp()

	/**
	 * A weekly Monday series with COUNT=4 expands to four consecutive Mondays.
	 *
	 * @return void
	 */
	public function testExpandWeeklyByDayCount(): void {
		$occ = $this->service->expandRule(
			rrule: 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
			seriesStart: new DateTimeImmutable('2030-01-07T09:00:00Z', new DateTimeZone('UTC')),
		);

		$dates = array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d\TH:i:s\Z'), $occ);
		self::assertSame(
			[
				'2030-01-07T09:00:00Z',
				'2030-01-14T09:00:00Z',
				'2030-01-21T09:00:00Z',
				'2030-01-28T09:00:00Z',
			],
			$dates
		);

	}//end testExpandWeeklyByDayCount()

	/**
	 * A daily series with INTERVAL=2 and COUNT=3 skips every other day.
	 *
	 * @return void
	 */
	public function testExpandDailyInterval(): void {
		$occ = $this->service->expandRule(
			rrule: 'FREQ=DAILY;INTERVAL=2;COUNT=3',
			seriesStart: new DateTimeImmutable('2030-03-01T10:00:00Z', new DateTimeZone('UTC')),
		);

		$dates = array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d'), $occ);
		self::assertSame(['2030-03-01', '2030-03-03', '2030-03-05'], $dates);

	}//end testExpandDailyInterval()

	/**
	 * A monthly series with COUNT=3 preserves the day-of-month across months.
	 *
	 * @return void
	 */
	public function testExpandMonthly(): void {
		$occ = $this->service->expandRule(
			rrule: 'FREQ=MONTHLY;COUNT=3',
			seriesStart: new DateTimeImmutable('2030-01-15T14:00:00Z', new DateTimeZone('UTC')),
		);

		$dates = array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d'), $occ);
		self::assertSame(['2030-01-15', '2030-02-15', '2030-03-15'], $dates);

	}//end testExpandMonthly()

	/**
	 * A daily series bounded by UNTIL stops on the inclusive final date.
	 *
	 * @return void
	 */
	public function testExpandDailyUntilInclusive(): void {
		$occ = $this->service->expandRule(
			rrule: 'FREQ=DAILY;UNTIL=20300103',
			seriesStart: new DateTimeImmutable('2030-01-01T09:00:00Z', new DateTimeZone('UTC')),
		);

		$dates = array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d'), $occ);
		self::assertSame(['2030-01-01', '2030-01-02', '2030-01-03'], $dates);

	}//end testExpandDailyUntilInclusive()

	/**
	 * An unsupported / missing FREQ throws so the caller surfaces a 400.
	 *
	 * @return void
	 */
	public function testUnsupportedFreqThrows(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->expandRule(
			rrule: 'FREQ=YEARLY;COUNT=2',
			seriesStart: new DateTimeImmutable('2030-01-01T09:00:00Z', new DateTimeZone('UTC')),
		);

	}//end testUnsupportedFreqThrows()

	/**
	 * A full weekly series with no conflicts generates one appointment per
	 * occurrence, each tagged with its zero-based recurrenceIndex.
	 *
	 * @return void
	 */
	public function testPlanSeriesGeneratesAllWhenNoConflict(): void {
		$plan = $this->service->planSeries(
			seriesDef: [
				'seriesId' => 'series-1',
				'administrationId' => 'admin-1',
				'serviceId' => 'svc-yoga',
				'resourceId' => 'res-a',
				'customerId' => 'cust-1',
				'startTime' => '2030-01-07T09:00:00Z',
				'durationMinutes' => 60,
				'recurrenceRule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
				'openingTime' => '09:00',
				'closingTime' => '17:00',
				'existingAppointments' => [],
			]
		);

		self::assertCount(4, $plan['generated']);
		self::assertCount(0, $plan['skipped']);
		self::assertSame('2030-01-07T09:00:00Z', $plan['generated'][0]['startTime']);
		self::assertSame('2030-01-07T10:00:00Z', $plan['generated'][0]['endTime']);
		self::assertSame(0, $plan['generated'][0]['recurrenceIndex']);
		self::assertSame(3, $plan['generated'][3]['recurrenceIndex']);
		self::assertSame('series-1', $plan['generated'][0]['seriesId']);

	}//end testPlanSeriesGeneratesAllWhenNoConflict()

	/**
	 * An occurrence overlapping an existing appointment is SKIPPED (conflict
	 * rule) while the remaining occurrences are generated.
	 *
	 * @return void
	 */
	public function testPlanSeriesSkipsConflictingOccurrence(): void {
		$plan = $this->service->planSeries(
			seriesDef: [
				'seriesId' => 'series-2',
				'serviceId' => 'svc-yoga',
				'resourceId' => 'res-a',
				'startTime' => '2030-01-07T09:00:00Z',
				'durationMinutes' => 60,
				'recurrenceRule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=4',
				'openingTime' => '09:00',
				'closingTime' => '17:00',
				// The 2030-01-14 09:00-10:00 window is already booked → conflict.
				'existingAppointments' => [
					['startTime' => '2030-01-14T09:00:00Z', 'endTime' => '2030-01-14T10:00:00Z'],
				],
			]
		);

		self::assertCount(3, $plan['generated']);
		self::assertCount(1, $plan['skipped']);
		self::assertSame('2030-01-14T09:00:00Z', $plan['skipped'][0]['startTime']);
		self::assertSame('unavailable', $plan['skipped'][0]['reason']);

		$generatedStarts = array_map(static fn (array $a): string => $a['startTime'], $plan['generated']);
		self::assertNotContains('2030-01-14T09:00:00Z', $generatedStarts);
		self::assertContains('2030-01-21T09:00:00Z', $generatedStarts);

	}//end testPlanSeriesSkipsConflictingOccurrence()

	/**
	 * An occurrence whose slot falls outside the resource opening hours is
	 * SKIPPED (availability rule).
	 *
	 * @return void
	 */
	public function testPlanSeriesSkipsOutOfHoursOccurrence(): void {
		$plan = $this->service->planSeries(
			seriesDef: [
				'seriesId' => 'series-3',
				'serviceId' => 'svc-yoga',
				'resourceId' => 'res-a',
				// 08:00 start is before the 09:00 opening → every occurrence
				// is out of hours and skipped.
				'startTime' => '2030-01-07T08:00:00Z',
				'durationMinutes' => 60,
				'recurrenceRule' => 'FREQ=WEEKLY;BYDAY=MO;COUNT=2',
				'openingTime' => '09:00',
				'closingTime' => '17:00',
				'existingAppointments' => [],
			]
		);

		self::assertCount(0, $plan['generated']);
		self::assertCount(2, $plan['skipped']);
		self::assertSame('unavailable', $plan['skipped'][0]['reason']);

	}//end testPlanSeriesSkipsOutOfHoursOccurrence()
}//end class
