<?php

/**
 * Unit tests for InnovatieboxAggregationService.
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
 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Service\CarryForwardLossService;
use OCA\Shillinq\Service\InnovatieboxAggregationService;
use OCA\Shillinq\Service\QualifyingAssetValidator;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The innovatiebox roll-up per qualifying asset: only currently valid assets
 * with an attribution for the year, an open loss recovered first at the full
 * tariff, and the grand totals.
 *
 * The access-ticket validator is mocked so no test depends on today's date.
 *
 * @covers \OCA\Shillinq\Service\InnovatieboxAggregationService
 * @uses   \OCA\Shillinq\Service\CarryForwardLossService
 */
class InnovatieboxAggregationServiceTest extends TestCase {

	/**
	 * Rows per schema the fake object service answers.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $rows = [];

	/**
	 * Every findAll() call, as [register, schema, config].
	 *
	 * @var array<int, array{0: string|int, 1: string|int, 2: array}>
	 */
	private array $queries = [];

	/**
	 * The register the app config answers.
	 *
	 * @var string
	 */
	private string $register = 'boekhouding';

	/**
	 * The validator mock.
	 *
	 * @var QualifyingAssetValidator&MockObject
	 */
	private QualifyingAssetValidator $validator;

	/**
	 * Build the validator before each test: every asset named "stale" is invalid.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->validator = $this->createMock(QualifyingAssetValidator::class);
		$this->validator->method('validateAccessTicket')->willReturnCallback(
			static fn (array $asset): array => ['valid' => (($asset['name'] ?? '') !== 'stale')]
		);
	}//end setUp()

	/**
	 * The service over the fake store.
	 *
	 * @return InnovatieboxAggregationService
	 */
	private function service(): InnovatieboxAggregationService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('shillinq', 'register', 'shillinq')->willReturnCallback(fn (): string => $this->register);

