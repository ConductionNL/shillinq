<?php

/**
 * Characterisation tests for RecurringSeriesService::expandRule.
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
 * @spec openspec/specs/bookings-recurring-series/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
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
 * Pins the occurrences of every frequency, interval, BYDAY, COUNT and UNTIL
 * shape, the UNTIL formats and the cap.
 */
final class RecurrenceExpansionGridTest extends TestCase {

	/**
	 * Warnings the logger received.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * The occurrences of a rule from a start, as ISO strings.
	 *
	 * @param string $rrule The rule.
	 * @param string $start The series start.
	 *
	 * @return array<int, string>
	 */
	private function expand(string $rrule, string $start): array {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isLocalCacheAvailable')->willReturn(false);

		$service = new RecurringSeriesService(
			new SlotService(
				$this->createMock(ContainerInterface::class),
				$this->createMock(SettingsService::class),
				$cacheFactory,
				$this->createMock(ITimeFactory::class),
				$logger
			),
			$logger
		);

		return array_map(
			static fn (DateTimeImmutable $d): string => $d->format('Y-m-d\TH:i:sP'),
			$service->expandRule($rrule, new DateTimeImmutable($start))
		);
	}//end expand()

	/**
	 * Daily and weekly walks.
	 *
	 * @return void
	 */
	public function testDailyAndWeekly(): void {
		self::assertSame(
			['2026-03-02T09:00:00+00:00', '2026-03-05T09:00:00+00:00', '2026-03-08T09:00:00+00:00'],
			$this->expand('RRULE:freq=daily;interval=3;count=3', '2026-03-02T10:00:00+01:00')
		);
		self::assertSame(
			['2026-03-02T09:00:00+00:00', '2026-03-03T09:00:00+00:00'],
			$this->expand('FREQ=DAILY;UNTIL=20260303;BYDAY=MO', '2026-03-02T09:00:00Z')
		);
		self::assertSame(
			['2026-03-04T08:00:00+00:00', '2026-03-18T08:00:00+00:00', '2026-04-01T08:00:00+00:00'],
			$this->expand('FREQ=WEEKLY;INTERVAL=2;COUNT=3', '2026-03-04T08:00:00Z')
		);
		self::assertSame(
			['2026-03-04T08:00:00+00:00', '2026-03-06T08:00:00+00:00', '2026-03-16T08:00:00+00:00', '2026-03-18T08:00:00+00:00', '2026-03-20T08:00:00+00:00'],
			$this->expand('FREQ=WEEKLY;INTERVAL=2;BYDAY=MO, we ,FR,XX;COUNT=5', '2026-03-04T08:00:00Z')
		);
		self::assertSame(
			['2026-03-04T08:00:00+00:00', '2026-03-11T08:00:00+00:00'],
			$this->expand('FREQ=WEEKLY;UNTIL=20260311T080000Z', '2026-03-04T08:00:00Z')
		);
		self::assertSame(
			['2026-03-04T08:00:00+00:00'],
			$this->expand('FREQ=WEEKLY;UNTIL=20260311T075959', '2026-03-04T08:00:00Z')
		);
		self::assertCount(366, $this->expand('FREQ=DAILY', '2026-01-01T00:00:00Z'));
		self::assertCount(366, $this->expand('FREQ=DAILY;COUNT=999', '2026-01-01T00:00:00Z'));
		self::assertSame([], $this->expand('FREQ=DAILY;UNTIL=2025-12-31', '2026-01-01T00:00:00Z'));
	}//end testDailyAndWeekly()

	/**
	 * Monthly steps skip months without the day and stop past UNTIL.
	 *
	 * @return void
	 */
	public function testMonthly(): void {
		self::assertSame(
			['2026-01-31T12:30:15+00:00', '2026-03-31T12:30:15+00:00', '2026-05-31T12:30:15+00:00'],
			$this->expand('FREQ=MONTHLY;COUNT=3', '2026-01-31T12:30:15Z')
		);
		self::assertSame(
			['2026-01-31T12:30:15+00:00', '2026-03-31T12:30:15+00:00'],
			$this->expand('FREQ=MONTHLY;UNTIL=2026-04-15', '2026-01-31T12:30:15Z')
		);
		self::assertSame(
			['2026-01-31T12:30:15+00:00'],
			$this->expand('FREQ=MONTHLY;UNTIL=20260228', '2026-01-31T12:30:15Z')
		);
		self::assertSame(
			['2026-01-15T00:00:00+00:00', '2026-04-15T00:00:00+00:00'],
			$this->expand('FREQ=MONTHLY;INTERVAL=3;COUNT=2', '2026-01-15T00:00:00Z')
		);
		self::assertSame(
			['2026-02-15T00:00:00+00:00'],
			$this->expand('FREQ=MONTHLY;COUNT=1', '2026-01-15T00:00:00.5Z')
		);
		self::assertCount(366, $this->expand('FREQ=MONTHLY', '2026-01-01T00:00:00Z'));
	}//end testMonthly()

	/**
	 * An unreadable UNTIL is logged and ignored; an unknown FREQ is refused.
	 *
	 * @return void
	 */
	public function testUnreadableUntilAndUnknownFrequency(): void {
		self::assertCount(2, $this->expand('FREQ=DAILY;UNTIL=nonsense;COUNT=2', '2026-01-01T00:00:00Z'));
		self::assertCount(2, $this->expand('FREQ=DAILY;UNTIL=20261399;COUNT=2', '2026-01-01T00:00:00Z'));
		self::assertSame(['Shillinq: unparseable RRULE UNTIL: NONSENSE'], $this->warnings);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unsupported or missing RRULE FREQ: "YEARLY"');
		$this->expand('FREQ=YEARLY;UNTIL=bad', '2026-01-01T00:00:00Z');
	}//end testUnreadableUntilAndUnknownFrequency()
}//end class
