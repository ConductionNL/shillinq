<?php

/**
 * Unit tests for the monthly depreciation run: which month it posts, which assets it refreshes, and what it reports.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Asset
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

namespace OCA\Shillinq\Tests\Unit\Asset;

use DateTimeImmutable;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Asset\DepreciationRun;
use OCA\Shillinq\Service\Asset\DepreciationScheduleService;
use OCA\Shillinq\Service\Asset\FixedAssetDepreciation;
use OCA\Shillinq\Service\Asset\ReinvestmentReserves;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The run posts last month, refreshes only the active assets whose figures
 * moved, and releases expired reserves as of today.
 *
 * @covers \OCA\Shillinq\Service\Asset\DepreciationRun
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class DepreciationRunTest extends TestCase {

	/**
	 * @var DepreciationScheduleService&MockObject
	 */
	private DepreciationScheduleService $schedules;

	/**
	 * @var FixedAssetDepreciation&MockObject
	 */
	private FixedAssetDepreciation $depreciation;

	/**
	 * @var ReinvestmentReserves&MockObject
	 */
	private ReinvestmentReserves $reserves;

	/**
	 * @var AssetRecords&MockObject
	 */
	private AssetRecords $records;

	/**
	 * Fresh collaborator mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schedules = $this->createMock(DepreciationScheduleService::class);
		$this->depreciation = $this->createMock(FixedAssetDepreciation::class);
		$this->reserves = $this->createMock(ReinvestmentReserves::class);
		$this->records = $this->createMock(AssetRecords::class);
	}//end setUp()

	/**
	 * The run under test.
	 *
	 * @return DepreciationRun
	 */
	private function depreciationRun(): DepreciationRun {
		return new DepreciationRun($this->schedules, $this->depreciation, $this->reserves, $this->records);
	}//end depreciationRun()

	/**
	 * The month posted is the calendar month before today, across month ends and the year boundary.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function months(): array {
		return [
			'mid month'         => ['2026-05-15', '2026-04'],
			'31st after 30-day' => ['2026-05-31', '2026-04'],
			'March 31st'        => ['2026-03-31', '2026-02'],
			'January'           => ['2026-01-01', '2025-12'],
		];
	}//end months()

	/**
	 * Last month is posted and the reserves are released as of today.
	 *
	 * @param string $today The run day.
	 * @param string $month The month that must be posted.
	 *
	 * @dataProvider months
	 *
	 * @return void
	 */
	public function testItPostsLastMonthAndReleasesAsOfToday(string $today, string $month): void {
		$this->schedules->expects($this->once())->method('postMonth')->with($month)->willReturn(['journals' => 2, 'lines' => 7]);
		$this->records->expects($this->once())->method('records')->with(DepreciationScheduleService::ASSET, ['status' => 'active'])->willReturn([]);
		$this->records->expects($this->never())->method('patch');
		$this->reserves->expects($this->once())->method('releaseExpired')->with($today)->willReturn(1);

		$this->assertSame(
			['month' => $month, 'journals' => 2, 'lines' => 7, 'assets' => 0, 'released' => 1],
			$this->depreciationRun()->run(new DateTimeImmutable($today))
		);
	}//end testItPostsLastMonthAndReleasesAsOfToday()

	/**
	 * Only assets whose monthly depreciation or book value moved are patched, with exactly those two fields.
	 *
	 * @return void
	 */
	public function testOnlyAssetsWhoseFiguresMovedArePatched(): void {
		$unchanged = ['id' => 'a-1', 'monthlyDepreciation' => 100.0, 'currentBookValue' => 900.0];
		$moved = ['id' => 'a-2', 'monthlyDepreciation' => 50.0, 'currentBookValue' => 500.0, 'name' => 'Van'];
		$fresh = ['id' => 'a-3', 'name' => 'Laptop'];
		$this->schedules->method('postMonth')->willReturn(['journals' => 1, 'lines' => 3]);
		$this->records->method('records')->willReturn([$unchanged, $moved, $fresh]);
		$this->depreciation->expects($this->exactly(3))->method('withScheduleFigures')->willReturnCallback(
			function (array $asset, string $onDate): array {
				$this->assertSame('2026-06-10', $onDate);
				return match ($asset['id']) {
					'a-1' => $asset,
					'a-2' => array_merge($asset, ['currentBookValue' => 450.0]),
					'a-3' => array_merge($asset, ['monthlyDepreciation' => 20.0, 'currentBookValue' => 980.0]),
				};
			}
		);
		$patched = [];
		$this->records->expects($this->exactly(2))->method('patch')->willReturnCallback(
			static function (string $schema, string $id, array $fields) use (&$patched): void {
				$patched[] = [$schema, $id, $fields];
			}
		);
		$this->reserves->method('releaseExpired')->willReturn(0);

		$result = $this->depreciationRun()->run(new DateTimeImmutable('2026-06-10'));

		$this->assertSame(
			[
				[DepreciationScheduleService::ASSET, 'a-2', ['monthlyDepreciation' => 50.0, 'currentBookValue' => 450.0]],
				[DepreciationScheduleService::ASSET, 'a-3', ['monthlyDepreciation' => 20.0, 'currentBookValue' => 980.0]],
			],
			$patched
		);
		$this->assertSame(2, $result['assets']);
	}//end testOnlyAssetsWhoseFiguresMovedArePatched()

	/**
	 * Figures equal as text are no change: 100 and 100.0 do not trigger a patch.
	 *
	 * @return void
	 */
	public function testFiguresThatOnlyChangeTypeAreNoChange(): void {
		$this->schedules->method('postMonth')->willReturn(['journals' => 0, 'lines' => 0]);
		$this->records->method('records')->willReturn([['id' => 'a-1', 'monthlyDepreciation' => 100, 'currentBookValue' => '900']]);
		$this->depreciation->method('withScheduleFigures')->willReturn(['id' => 'a-1', 'monthlyDepreciation' => 100.0, 'currentBookValue' => 900]);
		$this->records->expects($this->never())->method('patch');
		$this->reserves->method('releaseExpired')->willReturn(0);

		$this->assertSame(0, $this->depreciationRun()->run(new DateTimeImmutable('2026-06-10'))['assets']);
	}//end testFiguresThatOnlyChangeTypeAreNoChange()
}//end class
