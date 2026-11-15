<?php

/**
 * Pins UrenPrognoseService's holiday-month classification: which months a
 * vakantie range touches ('partial') and when its start month is fully
 * covered ('full'), over malformed, overlapping and multi-month ranges.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\UrenPrognoseService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * The holiday-month map before it is split into named steps.
 *
 * @covers \OCA\Shillinq\Service\UrenPrognoseService
 */
final class UrenPrognoseHolidayMonthsTest extends TestCase {

	/**
	 * Vakantie lists and the month map they produce.
	 *
	 * @return array<string, array{0: array<int,mixed>, 1: array<string,string>}>
	 */
	public static function ranges(): array {
		return [
			'none'                 => [[], []],
			'not text or no slash' => [[42, null, '2026-08-01', ['x']], []],
			'unparsable'           => [['soon/later', '2026-08-01/never'], []],
			'one day'              => [['2026-03-10/2026-03-10'], ['2026-03' => 'partial']],
			'full month'           => [['2026-08-01/2026-08-31'], ['2026-08' => 'full']],
			'into next month'      => [['2026-07-01/2026-08-03'], ['2026-07' => 'full', '2026-08' => 'partial']],
			'late start'           => [['2026-07-02/2026-09-01'], ['2026-07' => 'partial', '2026-08' => 'partial', '2026-09' => 'partial']],
			'end before start'     => [['2026-05-10/2026-05-01'], []],
			'overlap keeps full'   => [['2026-08-01/2026-08-31', '2026-08-10/2026-08-12'], ['2026-08' => 'full']],
			'later full wins'      => [['2026-08-10/2026-08-12', '2026-08-01/2026-08-31'], ['2026-08' => 'full']],
			'february'             => [['2028-02-01/2028-02-29'], ['2028-02' => 'full']],
		];

	}//end ranges()

	/**
	 * Each list produces the pinned month map, in order.
	 *
	 * @param array<int,mixed>     $vakanties The ranges.
	 * @param array<string,string> $expected  The month map.
	 *
	 * @return void
	 */
	#[DataProvider('ranges')]
	public function testHolidayMonths(array $vakanties, array $expected): void {
		$service = new UrenPrognoseService(logger: $this->createMock(LoggerInterface::class));
		$method = new ReflectionMethod(UrenPrognoseService::class, 'holidayMonths');

		self::assertSame($expected, $method->invoke($service, $vakanties));

	}//end testHolidayMonths()
}//end class
