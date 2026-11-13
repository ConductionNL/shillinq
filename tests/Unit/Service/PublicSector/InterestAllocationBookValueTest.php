<?php

/**
 * Unit tests for InterestAllocationService::bookValue(): an investment's
 * book value on 1 January from its stored or straight-line depreciation
 * schedule, and when it cannot be told.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service\PublicSector;

use OCA\Shillinq\Service\KapitaallastenCalculator;
use OCA\Shillinq\Service\PublicSector\InterestAllocationService;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\PublicSector\ReserveBalances;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Book value of an investment.
 */
#[CoversClass(InterestAllocationService::class)]
final class InterestAllocationBookValueTest extends TestCase {

	/**
	 * Mock depreciation schedule.
	 *
	 * @var KapitaallastenCalculator&MockObject
	 */
	private KapitaallastenCalculator&MockObject $schedule;

	/**
	 * Build the service.
	 *
	 * @return InterestAllocationService
	 */
	private function service(): InterestAllocationService {
		$this->schedule = $this->createMock(KapitaallastenCalculator::class);

		return new InterestAllocationService(
			$this->createMock(PublicSectorRecords::class),
			$this->createMock(ReserveBalances::class),
			$this->schedule
		);

	}//end service()

	/**
	 * The stored schedule's depreciation of the years before is deducted.
	 *
	 * @return void
	 */
	public function testStoredScheduleDeductsEarlierYears(): void {
		$service = $this->service();
		$this->schedule->expects($this->never())->method('schedule');
		$investment = [
			'gross' => '1000.10',
			'capitalChargesSchedule' => ['2024' => 100.05, '2025' => 100.05, '2026' => 100.05],
		];

		$this->assertSame(1000.10, $service->bookValue($investment, 2024));
		$this->assertSame(900.05, $service->bookValue($investment, 2025));
		$this->assertSame(800.0, $service->bookValue($investment, 2026));
		$this->assertSame(699.95, $service->bookValue($investment, 2030));

	}//end testStoredScheduleDeductsEarlierYears()

	/**
	 * Without a stored schedule the straight-line schedule over the term is used.
	 *
	 * @return void
	 */
	public function testStraightLineScheduleWhenNoneIsStored(): void {
		$service = $this->service();
		$this->schedule->expects($this->once())
			->method('schedule')
			->with(1200.0, 2025, 3)
			->willReturn([2025 => 400.0, 2026 => 400.0, 2027 => 400.0]);

		$this->assertSame(400.0, $service->bookValue(['gross' => 1200, 'firstDepreciationYear' => '2025', 'depreciationTerm' => 3], 2027));

	}//end testStraightLineScheduleWhenNoneIsStored()

	/**
	 * Depreciation beyond the gross amount never gives a negative book value.
	 *
	 * @return void
	 */
	public function testBookValueNeverDropsBelowZero(): void {
		$this->assertSame(0.0, $this->service()->bookValue(['gross' => 100, 'capitalChargesSchedule' => [2020 => 80, 2021 => 80]], 2022));

	}//end testBookValueNeverDropsBelowZero()

	/**
	 * Without a numeric gross amount, or without a schedule, first year and term, the value cannot be told.
	 *
	 * @return void
	 */
	public function testUnknownBookValueIsNull(): void {
		$service = $this->service();
		$this->schedule->expects($this->never())->method('schedule');

		$this->assertNull($service->bookValue([], 2026));
		$this->assertNull($service->bookValue(['gross' => 'n/a', 'capitalChargesSchedule' => [2025 => 1]], 2026));
		$this->assertNull($service->bookValue(['gross' => 100, 'firstDepreciationYear' => 2025], 2026));
		$this->assertNull($service->bookValue(['gross' => 100, 'depreciationTerm' => 5], 2026));
		$this->assertNull($service->bookValue(['gross' => 100, 'firstDepreciationYear' => 2025, 'depreciationTerm' => 0], 2026));

	}//end testUnknownBookValueIsNull()
}//end class
