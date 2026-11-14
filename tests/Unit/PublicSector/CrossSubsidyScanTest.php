<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\PublicSector;

use OCA\Shillinq\Service\CrossSubsidyDetector;
use OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner;
use OCA\Shillinq\Service\PublicSector\CrossSubsidyScan;
use OCA\Shillinq\Service\PublicSector\PublicSectorRecords;
use OCA\Shillinq\Service\RegisterLocation;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Tests\Unit\Service\Support\RegisterSchema;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * REQ-WMO-014: the daily cross-subsidy check over the active commercial
 * activities with the real detector, one AlertLog per signal that validates
 * against the merged register schema, and no repeat while an alert is open.
 *
 * @covers \OCA\Shillinq\Service\PublicSector\CrossSubsidyScan
 * @uses   \OCA\Shillinq\Service\CrossSubsidyDetector
 * @uses   \OCA\Shillinq\Service\PublicSector\PublicSectorRecords
 * @uses   \OCA\Shillinq\Service\Lifecycle\ObjectTransitionRunner
 * @uses   \OCA\Shillinq\Util\ObjectIdentifier
 */
final class CrossSubsidyScanTest extends TestCase {

	private const TODAY = '2026-11-14';

	private InMemoryObjectServiceStub $store;

	/**
	 * An activity record.
	 *
	 * @param string              $code    Its code.
	 * @param array<string,mixed> $changes Fields changed.
	 *
	 * @return array<string,mixed>
	 */
	private static function activity(string $code, array $changes = []): array {
		return array_merge(['id' => 'ca-' . strtolower($code), 'code' => $code, 'name' => $code, 'administrationId' => 'adm-gov-1', 'state' => 'active', 'isExempted' => false], $changes);

	}//end activity()

	/**
	 * A monthly cost price.
	 *
	 * @param string $code    The activity code.
	 * @param string $period  The period.
	 * @param float  $marge   The margin.
	 * @param array<string,mixed> $changes Fields changed.
	 *
	 * @return array<string,mixed>
	 */
	private static function price(string $code, string $period, float $marge, array $changes = []): array {
		return array_merge(
			[
				'commercialActivityId' => $code, 'period' => $period, 'status' => 'voorlopig', 'administrationId' => 'adm-gov-1', 'marge' => $marge, 'totalCost' => 10000.0,
				'componenten' => ['directPayrollCost' => 4000.0, 'directMaterials' => 2000.0, 'directDepreciations' => 1000.0, 'indirecteOverhead' => ['huisvesting' => 2500.0], 'capitalCost' => 300.0, 'profitMarkup' => 200.0],
			],
			$changes
		);

	}//end price()

	/**
	 * The scan over these rows.
	 *
	 * @param array<string, list<array<string,mixed>>> $rows Rows per schema.
	 *
	 * @return CrossSubsidyScan
	 */
	private function scan(array $rows): CrossSubsidyScan {
		$this->store = new InMemoryObjectServiceStub(array_merge(['CommercialActivity' => [], 'IntegralCostPrice' => [], 'ActivityCostAllocation' => [], 'AlgemeenBelangBesluit' => [], 'MarketBenchmark' => [], 'AlertLog' => []], $rows));
		$location = $this->createMock(RegisterLocation::class);
		$location->method('getRegisterSlug')->willReturn('shillinq');
		$records = new PublicSectorRecords($this->store, new ObjectTransitionRunner(container: $this->createMock(ContainerInterface::class)), $location);
		return new CrossSubsidyScan($records, new CrossSubsidyDetector());

	}//end scan()

	/**
	 * @return list<array<string,mixed>>
	 */
	private function alerts(): array {
		return $this->store->setSchema('AlertLog')->findAll();

	}//end alerts()

	/**
	 * Scenario: an activity whose cost prices show a loss for twelve months in a
	 * row, checked twice, has one open loss financing alert.
	 */
	public function testALossForAYearLogsOneAlertOverTwoRuns(): void {
		$prices = [];
		for ($month = 1; $month <= 12; $month++) {
			$prices[] = self::price('SPORT-01', sprintf('2026-%02d', $month), -150.0);
		}

		$scan = $this->scan(['CommercialActivity' => [self::activity('SPORT-01')], 'IntegralCostPrice' => $prices]);

		$this->assertSame(['activities' => 1, 'alerts' => 1, 'alreadyOpen' => 0], $scan->run(self::TODAY));
		$this->assertSame(['activities' => 1, 'alerts' => 0, 'alreadyOpen' => 1], $scan->run(self::TODAY));

		$alerts = $this->alerts();
		$this->assertCount(1, $alerts);
		$alert = $alerts[0];
		$this->assertSame('loss-financing', $alert['alertType']);
		$this->assertSame('SPORT-01', $alert['commercialActivityId']);
		$this->assertSame('HIGH', $alert['severity']);
		$this->assertSame('open', $alert['status']);
		$this->assertSame('concerncontroller', $alert['assignedTo']);
		$this->assertSame('adm-gov-1', $alert['administrationId']);
		$this->assertSame('2026-12', $alert['detectionContext']['periods'][0], 'the most recent period first');
		$this->assertSame([], RegisterSchema::errors('AlertLog', array_diff_key($alert, ['id' => true])));

	}//end testALossForAYearLogsOneAlertOverTwoRuns()

