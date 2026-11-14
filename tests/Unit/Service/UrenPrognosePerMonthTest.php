<?php

/**
 * Pins UrenPrognoseService's per-month forecast: months from the as-of month
 * to December, planned overrides, full and partial holiday months, the
 * seasonal factors, and an unparsable as-of date.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\UrenPrognoseService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The per-month forecast before it is split into named steps.
 *
 * @covers \OCA\Shillinq\Service\UrenPrognoseService
 */
final class UrenPrognosePerMonthTest extends TestCase {

	/**
	 * Run the forecast.
	 *
	 * @param string           $asOf        As-of date.
	 * @param array<int,mixed> $vakanties   Holiday ranges.
	 * @param array<int,mixed> $assignments Planned assignments.
	 *
	 * @return array<string,float>
	 */
	private function forecast(string $asOf, array $vakanties, array $assignments): array {
		$service = new UrenPrognoseService(logger: $this->createMock(LoggerInterface::class));
		$method = new ReflectionMethod(UrenPrognoseService::class, 'perMonthPrognose');

		return $method->invoke($service, $asOf, 2026, 30.0, $vakanties, $assignments);

	}//end forecast()

	/**
	 * Overrides win over holidays; full holidays zero a month; partial ones
	 * halve it; other months follow the seasonal factors.
	 *
	 * @return void
	 */
	public function testForecastFromSeptember(): void {
		$result = $this->forecast(
			'2026-09-15',
			['2026-10-01/2026-10-31', '2026-11-20/2026-11-25', '2026-12-01/2026-12-31'],
			[
				['month' => '2026-12', 'hours' => '12.5'],
				'not an assignment',
				['month' => '', 'hours' => 99],
				['month' => '2026-09', 'hours' => 0],
				['month' => '2026-09', 'hours' => 7],
				['month' => '2025-10', 'hours' => 1],
			]
		);

		self::assertSame(['2026-09', '2026-10', '2026-11', '2026-12'], array_keys($result));
		self::assertSame(7.0, $result['2026-09']);
		self::assertSame(0.0, $result['2026-10']);
		self::assertSame(12.5, $result['2026-12']);
		self::assertSame($this->forecast('2026-11-01', [], [])['2026-11'] / 2, $result['2026-11']);

	}//end testForecastFromSeptember()

	/**
	 * Every month of a year without holidays or plans, pinned.
	 *
	 * @return void
	 */
	public function testSeasonalYear(): void {
		self::assertSame(
			'0a53abd6',
			substr(md5(json_encode($this->forecast('2026-01-10', [], []), JSON_PRESERVE_ZERO_FRACTION)), 0, 8)
		);

	}//end testSeasonalYear()

	/**
	 * An unparsable as-of date forecasts nothing.
	 *
	 * @return void
	 */
	public function testUnparsableAsOf(): void {
		self::assertSame([], $this->forecast('someday', [], [['month' => '2026-05', 'hours' => 3]]));

	}//end testUnparsableAsOf()
}//end class
