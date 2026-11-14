<?php

/**
 * Offered slots follow the availability rules, the breaks and the booking
 * constraints (REQ-BAR-011): the three scenarios of the spec, the fallback
 * to the resource's own hours, and the fail-closed read.
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
 * @spec openspec/changes/bookings-slots-follow-availability/specs/bookings-availability-rules/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\Booking\AvailabilityWindows;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Service\SlotService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * SlotService over an in-memory register holding Sanne's rules.
 */
#[CoversClass(SlotService::class)]
#[UsesClass(AvailabilityWindows::class)]
final class SlotServiceAvailabilityRulesTest extends TestCase {

	/**
	 * Sanne's weekday rule.
	 *
	 * @var array<string,mixed>
	 */
	private const WEEK = ['availabilityRuleId' => 'r-week', 'resource' => 'sanne', 'status' => 'active', 'daysOfWeek' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'startTime' => '09:00', 'endTime' => '17:00'];

	/**
	 * Sanne's Wednesday-morning rule.
	 *
	 * @var array<string,mixed>
	 */
	private const WEDNESDAY = ['availabilityRuleId' => 'r-wed', 'resource' => 'sanne', 'status' => 'active', 'daysOfWeek' => ['wednesday'], 'startTime' => '09:00', 'endTime' => '12:30'];

	/**
	 * Sanne's daily lunch break.
	 *
	 * @var array<string,mixed>
	 */
	private const LUNCH = ['availabilityRule' => 'r-week', 'dayOfWeek' => 'daily', 'startTime' => '12:00', 'endTime' => '12:30', 'status' => 'active'];

