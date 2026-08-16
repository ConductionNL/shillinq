<?php

/**
 * Unit tests for UrenTallyService.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Guard\UrenDagregistratieGuard;
use OCA\Shillinq\Service\UrenTallyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers REQ-URC-001: daily tally with reistijd-cap + YTD tally + idempotency.
 */
final class UrenTallyServiceTest extends TestCase {

	/**
	 * Build a service with real guard.
	 *
	 * @return UrenTallyService
	 */
	private function build(): UrenTallyService {
		$logger = $this->createMock(LoggerInterface::class);
		$guard = new UrenDagregistratieGuard(logger: $logger);
		return new UrenTallyService(guard: $guard, logger: $logger);
	}//end build()

	/**
	 * Day tally sums entries and applies the reistijd-cap.
	 *
	 * @return void
	 */
	public function testTallyDagAppliesReistijdCap(): void {
		$result = $this->build()->tallyDag(
			entries: [
				['category' => 'BILLABLE_CLIENT_WORK', 'hours' => 6],
				['category' => 'TRAVEL_TIME_BUSINESS', 'hours' => 8],
				['category' => 'ADMINISTRATION', 'hours' => 1.5],
			]
		);

		self::assertSame(11.5, $result['totalHours']);
		self::assertSame(4.0, $result['perCategory']['TRAVEL_TIME_BUSINESS']);
		self::assertCount(1, $result['overages']);
		self::assertSame('TRAVEL_TIME_BUSINESS', $result['overages'][0]['category']);

	}//end testTallyDagAppliesReistijdCap()

	/**
	 * Tally is idempotent: calling it twice yields the same total.
	 *
	 * @return void
	 */
	public function testTallyDagIsIdempotent(): void {
		$service = $this->build();
		$entries = [
			['category' => 'BILLABLE_CLIENT_WORK', 'hours' => 6],
			['category' => 'TRAVEL_TIME_BUSINESS', 'hours' => 5],
		];

		$first = $service->tallyDag(entries: $entries);
		$second = $service->tallyDag(entries: $entries);

		self::assertSame($first['totalHours'], $second['totalHours']);
		self::assertSame($first['perCategory'], $second['perCategory']);

	}//end testTallyDagIsIdempotent()

	/**
	 * Empty input yields zero with no overages.
	 *
	 * @return void
	 */
	public function testTallyDagEmptyYieldsZero(): void {
		$result = $this->build()->tallyDag(entries: []);
		self::assertSame(0.0, $result['totalHours']);
		self::assertSame([], $result['perCategory']);
		self::assertSame([], $result['overages']);

	}//end testTallyDagEmptyYieldsZero()

	/**
	 * Garbage entries are skipped, not fatal.
	 *
	 * @return void
	 */
	public function testTallyDagSkipsGarbage(): void {
		$result = $this->build()->tallyDag(
			entries: [
				['category' => 'BILLABLE_CLIENT_WORK', 'hours' => 4],
				'garbage',
				['hours' => 2],
				['category' => '', 'hours' => 99],
				['category' => 'ACQUISITION', 'hours' => 2],
			]
		);

		self::assertSame(6.0, $result['totalHours']);

	}//end testTallyDagSkipsGarbage()

	/**
	 * YTD tally returns the canonical UrencriteriumYear patch shape.
	 *
	 * @return void
	 */
	public function testTallyYearToDateReturnsPatch(): void {
		$patch = $this->build()->tallyYearToDate(
			entries: [
				['category' => 'BILLABLE_CLIENT_WORK', 'hours' => 800],
				['category' => 'ACQUISITION', 'hours' => 100],
				['category' => 'TRAVEL_TIME_BUSINESS', 'hours' => 6],
			],
			now: '2026-09-30T23:00:00Z'
		);

		self::assertSame(904.0, $patch['currentHours']);
		self::assertSame('2026-09-30T23:00:00Z', $patch['calculatedOn']);

	}//end testTallyYearToDateReturnsPatch()

}//end class