		$objects  = $this->createMock(ObjectServiceInterface::class);
		$register = '';
		$schema   = '';
		$objects->method('setRegister')->willReturnCallback(
			function (string|int $value) use ($objects, &$register): ObjectServiceInterface {
				$register = $value;
				return $objects;
			}
		);
		$objects->method('setSchema')->willReturnCallback(
			function (string|int $value) use ($objects, &$schema): ObjectServiceInterface {
				$schema = $value;
				return $objects;
			}
		);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use (&$register, &$schema): array {
				$this->queries[] = [$register, $schema, $config];
				return ($this->rows[$schema] ?? []);
			}
		);

		return new InnovatieboxAggregationService(
			appConfig: $appConfig,
			lossService: new CarryForwardLossService(),
			validator: $this->validator,
			objectService: $objects,
		);
	}//end service()

	/**
	 * The three reads go to the configured register with the administration, year and status filters.
	 *
	 * @return void
	 */
	public function testItReadsAssetsAttributionsAndOpenLossesOfTheAdministration(): void {
		$result = $this->service()->aggregate(administrationId: 'adm-1', financialYear: 2025);

		$this->assertSame(['data' => [], 'total' => 0, 'totals' => ['vpb_regel_23' => 0.0, 'benefit_innovation_box' => 0.0]], $result);
		$this->assertSame(
			[
				['boekhouding', 'QualifyingAsset', ['filters' => ['administrationId' => 'adm-1', 'status' => 'valid']]],
				['boekhouding', 'IBProfitAttribution', ['filters' => ['administrationId' => 'adm-1', 'financialYear' => 2025]]],
				['boekhouding', 'CarryForwardLoss', ['filters' => ['administrationId' => 'adm-1', 'status' => 'open']]],
			],
			$this->queries
		);
	}//end testItReadsAssetsAttributionsAndOpenLossesOfTheAdministration()

	/**
	 * An empty register setting falls back to the shillinq register.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterFallsBackToShillinq(): void {
		$this->register = '';

		$this->service()->aggregate(administrationId: 'adm-1', financialYear: 2025);

		$this->assertSame(['shillinq', 'shillinq', 'shillinq'], array_column($this->queries, 0));
	}//end testAnEmptyRegisterFallsBackToShillinq()

	/**
	 * A row per valid asset with an attribution, rounded, with the stored figures.
	 *
	 * @return void
	 */
	public function testAValidAttributedAssetBecomesARow(): void {
		$this->rows['QualifyingAsset']     = [['id' => 'a1', 'name' => 'Octrooi X']];
		$this->rows['IBProfitAttribution'] = [[
			'qualifying_asset_id'           => 'a1',
			'method'                        => 'residual',
			'qualifying_profit_for_nexus'   => 1000.006,
			'nexus_fraction_applied'        => 0.123456,
			'qualifying_profit_after_nexus' => 123.4567,
			'effective_rate'                => 0.09,
			'vpb_on_innovation_share'       => 11.111,
			'benefit_innovation_box'        => 20.004,
		]];

		$result = $this->service()->aggregate(administrationId: 'adm-1', financialYear: 2025);

		$this->assertSame(
			[[
				'qualifying_asset_id'           => 'a1',
				'name'                          => 'Octrooi X',
				'method'                        => 'residual',
				'qualifying_profit_for_nexus'   => 1000.01,
				'nexus_fraction_applied'        => 0.1235,
				'qualifying_profit_after_nexus' => 123.46,
				'effective_rate'                => 0.09,
				'vpb_on_innovation_share'       => 11.11,
				'benefit_innovation_box'        => 20.0,
				'loss_carry_forward'            => null,
			]],
			$result['data']
		);
		$this->assertSame(1, $result['total']);
		$this->assertSame(['vpb_regel_23' => 11.11, 'benefit_innovation_box' => 20.0], $result['totals']);
	}//end testAValidAttributedAssetBecomesARow()

	/**
	 * Missing figures default: full nexus, the innovatiebox tariff, VPB computed from the profit after nexus.
	 *
	 * @return void
	 */
	public function testMissingFiguresTakeTheirDefaults(): void {
		$this->rows['QualifyingAsset']     = [['@self' => ['id' => 'a2']]];
		$this->rows['IBProfitAttribution'] = [['qualifying_asset_id' => 'a2', 'qualifying_profit_after_nexus' => 1000]];

		$row = $this->service()->aggregate(administrationId: 'adm-1', financialYear: 2025)['data'][0];

		$this->assertSame('a2', $row['qualifying_asset_id']);
		$this->assertSame('', $row['name']);
		$this->assertSame('', $row['method']);
		$this->assertSame(1.0, $row['nexus_fraction_applied']);
		$this->assertSame(CarryForwardLossService::INNOVATIEBOX_TARIFF, $row['effective_rate']);
		$this->assertSame(90.0, $row['vpb_on_innovation_share']);
		$this->assertSame(0.0, $row['benefit_innovation_box']);
	}//end testMissingFiguresTakeTheirDefaults()

	/**
	 * Stale, unidentified, non-array and unattributed assets are left out; the first attribution per asset wins.
	 *
	 * @return void
	 */
	public function testOnlyCurrentlyValidAttributedAssetsCount(): void {
		$this->rows['QualifyingAsset'] = [
			['id' => 'stale-1', 'name' => 'stale'],
			['name' => 'zonder id'],
			'not-an-array',
			['id' => 'no-attribution'],
			['@self' => ['slug' => 'by-slug'], 'name' => 'Slug'],
		];
		$this->rows['IBProfitAttribution'] = [
			['qualifying_asset_id' => 'stale-1', 'benefit_innovation_box' => 99],
			['qualifying_asset_id' => '', 'benefit_innovation_box' => 98],
			['qualifying_asset_id' => 'by-slug', 'benefit_innovation_box' => 5, 'vpb_on_innovation_share' => 1],
			['qualifying_asset_id' => 'by-slug', 'benefit_innovation_box' => 7, 'vpb_on_innovation_share' => 2],
		];

		$result = $this->service()->aggregate(administrationId: 'adm-1', financialYear: 2025);

		$this->assertSame(['by-slug'], array_column($result['data'], 'qualifying_asset_id'));
		$this->assertSame(['vpb_regel_23' => 1.0, 'benefit_innovation_box' => 5.0], $result['totals']);
	}//end testOnlyCurrentlyValidAttributedAssetsCount()

	/**
	 * An open loss is recovered first at the full tariff, and the row shows the offset.
	 *
	 * @return void
	 */
	public function testAnOpenLossIsRecoveredFirst(): void {
		$this->rows['QualifyingAsset']     = [['id' => 'a1', 'name' => 'Octrooi X']];
		$this->rows['IBProfitAttribution'] = [[
			'qualifying_asset_id'           => 'a1',
			'qualifying_profit_after_nexus' => 1000,
			'nexus_fraction_applied'        => 0.5,
			'vpb_on_innovation_share'       => 45,
			'benefit_innovation_box'        => 100,
		]];
		$this->rows['CarryForwardLoss']    = [['qualifying_asset_id' => 'a1', 'balance_open' => 400]];
		$expected = (new CarryForwardLossService())->offsetLossAgainstProfit(openLoss: 400.0, currentYearProfit: 1000.0, nexusBreak: 0.5);

		$row = $this->service()->aggregate(administrationId: 'adm-1', financialYear: 2025)['data'][0];

		$this->assertSame(
			[
				'loss_offset'   => $expected['lossOffset'],
				'benefit_full'  => $expected['lossOffsetAtFullRate'],
				'residual'      => $expected['residualProfit'],
				'benefit_9pct'  => $expected['residualProfitAt9Pct'],
				'balance_after' => $expected['balanceAfter'],
			],
			$row['loss_carry_forward']
		);
		$this->assertSame(400.0, $row['loss_carry_forward']['loss_offset']);
		$this->assertSame(round($expected['residualProfitAt9Pct'], 2), $row['vpb_on_innovation_share']);
		$this->assertSame(round($expected['totalBenefit'], 2), $row['benefit_innovation_box']);
	}//end testAnOpenLossIsRecoveredFirst()

	/**
	 * A loss record without an open balance changes nothing.
	 *
	 * @return void
	 */
	public function testALossWithoutOpenBalanceIsIgnored(): void {
		$this->rows['QualifyingAsset']     = [['id' => 'a1']];
		$this->rows['IBProfitAttribution'] = [['qualifying_asset_id' => 'a1', 'qualifying_profit_after_nexus' => 1000, 'benefit_innovation_box' => 100]];
		$this->rows['CarryForwardLoss']    = [['qualifying_asset_id' => 'a1', 'balance_open' => 0]];

		$row = $this->service()->aggregate(administrationId: 'adm-1', financialYear: 2025)['data'][0];

		$this->assertNull($row['loss_carry_forward']);
		$this->assertSame(100.0, $row['benefit_innovation_box']);
	}//end testALossWithoutOpenBalanceIsIgnored()

	/**
	 * The totals add every row's VPB and benefit, rounded once.
	 *
	 * @return void
	 */
	public function testTheTotalsAddEveryRow(): void {
		$this->rows['QualifyingAsset']     = [['id' => 'a1'], ['id' => 'a2']];
		$this->rows['IBProfitAttribution'] = [
			['qualifying_asset_id' => 'a1', 'vpb_on_innovation_share' => 10.25, 'benefit_innovation_box' => 1.5],
			['qualifying_asset_id' => 'a2', 'vpb_on_innovation_share' => 4.5, 'benefit_innovation_box' => 2.25],
		];

		$result = $this->service()->aggregate(administrationId: 'adm-1', financialYear: 2025);

		$this->assertSame(2, $result['total']);
		$this->assertSame(['vpb_regel_23' => 14.75, 'benefit_innovation_box' => 3.75], $result['totals']);
	}//end testTheTotalsAddEveryRow()
}//end class