	/**
	 * The slot service over the given rows, at the given moment.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $rows  Rows by schema.
	 * @param string                                       $now   The moment, ISO 8601.
	 * @param string|null                                  $fails A schema whose read throws.
	 *
	 * @return SlotService The service.
	 */
	private function service(array $rows, string $now, ?string $fails = null): SlotService {
		$rows += [
			'Service' => [['serviceId' => 'cut', 'duration' => 45]],
			'Resource' => [['resourceId' => 'sanne', 'openingTime' => '08:00', 'closingTime' => '20:00']],
		];
		$objects = new class($rows, $fails) {
			/**
			 * The schema being read.
			 *
			 * @var string
			 */
			private string $schema = '';

			/**
			 * Constructor.
			 *
			 * @param array<string,array<int,array<string,mixed>>> $rows  Rows by schema.
			 * @param string|null                                  $fails The failing schema.
			 */
			public function __construct(private readonly array $rows, private readonly ?string $fails) {
			}

			/**
			 * Select the register.
			 *
			 * @param string $register The register.
			 *
			 * @return static
			 */
			public function setRegister(string $register): static {
				return $this;
			}

			/**
			 * Select the schema.
			 *
			 * @param string $schema The schema.
			 *
			 * @return static
			 */
			public function setSchema(string $schema): static {
				$this->schema = $schema;
				return $this;
			}

			/**
			 * The rows of the schema matching every filter.
			 *
			 * @param array<string,mixed> $config The config with filters.
			 * @param bool                $_rbac  Ignored.
			 *
			 * @return array<int,array<string,mixed>>
			 */
			public function findAll(array $config = [], bool $_rbac = true): array {
				if ($this->schema === $this->fails) {
					throw new RuntimeException('read failed');
				}

				return array_values(array_filter(
					$this->rows[$this->schema] ?? [],
					static function (array $row) use ($config): bool {
						foreach (($config['filters'] ?? []) as $field => $value) {
							if (($row[$field] ?? null) !== $value) {
								return false;
							}
						}

						return true;
					}
				));
			}
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($objects);
		$location = $this->createMock(RegisterLocation::class);
		$location->method('isOpenRegisterAvailable')->willReturn(true);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$cache = $this->createMock(ICacheFactory::class);
		$cache->method('isLocalCacheAvailable')->willReturn(false);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(strtotime($now));

		return new SlotService($container, $location, $cache, $time, $this->createMock(LoggerInterface::class));
	}//end service()

	/**
	 * The start times (HH:MM) of the slots offered.
	 *
	 * @param SlotService $service The service.
	 * @param string      $date    The date.
	 *
	 * @return array<int,string>
	 */
	private static function starts(SlotService $service, string $date): array {
		return array_map(
			static fn (array $slot): string => substr($slot['startTime'], 11, 5),
			$service->getAvailableSlots('cut', 'sanne', $date)['slots']
		);
	}//end starts()

	/**
	 * Wednesday follows the Wednesday-morning rule: the last 45 minute slot starts at 11:45.
	 *
	 * @return void
	 */
	public function testAStaffMemberWorksMorningsOnlyOnWednesday(): void {
		$starts = self::starts($this->service(['AvailabilityRule' => [self::WEEK, self::WEDNESDAY]], '2026-12-01T00:00:00Z'), '2026-12-02');

		self::assertSame('09:00', $starts[0]);
		self::assertSame('11:45', end($starts));
		self::assertCount(12, $starts);
	}//end testAStaffMemberWorksMorningsOnlyOnWednesday()

	/**
	 * Thursday follows the weekday rule, which ends at 17:00.
	 *
	 * @return void
	 */
	public function testTheWeekdayRuleHoldsOnOtherDays(): void {
		$starts = self::starts($this->service(['AvailabilityRule' => [self::WEEK, self::WEDNESDAY]], '2026-12-01T00:00:00Z'), '2026-12-03');

		self::assertSame('09:00', $starts[0]);
		self::assertSame('16:15', end($starts));
	}//end testTheWeekdayRuleHoldsOnOtherDays()

	/**
	 * A 30 minute service is not offered across the 12:00 to 12:30 lunch break.
	 *
	 * @return void
	 */
	public function testALunchBreakIsNotOffered(): void {
		$service = $this->service(
			['Service' => [['serviceId' => 'cut', 'duration' => 30]], 'AvailabilityRule' => [self::WEEK], 'ResourceBreak' => [self::LUNCH]],
			'2026-12-01T00:00:00Z'
		);
		$starts = self::starts($service, '2026-12-03');

		self::assertContains('11:30', $starts);
		self::assertContains('12:30', $starts);
		foreach (['11:45', '12:00', '12:15'] as $blocked) {
			self::assertNotContains($blocked, $starts);
		}
	}//end testALunchBreakIsNotOffered()

	/**
	 * Two hours' notice at 10:10 with a lunch break: the first slot of the day is 12:30.
	 *
	 * @return void
	 */
	public function testTheMinimumNoticeKeepsTheNextHoursFree(): void {
		$service = $this->service(
			[
				'Service' => [['serviceId' => 'cut', 'duration' => 30]],
				'AvailabilityRule' => [self::WEEK],
				'ResourceBreak' => [self::LUNCH],
				'BookingConstraint' => [['availabilityRule' => 'r-week', 'minAdvanceNoticeHours' => 2, 'status' => 'active']],
			],
			'2026-12-04T10:10:00Z'
		);

		self::assertSame('12:30', self::starts($service, '2026-12-04')[0]);
	}//end testTheMinimumNoticeKeepsTheNextHoursFree()

	/**
	 * A date beyond the maximum days ahead offers nothing; one inside it does.
	 *
	 * @return void
	 */
	public function testTheMaximumDaysAheadCutsOffLaterDates(): void {
		$rows = [
			'AvailabilityRule' => [self::WEEK],
			'BookingConstraint' => [['availabilityRule' => 'r-week', 'maxAdvanceNoticeDays' => 7, 'status' => 'active']],
		];

		self::assertNotSame([], self::starts($this->service($rows, '2026-12-01T00:00:00Z'), '2026-12-08'));
		self::assertSame([], self::starts($this->service($rows, '2026-12-01T00:00:00Z'), '2026-12-09'));
	}//end testTheMaximumDaysAheadCutsOffLaterDates()

	/**
	 * A day no active rule names offers nothing, rather than the resource's own hours.
	 *
	 * @return void
	 */
	public function testADayNoRuleNamesOffersNothing(): void {
		self::assertSame([], self::starts($this->service(['AvailabilityRule' => [self::WEEK]], '2026-12-01T00:00:00Z'), '2026-12-05'));
	}//end testADayNoRuleNamesOffersNothing()

	/**
	 * A resource without an active rule keeps its own opening and closing time.
	 *
	 * @return void
	 */
	public function testAResourceWithoutActiveRulesKeepsItsOwnHours(): void {
		$draft = ['status' => 'draft'] + self::WEEK;
		$starts = self::starts($this->service(['AvailabilityRule' => [$draft]], '2026-12-01T00:00:00Z'), '2026-12-05');

		self::assertSame('08:00', $starts[0]);
		self::assertSame('19:15', end($starts));
	}//end testAResourceWithoutActiveRulesKeepsItsOwnHours()

	/**
	 * When the rules or the breaks cannot be read, nothing is offered.
	 *
	 * @return void
	 */
	public function testAFailedAvailabilityReadOffersNothing(): void {
		self::assertSame([], self::starts($this->service(['AvailabilityRule' => [self::WEEK]], '2026-12-01T00:00:00Z', 'AvailabilityRule'), '2026-12-03'));
		self::assertSame([], self::starts($this->service(['AvailabilityRule' => [self::WEEK]], '2026-12-01T00:00:00Z', 'ResourceBreak'), '2026-12-03'));
	}//end testAFailedAvailabilityReadOffersNothing()
}//end class
