<?php

/**
 * The edges of the depreciation schedule: an asset that cannot be posted, a
 * revision that starts before the asset was acquired, and an asset or a plan
 * without a usable month.
 *
 * @category Tests
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

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Asset;

use DomainException;
use OCA\Shillinq\Service\Asset\AssetRecords;
use OCA\Shillinq\Service\Asset\DepreciationPlanner;
use OCA\Shillinq\Service\Asset\DepreciationScheduleService;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Refusals and boundaries of DepreciationScheduleService and DepreciationPlanner.
 *
 * @covers \OCA\Shillinq\Service\Asset\DepreciationScheduleService
 * @covers \OCA\Shillinq\Service\Asset\DepreciationPlanner
 * @uses   \OCA\Shillinq\Service\Asset\AssetRecords
 * @uses   \OCA\Shillinq\Service\Asset\DepreciationPlanRun
 * @uses   \OCA\Shillinq\Service\Asset\DepreciationPosting
 * @uses   \OCA\Shillinq\Service\Asset\ScheduleTerms
 * @uses   \OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
class DepreciationScheduleEdgesTest extends TestCase {

	private const KIOSK = '0a0e0000-0000-4000-8000-0000000000b1';

	private const BARE = '0a0e0000-0000-4000-8000-0000000000b2';

	private const UNDATED = '0a0e0000-0000-4000-8000-0000000000b3';

	/**
	 * The register.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The service under test.
	 *
	 * @var DepreciationScheduleService
	 */
	private DepreciationScheduleService $schedules;

	/**
	 * Three assets: a dated one with accounts, one without an expense account, and an undated draft.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->store = new InMemoryObjectServiceStub(
			[
				'FixedAsset'           => [
					[
						'id' => self::KIOSK,
						'assetNumber' => 'FA-1',
						'acquisitionDate' => '2026-01-10',
						'acquisitionCost' => 1200,
						'usefulLifeYears' => 1,
						'residualValue' => 0,
						'depreciationMethod' => 'linear',
						'accumulatedDepreciationAccountNumber' => '0309',
						'depreciationExpenseAccountNumber' => '4810',
						'status' => 'active',
						'administrationId' => 'adm-1',
					],
					[
						'id' => self::BARE,
						'assetNumber' => 'FA-2',
						'acquisitionDate' => '2026-01-10',
						'acquisitionCost' => 600,
						'usefulLifeMonths' => 6,
						'residualValue' => 0,
						'depreciationMethod' => 'linear',
						'accumulatedDepreciationAccountNumber' => '0309',
						'status' => 'active',
						'administrationId' => 'adm-1',
					],
					[
						'id' => self::UNDATED,
						'assetNumber' => 'FA-3',
						'acquisitionCost' => 600,
						'usefulLifeMonths' => 6,
						'residualValue' => 0,
						'depreciationMethod' => 'linear',
						'status' => 'draft',
						'administrationId' => 'adm-1',
					],
				],
				'DepreciationSchedule' => [],
				'JournalEntry'         => [],
			]
		);
		$engine = new LifecycleFaithfulTransitionEngine($this->store, ['FixedAsset', 'JournalEntry'], static function (): void {
		});
		$settings = $this->createMock(RegisterLocation::class);
		$settings->method('getRegisterSlug')->willReturn('shillinq');
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturn($engine);
		$records = new AssetRecords($this->store, new ObjectTransitionRunner(container: $container), $settings);
		$this->schedules = new DepreciationScheduleService($records, new DepreciationPlanner());

	}//end setUp()

	/**
	 * The asset record.
	 *
	 * @param string $id The asset id.
	 *
	 * @return array<string,mixed>
	 */
	private function asset(string $id): array {
		return $this->store->setSchema('FixedAsset')->find($id)->getObject();

	}//end asset()

	/**
	 * A month whose due assets include one without an expense account is
	 * refused, naming that asset, and no journal is written for it.
	 *
	 * @return void
	 */
	public function testAnAssetWithoutAnExpenseAccountIsNotPosted(): void {
		try {
			$this->schedules->postMissed($this->asset(self::BARE), '2026-03');
			$this->fail('An asset without an expense account was posted.');
		} catch (DomainException $e) {
			$this->assertSame('Asset FA-2 has no depreciation expense or accumulated depreciation account.', $e->getMessage());
		}

		$this->assertSame([], $this->store->setSchema('JournalEntry')->findAll());
		foreach ($this->schedules->lines(self::BARE) as $line) {
			$this->assertNotSame('posted', $line['status']);
		}

	}//end testAnAssetWithoutAnExpenseAccountIsNotPosted()

	/**
	 * A revision from a month before the acquisition counts those months as
	 * life still to come, rather than as life already used.
	 *
	 * @return void
	 */
	public function testARevisionBeforeTheAcquisitionAddsTheMonthsBetween(): void {
		$result = $this->schedules->revise($this->asset(self::KIOSK), '2025-11-15', 'linear', 12, 'earlier use');

		$this->assertSame('2025-11', $result['fromMonth']);
		$this->assertSame(14, $result['months']);
		$this->assertSame(1200.0, (float)$result['bookValue']);
		$this->assertCount(14, $this->schedules->lines(self::KIOSK));

	}//end testARevisionBeforeTheAcquisitionAddsTheMonthsBetween()

	/**
	 * An asset without an acquisition date gets no schedule of its own; a
	 * revision then plans its whole life from the revision month, because
	 * no month of it can have been used yet.
	 *
	 * @return void
	 */
	public function testAnAssetWithoutAnAcquisitionDatePlansItsWholeLifeOnRevision(): void {
		$this->assertSame([], $this->schedules->ensureSchedule($this->asset(self::UNDATED)));

		$result = $this->schedules->revise($this->asset(self::UNDATED), '2026-03-01', 'linear', 6, 'dated later');

		$this->assertSame('2026-03', $result['fromMonth']);
		$this->assertSame(6, $result['months']);
		$this->assertSame(100.0, (float)$result['monthly']);
		$this->assertCount(6, $this->schedules->lines(self::UNDATED));

	}//end testAnAssetWithoutAnAcquisitionDatePlansItsWholeLifeOnRevision()

	/**
	 * The planner refuses a first month that is not a month.
	 *
	 * @return void
	 */
	public function testThePlannerRefusesAFirstMonthThatIsNotAMonth(): void {
		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('soon is not a month.');

		(new DepreciationPlanner())->plan('soon', 1000, 0, 3, 'linear');

	}//end testThePlannerRefusesAFirstMonthThatIsNotAMonth()
}//end class
