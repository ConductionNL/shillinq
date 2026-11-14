<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PublicSector;

use DomainException;
use OCA\Shillinq\Lifecycle\Action\CalculateIntegralCostPriceAction;
use OCA\Shillinq\Service\IntegralCostPriceCalculator;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PublicSector\ActivityCostPriceCalculation;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\PublicSector\TaskFieldRealisation;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Bank\LifecycleFaithfulTransitionEngine;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * REQ-WMO-013: the integral cost price of a commercial activity calculated from
 * the books through the real calculator, over the real CommercialActivity
 * `calculate` transition, and stored as an IntegralCostPrice that validates
 * against the merged register schema.
 *
 * @covers \OCA\Shillinq\Service\PublicSector\ActivityCostPriceCalculation
 * @covers \OCA\Shillinq\Lifecycle\Action\CalculateIntegralCostPriceAction
 * @covers \OCA\Shillinq\Service\PublicSector\PublicSectorRecords
 * @uses   \OCA\Shillinq\Service\IntegralCostPriceCalculator
 * @uses   \OCA\Shillinq\Service\ComposedCostCents
 * @uses   \OCA\Shillinq\Service\UnitCostPricing
 * @uses   \OCA\Shillinq\Service\PublicSector\TaskFieldRealisation
 * @uses   \OCA\Shillinq\Service\LedgerActuals
 * @uses   \OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class ActivityCostPriceCalculationTest extends TestCase {

	private const ACTIVITY = '88888888-0001-4000-8000-000000000001';

	private InMemoryObjectServiceStub $store;

	private LifecycleFaithfulTransitionEngine $engine;

	/**
	 * Seed the sports hall rental: 2026 lines on its cost centre and cost
	 * object for payroll, materials and depreciation, its revenue, EUR
	 * 1,000,000 of overhead on task field 0.4, and lines that must not count.
	 *
	 * @param array<string,mixed> $activity Fields of the activity changed.
	 *
	 * @return void
	 */
	private function seed(array $activity = []): void {
		$line = static fn (string $tx, string $account, string $side, float $amount, string $kp = 'KP-SPORT', string $kd = 'KD-ZAAL'): array => ['transactionId' => $tx, 'accountNumber' => $account, 'side' => $side, 'amount' => $amount, 'costCenterCode' => $kp, 'costCarrierCode' => $kd];
		$this->store = new InMemoryObjectServiceStub(
			[
				'CommercialActivity' => [
					array_merge(
						[
							'id' => self::ACTIVITY, 'code' => 'SPORT-01', 'name' => 'Sporthal zaalverhuur', 'governingBody' => 'gemeente', 'organisationalUnit' => 'Sport', 'description' => 'Verhuur van de sporthal aan verenigingen en bedrijven.', 'marketSegment' => 'Zaalverhuur', 'concurrenten' => ['Sportcentrum B.V.'], 'startDate' => '2020-01-01', 'costPriceMethod' => 'integral-costprice-art-25i', 'costCentreCode' => 'KP-SPORT', 'costObjectCode' => 'KD-ZAAL', 'isExempted' => false, 'administrationId' => 'adm-gov-1', 'state' => 'active',
							'overheadKey' => [['bucket' => 'huisvesting', 'ratio' => 0.02], ['bucket' => 'ict', 'ratio' => 0.01]],
							'investedBookValue' => 500000, 'waccRate' => 0.045, 'profitMarkupRate' => 0.03, 'soldUnits' => 312, 'unitLabel' => 'dagdeel', 'appliedRate' => 280,
						],
						$activity
					),
				],
				'Account'            => [
					['accountNumber' => '4000', 'administrationId' => 'adm-gov-1', 'rgsCode' => 'WPerLes'],
					['accountNumber' => '4100', 'administrationId' => 'adm-gov-1', 'rgsCode' => 'WBedHui'],
					['accountNumber' => '4200', 'administrationId' => 'adm-gov-1', 'rgsCode' => 'WAfsMat'],
					['accountNumber' => '4400', 'administrationId' => 'adm-gov-1', 'rgsCode' => 'WBedAlk'],
					['accountNumber' => '8000', 'administrationId' => 'adm-gov-1', 'rgsCode' => 'WOmzNop'],
					['accountNumber' => '1100', 'administrationId' => 'adm-gov-1', 'rgsCode' => 'BLimBan'],
				],
				'BbvAccountMapping'  => [
					['administrationId' => 'adm-gov-1', 'accountNumber' => '4400', 'taskField' => '0.4'],
					['administrationId' => 'adm-gov-1', 'accountNumber' => '4000', 'taskField' => '5.2'],
				],
				'GLTransaction'      => [
					['id' => 'tx-1', 'administrationId' => 'adm-gov-1', 'state' => 'posted', 'postingDate' => '2026-03-01'],
					['id' => 'tx-2', 'administrationId' => 'adm-gov-1', 'state' => 'posted', 'postingDate' => '2026-12-31'],
					['id' => 'tx-2025', 'administrationId' => 'adm-gov-1', 'state' => 'posted', 'postingDate' => '2025-12-31'],
				],
				'GLLine'             => [
					$line('tx-1', '4000', 'debit', 40000.00),
					$line('tx-1', '4100', 'debit', 10500.00),
					$line('tx-1', '4100', 'credit', 500.00),
					$line('tx-1', '4200', 'debit', 12500.00),
					$line('tx-1', '1100', 'credit', 62500.00),
					$line('tx-2', '8000', 'credit', 80000.00),
					$line('tx-2', '1100', 'debit', 80000.00),
					$line('tx-2', '4400', 'debit', 1000000.00, '', ''),
					$line('tx-2', '4100', 'debit', 5000.00, 'KP-OTHER'),
					$line('tx-2025', '4000', 'debit', 99999.00),
				],
				'IntegralCostPrice'  => [],
			]
		);

		$action = new CalculateIntegralCostPriceAction($this->calculation());
		$store = $this->store;
		$this->engine = new LifecycleFaithfulTransitionEngine(
			$this->store,
			['CommercialActivity'],
			static function (array $record, array $object) use ($action, $store): void {
				$declared = RegisterSchema::schema($record['schema'])['x-openregister-lifecycle']['transitions'][$record['action']];
				foreach (($declared['actions'] ?? []) as $step) {
					if ($step['action'] === CalculateIntegralCostPriceAction::class) {
						$object = $action->execute($object, [], ($step['actionParameters'] ?? []), $step['action']);
						$store->setSchema($record['schema'])->saveObject($object);
					}
				}
			}
		);

	}//end seed()

	private function calculation(): ActivityCostPriceCalculation {
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$records = new PublicSectorRecords($this->store, new ObjectTransitionRunner(container: $this->container()), $location);
		return new ActivityCostPriceCalculation($records, new TaskFieldRealisation($records), new IntegralCostPriceCalculator());

	}//end calculation()

	private function container(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('has')->willReturn(true);
		$container->method('get')->willReturnCallback(fn (): object => $this->engine);
		return $container;

	}//end container()

	/**
	 * Calculate for a year through the declared transition, the year set as its input.
	 */
	private function calculateFor(int $year): array {
		$activity = $this->activity();
		$activity['costPriceYear'] = $year;
		$this->store->setSchema('CommercialActivity')->saveObject($activity);
		(new ObjectTransitionRunner(container: $this->container()))->run(objectId: self::ACTIVITY, action: 'calculate');
		return $this->activity();

	}//end calculateFor()

	private function activity(): array {
		return $this->store->setSchema('CommercialActivity')->find(self::ACTIVITY)->getObject();

	}//end activity()

	/**
	 * @return list<array<string,mixed>>
	 */
	private function costPrices(): array {
		return $this->store->setSchema('IntegralCostPrice')->findAll();

	}//end costPrices()

	/**
	 * The register declares the transition, its year input and its executor.
	 */
	public function testTheCalculateTransitionIsDeclared(): void {
		$transition = RegisterSchema::schema('CommercialActivity')['x-openregister-lifecycle']['transitions']['calculate'];

		$this->assertSame('active', $transition['from']);
		$this->assertSame('active', $transition['to']);
		$this->assertSame([['field' => 'costPriceYear', 'required' => true]], $transition['inputs']);
		$this->assertSame(CalculateIntegralCostPriceAction::class, $transition['actions'][0]['action']);
		$this->assertArrayHasKey('pause', RegisterSchema::schema('CommercialActivity')['x-openregister-lifecycle']['transitions'], 'the existing transitions stay');

	}//end testTheCalculateTransitionIsDeclared()

	/**
	 * Scenario: the controller calculates 2026 for the sports hall rental; the
	 * cost price is stored with direct costs, overhead, cost of capital and
	 * markup, and says the applied rate does not cover it.
	 */
	public function testTheCostPriceIsCalculatedFromTheBooks(): void {
		$this->seed();

		$activity = $this->calculateFor(2026);

		$prices = $this->costPrices();
		$this->assertCount(1, $prices);
		$price = $prices[0];
		$this->assertSame($price['id'], $activity['integralCostPriceId']);
		$this->assertSame('active', $activity['state']);
		$this->assertSame('SPORT-01', $price['commercialActivityId'], 'named by its code, as the activity page filters');
		$this->assertSame('2026-YTD', $price['period']);
		$this->assertSame('voorlopig', $price['status']);
		$this->assertSame('adm-gov-1', $price['administrationId']);
		$this->assertEquals(
			[
				'directPayrollCost'   => 40000.0,
				'directMaterials'     => 10000.0,
				'directDepreciations' => 12500.0,
				'indirecteOverhead'   => ['huisvesting' => 20000.0, 'ict' => 10000.0],
				'capitalCost'         => 22500.0,
				'profitMarkup'        => 3450.0,
			],
			$price['componenten']
		);
		$this->assertEquals(118450.0, $price['totalCost']);
		$this->assertSame(379.6474, $price['costPricePerUnit']);
		$this->assertEquals(280.0, $price['appliedRate']);
		$this->assertFalse($price['compliant'], 'EUR 280 per unit does not cover EUR 379.65');
		$this->assertSame([], RegisterSchema::errors('IntegralCostPrice', array_diff_key($price, ['id' => true])));
		$this->assertSame([], RegisterSchema::errors('CommercialActivity', array_diff_key($activity, ['id' => true, 'integralCostPriceId' => true])));

	}//end testTheCostPriceIsCalculatedFromTheBooks()

	/**
	 * Calculating the year again replaces the provisional record; a rate that
	 * covers the cost price is compliant.
	 */
	public function testRecalculatingReplacesTheProvisionalRecord(): void {
		$this->seed();
		$first = $this->calculateFor(2026)['integralCostPriceId'];

		$activity = $this->activity();
		$activity['appliedRate'] = 400;
		$this->store->setSchema('CommercialActivity')->saveObject($activity);
		$second = $this->calculateFor(2026)['integralCostPriceId'];

		$this->assertSame($first, $second);
		$this->assertCount(1, $this->costPrices());
		$this->assertTrue($this->costPrices()[0]['compliant']);

	}//end testRecalculatingReplacesTheProvisionalRecord()

	/**
	 * The monitor method charges no markup; without units or a rate the
	 * revenue decides compliance.
	 */
	public function testTheMonitorMethodChargesNoMarkupAndRevenueDecides(): void {
		$this->seed(['costPriceMethod' => 'costprice-monitor-without-profit-markup', 'soldUnits' => null, 'appliedRate' => null, 'unitLabel' => null, 'waccRate' => null, 'profitMarkupRate' => 0.5]);

		$this->calculateFor(2026);

		$price = $this->costPrices()[0];
		$this->assertEquals(0.0, $price['componenten']['profitMarkup']);
		$this->assertEquals(22500.0, $price['componenten']['capitalCost'], 'the default cost of capital rate is 4.5 percent');
		$this->assertEquals(115000.0, $price['totalCost']);
		$this->assertArrayNotHasKey('costPricePerUnit', $price);
		$this->assertArrayNotHasKey('appliedRate', $price);
		$this->assertFalse($price['compliant'], 'EUR 80,000 of revenue does not cover EUR 115,000');
		$this->assertSame([], RegisterSchema::errors('IntegralCostPrice', array_diff_key($price, ['id' => true])));

	}//end testTheMonitorMethodChargesNoMarkupAndRevenueDecides()

	/**
	 * A year without postings costs only the capital and its markup.
	 */
	public function testAYearWithoutPostingsCostsOnlyTheCapital(): void {
		$this->seed();

		$this->calculateFor(2030);

		$price = $this->costPrices()[0];
		$this->assertSame('2030-YTD', $price['period']);
		$this->assertEquals(0.0, $price['componenten']['directPayrollCost']);
		$this->assertEquals(['huisvesting' => 0.0, 'ict' => 0.0], $price['componenten']['indirecteOverhead']);
		$this->assertEquals(23175.0, $price['totalCost']);

	}//end testAYearWithoutPostingsCostsOnlyTheCapital()

	/**
	 * A final cost price is not calculated again.
	 */
	public function testAFinalCostPriceIsNotRecalculated(): void {
		$this->seed();
		$this->store->setSchema('IntegralCostPrice')->saveObject(['id' => 'ikp-final', 'commercialActivityId' => 'SPORT-01', 'period' => '2026-YTD', 'status' => 'final']);
		$activity = $this->activity();
		$activity['costPriceYear'] = 2026;

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage('The cost price of 2026 is final and cannot be calculated again.');
		$this->calculation()->calculate($activity);

	}//end testAFinalCostPriceIsNotRecalculated()

	/**
	 * An activity without a year, a cost centre or a cost object is refused.
	 *
	 * @dataProvider incompleteActivities
	 *
	 * @param array<string,mixed> $changes The activity fields changed.
	 * @param string              $message The refusal.
	 */
	public function testAnIncompleteActivityIsRefused(array $changes, string $message): void {
		$this->seed();
		$activity = array_merge($this->activity(), ['costPriceYear' => 2026], $changes);

		$this->expectException(DomainException::class);
		$this->expectExceptionMessage($message);
		$this->calculation()->calculate($activity);

	}//end testAnIncompleteActivityIsRefused()

	/**
	 * @return array<string, array{0: array<string,mixed>, 1: string}>
	 */
	public static function incompleteActivities(): array {
		$where = 'Enter the administration, the cost centre and the cost object of the activity before calculating.';
		return [
			'no year'           => [['costPriceYear' => null], 'Enter the year to calculate the cost price for.'],
			'no cost centre'    => [['costCentreCode' => ' '], $where],
			'no cost object'    => [['costObjectCode' => ''], $where],
			'no administration' => [['administrationId' => ''], $where],
		];

	}//end incompleteActivities()
}//end class