	/**
	 * A resolved alert does not hold back a new one; an escalated one does.
	 */
	public function testOnlyAnUnresolvedAlertHoldsBackANewOne(): void {
		$prices = [self::price('A-1', '2026-02', -1.0), self::price('A-1', '2026-01', -1.0), self::price('B-1', '2026-02', -1.0), self::price('B-1', '2026-01', -1.0)];
		$scan = $this->scan(
			[
				'CommercialActivity' => [self::activity('A-1'), self::activity('B-1')],
				'IntegralCostPrice'  => $prices,
				'AlertLog'           => [
					['alertType' => 'loss-financing', 'commercialActivityId' => 'A-1', 'status' => 'remediated'],
					['alertType' => 'loss-financing', 'commercialActivityId' => 'B-1', 'status' => 'escalated'],
				],
			]
		);

		$this->assertSame(['activities' => 2, 'alerts' => 1, 'alreadyOpen' => 1], $scan->run(self::TODAY));
		$new = array_values(array_filter($this->alerts(), static fn (array $a): bool => ($a['status'] ?? '') === 'open'));
		$this->assertSame(['A-1'], array_column($new, 'commercialActivityId'));

	}//end testOnlyAnUnresolvedAlertHoldsBackANewOne()

	/**
	 * A paused or closed activity, and one with a profit, is not alerted.
	 */
	public function testOnlyActiveActivitiesWithASignalAreAlerted(): void {
		$scan = $this->scan(
			[
				'CommercialActivity' => [self::activity('PAUSED', ['state' => 'paused']), self::activity('CLOSED', ['state' => 'closed']), self::activity('FINE')],
				'IntegralCostPrice'  => [
					self::price('PAUSED', '2026-02', -1.0), self::price('PAUSED', '2026-01', -1.0),
					self::price('FINE', '2026-02', 10.0), self::price('FINE', '2026-01', -1.0),
				],
			]
		);

		$this->assertSame(['activities' => 1, 'alerts' => 0, 'alreadyOpen' => 0], $scan->run(self::TODAY));
		$this->assertSame([], $this->alerts());

	}//end testOnlyActiveActivitiesWithASignalAreAlerted()

	/**
	 * Each other check fires on its own data: overhead under the floor,
	 * direct costs that grew while overhead did not, a stale exemption, too many
	 * manual allocations, and a rate far below the market.
	 */
	public function testTheOtherChecksEachLogTheirAlert(): void {
		$latest = self::price('MIX-1', '2026-YTD', 50.0, ['totalCost' => 100000.0, 'appliedRate' => 60.0, 'costPricePerUnit' => 55.0, 'componenten' => ['directPayrollCost' => 50000.0, 'directMaterials' => 20000.0, 'directDepreciations' => 10000.0, 'indirecteOverhead' => ['ict' => 500.0], 'capitalCost' => 0.0, 'profitMarkup' => 0.0]]);
		$prior = self::price('MIX-1', '2025-YTD', 50.0, ['componenten' => ['directPayrollCost' => 30000.0, 'directMaterials' => 10000.0, 'directDepreciations' => 10000.0, 'indirecteOverhead' => ['ict' => 500.0]]]);
		$allocation = static fn (bool $automatic): array => ['commercialActivityId' => 'MIX-1', 'automaticApplied' => $automatic];
		$scan = $this->scan(
			[
				'CommercialActivity'     => [self::activity('MIX-1', ['isExempted' => true, 'exemptionDecisionId' => 'abb-1'])],
				'IntegralCostPrice'      => [$prior, $latest],
				'AlgemeenBelangBesluit'  => [['id' => 'abb-1', 'nextEvaluation' => '2023-06-01']],
				'ActivityCostAllocation' => [$allocation(false), $allocation(true), $allocation(true), $allocation(true)],
				'MarketBenchmark'        => [
					['commercialActivityId' => 'MIX-1', 'referenceDate' => '2026-03-01', 'amount' => 100.0],
					['commercialActivityId' => 'MIX-1', 'referenceDate' => '2026-09-01', 'amount' => 90.0],
					['commercialActivityId' => 'MIX-1', 'referenceDate' => '2025-01-01', 'amount' => 1.0],
				],
			]
		);

		$scan->run(self::TODAY);

		$alerts = $this->alerts();
		$byType = array_column($alerts, 'severity', 'alertType');
		ksort($byType);
		$this->assertSame(
			[
				'abb-stale'                          => 'MEDIUM',
				'bevoordeling-risk'                  => 'HIGH',
				'manual-override-accumulation'       => 'LOW',
				'overhead-under-allocation'          => 'MEDIUM',
				'potentiele-overhead-onderschatting' => 'MEDIUM',
			],
			$byType
		);
		$bevoordeling = array_values(array_filter($alerts, static fn (array $a): bool => $a['alertType'] === 'bevoordeling-risk'))[0];
		$this->assertSame(2, $bevoordeling['detectionContext']['benchmarks'], 'only the benchmarks of the last twelve months');
		foreach ($alerts as $alert) {
			$this->assertSame([], RegisterSchema::errors('AlertLog', array_diff_key($alert, ['id' => true])));
		}

	}//end testTheOtherChecksEachLogTheirAlert()
}//end class
