<?php

/**
 * Unit tests for FixedAssetDepreciation.
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
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Asset;

use DomainException;
use OCA\Shillinq\Service\Asset\DepreciationScheduleService;
use OCA\Shillinq\Service\Asset\FixedAssetDepreciation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Revisions and extra depreciation hand their inputs to the schedule and
 * read the asset's figures back from it.
 *
 * @covers \OCA\Shillinq\Service\Asset\FixedAssetDepreciation
 */
final class FixedAssetDepreciationTest extends TestCase {

	/**
	 * The schedule service.
	 *
	 * @var DepreciationScheduleService&MockObject
	 */
	private DepreciationScheduleService $schedules;

	/**
	 * The schedule's figures for the asset.
	 *
	 * @var array<string, mixed>
	 */
	private const FIGURES = ['id' => 'fa-1', 'method' => 'linear', 'months' => 60, 'costCents' => 1200000];

	/**
	 * A schedule double answering the figures and the given lines.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schedules = $this->createMock(DepreciationScheduleService::class);
		$this->schedules->method('figures')->willReturn(self::FIGURES);
	}//end setUp()

	/**
	 * Revisions missing a date or a reason.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function incompleteRevisions(): array {
		return [
			'no date' => [['revisionReason' => 'Longer use']],
			'blank date' => [['revisionDate' => '  ', 'revisionReason' => 'Longer use']],
			'no reason' => [['revisionDate' => '2026-07-01']],
			'blank reason' => [['revisionDate' => '2026-07-01', 'revisionReason' => ' ']],
		];
	}//end incompleteRevisions()

	/**
	 * A revision without a date and a reason is refused before the schedule
	 * is touched.
	 *
	 * @param array<string, mixed> $asset The asset.
	 *
	 * @dataProvider incompleteRevisions
	 *
	 * @return void
	 */
	public function testAnIncompleteRevisionIsRefused(array $asset): void {
		$this->schedules->expects($this->never())->method('revise');

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('A revision needs a date and a reason.');

		$this->service()->revise($asset);
	}//end testAnIncompleteRevisionIsRefused()

	/**
	 * Without a revised method or life the current ones are kept; the revised
	 * fields are cleared on the asset.
	 *
	 * @return void
	 */
	public function testARevisionKeepsTheCurrentMethodAndLifeByDefault(): void {
		$this->schedules->method('lines')->willReturn([]);
		$this->schedules->expects($this->once())->method('revise')
			->with(
				$this->anything(),
				'2026-07-01',
				'linear',
				60,
				'Longer use'
			);

		$asset = $this->service()->revise(
			['id' => 'fa-1', 'revisionDate' => '2026-07-01T09:00:00Z', 'revisionReason' => ' Longer use ', 'revisedUsefulLifeMonths' => 0]
		);

		$this->assertSame('linear', $asset['depreciationMethod']);
		$this->assertSame(60, $asset['usefulLifeMonths']);
		$this->assertNull($asset['revisedMethod']);
		$this->assertNull($asset['revisedUsefulLifeMonths']);
		$this->assertArrayNotHasKey('currentBookValue', $asset);
	}//end testARevisionKeepsTheCurrentMethodAndLifeByDefault()

	/**
	 * A revised method and life are passed on and become the asset's own.
	 *
	 * @return void
	 */
	public function testARevisionAppliesTheRevisedMethodAndLife(): void {
		$this->schedules->method('lines')->willReturn([]);
		$this->schedules->expects($this->once())->method('revise')
			->with($this->anything(), '2026-07-01', 'declining', 36, 'Wear');

		$asset = $this->service()->revise(
			['id' => 'fa-1', 'revisionDate' => '2026-07-01', 'revisionReason' => 'Wear', 'revisedMethod' => 'declining', 'revisedUsefulLifeMonths' => '36']
		);

		$this->assertSame('declining', $asset['depreciationMethod']);
		$this->assertSame(36, $asset['usefulLifeMonths']);
	}//end testARevisionAppliesTheRevisedMethodAndLife()

	/**
	 * Extra depreciation without a date is refused.
	 *
	 * @return void
	 */
	public function testExtraDepreciationNeedsADate(): void {
		$this->schedules->expects($this->never())->method('extra');

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('Extra depreciation needs a date.');

		$this->service()->extra(['id' => 'fa-1', 'extraDepreciationAmount' => 500]);
	}//end testExtraDepreciationNeedsADate()

	/**
	 * Extra depreciation passes amount, date and reason on and clears the amount.
	 *
	 * @return void
	 */
	public function testExtraDepreciationIsPassedOn(): void {
		$this->schedules->method('lines')->willReturn([]);
		$this->schedules->expects($this->once())->method('extra')
			->with($this->anything(), 500.0, '2026-08-15', 'Damage');

		$asset = $this->service()->extra(
			['id' => 'fa-1', 'extraDepreciationAmount' => '500', 'extraDepreciationDate' => '2026-08-15 10:00', 'extraDepreciationReason' => 'Damage']
		);

		$this->assertNull($asset['extraDepreciationAmount']);
	}//end testExtraDepreciationIsPassedOn()

	/**
	 * The monthly figure is the regular line of the month; the book value is
	 * cost less every posted or past line, extra ones included.
	 *
	 * @return void
	 */
	public function testScheduleFiguresAreReadFromTheLines(): void {
		$this->schedules->method('lines')->with('fa-1')->willReturn(
			[
				['periodStartDate' => '2026-05-01', 'periodEndDate' => '2026-05-31', 'depreciationAmount' => 200, 'status' => 'planned'],
				['periodStartDate' => '2026-06-01', 'periodEndDate' => '2026-06-30', 'depreciationAmount' => 200.10, 'status' => DepreciationScheduleService::POSTED],
				['periodStartDate' => '2026-07-01', 'periodEndDate' => '2026-07-31', 'depreciationAmount' => 210, 'status' => 'planned'],
				['periodStartDate' => '2026-07-10', 'periodEndDate' => '2026-07-10', 'depreciationAmount' => 1000, 'rateType' => DepreciationScheduleService::EXTRA, 'status' => 'planned'],
				['periodStartDate' => '2026-08-01', 'periodEndDate' => '2026-08-31', 'depreciationAmount' => 210, 'status' => 'planned'],
			]
		);

		$asset = $this->service()->withScheduleFigures(['id' => 'fa-1'], '2026-07-15');

		$this->assertSame(210.0, $asset['monthlyDepreciation']);
		$this->assertSame(10599.9, $asset['currentBookValue']);
	}//end testScheduleFiguresAreReadFromTheLines()

	/**
	 * The service under test.
	 *
	 * @return FixedAssetDepreciation
	 */
	private function service(): FixedAssetDepreciation {
		return new FixedAssetDepreciation(schedules: $this->schedules);
	}//end service()
}//end class
