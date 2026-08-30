<?php

/**
 * Unit tests for KapitaallastenCalculator.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-25
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\KapitaallastenCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the straight-line kapitaallasten schedule (REQ-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class KapitaallastenCalculatorTest extends TestCase {

	/**
	 * The calculator under test.
	 *
	 * @var KapitaallastenCalculator
	 */
	private KapitaallastenCalculator $calculator;

	/**
	 * Set up the calculator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calculator = new KapitaallastenCalculator();

	}//end setUp()

	/**
	 * REQ-005 scenario: 400000 / 20 years = 20000 per year starting 2027.
	 *
	 * @return void
	 */
	public function testEvenScheduleAcrossTwentyYears(): void {
		$schedule = $this->calculator->schedule(gross: 400000.0, firstDepreciationYear: 2027, depreciationTerm: 20);

		self::assertCount(20, $schedule);
		self::assertSame(20000.0, $schedule['2027']);
		self::assertSame(20000.0, $schedule['2046']);
		self::assertArrayNotHasKey('2047', $schedule);

	}//end testEvenScheduleAcrossTwentyYears()

	/**
	 * The schedule sums exactly to the gross amount (remainder in the final year).
	 *
	 * @return void
	 */
	public function testScheduleSumsExactlyToGross(): void {
		// 100 / 3 = 33.33... — cents: 3333 + 3333 + 3334 = 10000.
		$schedule = $this->calculator->schedule(gross: 100.0, firstDepreciationYear: 2027, depreciationTerm: 3);

		self::assertSame(33.33, $schedule['2027']);
		self::assertSame(33.33, $schedule['2028']);
		self::assertSame(33.34, $schedule['2029']);
		self::assertSame(100.0, array_sum($schedule));

	}//end testScheduleSumsExactlyToGross()

	/**
	 * A zero/invalid termijn yields an empty schedule (fail-safe).
	 *
	 * @return void
	 */
	public function testZeroTermijnYieldsEmptySchedule(): void {
		self::assertSame([], $this->calculator->schedule(gross: 1000.0, firstDepreciationYear: 2027, depreciationTerm: 0));

	}//end testZeroTermijnYieldsEmptySchedule()

	// phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
