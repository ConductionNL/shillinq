<?php

/**
 * Unit tests for Pillar2SafeHarbourService: the transitional CbCR safe
 * harbour tests, the draft computations and the Dutch QDMTT.
 *
 * @category Test
 * @package  OCA\Shillinq\Tests\Unit\Service\Cbcr
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tax-cbcr-and-pillar2-returns/specs/bookkeeping-cbcr-pillar2/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters

namespace OCA\Shillinq\Tests\Unit\Service\Cbcr;

use InvalidArgumentException;
use OCA\Shillinq\Service\Cbcr\Pillar2SafeHarbourService;
use OCA\Shillinq\Tests\Unit\Service\Support\InMemoryObjectServiceStub;
use OCA\Shillinq\Util\ObjectIdentifier;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * The safe harbour service over an in-memory register.
 */
#[CoversClass(Pillar2SafeHarbourService::class)]
#[UsesClass(ObjectIdentifier::class)]
final class Pillar2SafeHarbourServiceTest extends TestCase {
	/**
	 * The in-memory register.
	 *
	 * @var InMemoryObjectServiceStub
	 */
	private InMemoryObjectServiceStub $store;

	/**
	 * The service over a group with the given summaries and assessments.
	 *
	 * @param array<int,array<string,mixed>> $summaries    CbcrJurisdictionSummary rows.
	 * @param array<int,array<string,mixed>> $assessments  GroupScopeAssessment rows.
	 * @param array<int,array<string,mixed>> $computations Pillar2JurisdictionComputation rows.
	 *
	 * @return Pillar2SafeHarbourService
	 */
	private function service(array $summaries, array $assessments, array $computations = []): Pillar2SafeHarbourService {
		$this->store = new InMemoryObjectServiceStub(
			[
				'GroupEntityRegistry' => [
					['id' => 'ge-holding', 'entityName' => 'Voorbeeld Groep Holding B.V.', 'taxResidency' => 'NL'],
					['id' => 'ge-productie', 'entityName' => 'Voorbeeld Productie B.V.', 'taxResidency' => 'NL', 'ultimateParentEntity' => 'ge-holding'],
				],
				'GroupScopeAssessment' => $assessments,
				'CbcrJurisdictionSummary' => $summaries,
				'Pillar2JurisdictionComputation' => $computations,
				'Pillar2SafeHarbour' => [],
				'QdmttReturn' => [],
			]
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);

		return new Pillar2SafeHarbourService($this->store, $appConfig);
	}//end service()

	/**
	 * A minimum tax assessment of a calendar year.
	 *
	 * @param int         $year     The year.
	 * @param string|null $deadline The top-up tax return deadline.
	 *
	 * @return array<string,mixed>
	 */
	private static function assessment(int $year, ?string $deadline = null): array {
		return [
			'id' => 'gsa-' . $year, 'ultimateParentEntity' => 'ge-holding', 'fiscalYear' => $year, 'pillar2InScope' => true,
			'fiscalYearStart' => $year . '-01-01', 'fiscalYearEnd' => $year . '-12-31', 'bijheffingReturnDeadline' => $deadline,
		];
	}//end assessment()

	/**
	 * A 2026 summary.
	 *
	 * @param string              $code    The jurisdiction.
	 * @param array<string,float> $figures Its figures.
	 *
	 * @return array<string,mixed>
	 */
	private static function summary(string $code, array $figures): array {
		return [
			'id' => 'sum-' . $code, 'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2026',
			'jurisdiction' => $code, 'state' => 'reconciled',
		] + $figures;
	}//end summary()

	/**
	 * The safe harbour records written, as jurisdiction/test => result.
	 *
	 * @return array<string,string>
	 */
	private function writtenTests(): array {
		$this->store->setSchema('Pillar2SafeHarbour');
		$written = [];
		foreach ($this->store->findAll() as $row) {
			$written[$row['jurisdiction'] . '/' . $row['testApplied']] = $row['testResult'];
		}

		ksort($written);
		return $written;
	}//end writtenTests()

	/**
	 * 2026: NL passes the simplified ETR at 17%, DE the de minimis test, PL
	 * the routine profits test at 9.2% and 7.2%; IE passes none and gets a
	 * draft computation prefilled from its summary.
	 *
	 * @return void
	 */
	public function testEachTestPassesOrFailsAndTheFailingJurisdictionGetsADraft(): void {
		$service = $this->service(
			[
				self::summary(
					'NL',
					[
						'unrelatedPartyRevenue' => 300000000.0, 'relatedPartyRevenue' => 40000000.0, 'profitBeforeTax' => 50000000.0,
						'incomeTaxAccrued' => 8000000.0, 'deferredTaxExpense' => 500000.0,
					]
				),
				self::summary(
					'DE',
					['unrelatedPartyRevenue' => 6000000.0, 'relatedPartyRevenue' => 3000000.0, 'profitBeforeTax' => 900000.0, 'incomeTaxAccrued' => 0.0]
				),
				self::summary(
					'PL',
					[
						'unrelatedPartyRevenue' => 40000000.0,
						'profitBeforeTax' => 1000000.0,
						'eligiblePayroll' => 10000000.0,
						'tangibleAssetsOtherThanCash' => 2000000.0,
					]
				),
				self::summary(
					'IE',
					[
						'unrelatedPartyRevenue' => 50000000.0, 'profitBeforeTax' => 20000000.0, 'incomeTaxAccrued' => 2500000.0,
						'eligiblePayroll' => 3000000.0, 'tangibleAssetsOtherThanCash' => 1000000.0,
					]
				),
			],
			[self::assessment(2026, '2028-05-31')]
		);

		$outcome = $service->test('ge-holding', 2026);

		$this->assertSame(['DE', 'IE', 'NL', 'PL'], array_keys($outcome));
		$this->assertSame(['de-minimis'], $outcome['DE']['passed']);
		$this->assertSame(['simplified-etr'], $outcome['NL']['passed']);
		$this->assertSame(['routine-profits'], $outcome['PL']['passed']);
		$this->assertSame([], $outcome['IE']['passed']);
		$this->assertNull($outcome['NL']['computation']);
		$this->assertCount(12, $this->writtenTests());
		$this->assertSame('fail', $this->writtenTests()['NL/de-minimis']);

		$this->store->setSchema('Pillar2JurisdictionComputation');
		$drafts = $this->store->findAll();
		$this->assertCount(1, $drafts);
		$this->assertSame($outcome['IE']['computation'], $drafts[0]['id']);
		$this->assertSame(
			['IE', 'FY2026', 'draft', 20000000.0, 2500000.0, 3000000.0, 1000000.0, 0.092, 0.072, 0.15],
			[
				$drafts[0]['jurisdiction'], $drafts[0]['period'], $drafts[0]['state'], $drafts[0]['commercialProfitBeforeTax'],
				$drafts[0]['adjustedCoveredTaxes'], $drafts[0]['payroll'], $drafts[0]['tangibleAssetsNbv'],
				$drafts[0]['carveOutRatePayroll'], $drafts[0]['carveOutRateTangible'], $drafts[0]['minimumRate'],
			]
		);

		$this->store->setSchema('Pillar2SafeHarbour');
		$isNlEtr = static fn (array $row): bool => $row['jurisdiction'] === 'NL' && $row['testApplied'] === 'simplified-etr';
		$etr     = array_values(array_filter($this->store->findAll(), $isNlEtr))[0];
		$this->assertSame(
			['coveredTaxes' => 8500000.0, 'profitBeforeTax' => 50000000.0, 'simplifiedEtr' => 0.17, 'threshold' => 0.17],
			$etr['supportingCalculations']
		);
		$this->assertSame('qualified-cbcr', $etr['dataSource']);
	}//end testEachTestPassesOrFailsAndTheFailingJurisdictionGetsADraft()

	/**
	 * A rerun updates the records in place and leaves an approved computation alone.
	 *
	 * @return void
	 */
	public function testARerunUpdatesInPlaceAndKeepsAnApprovedComputation(): void {
		$service = $this->service(
			[self::summary('IE', ['unrelatedPartyRevenue' => 50000000.0, 'profitBeforeTax' => 20000000.0, 'incomeTaxAccrued' => 2500000.0])],
			[self::assessment(2026)],
			[
				[
					'id' => 'comp-ie', 'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2026', 'jurisdiction' => 'IE',
					'state' => 'approved', 'commercialProfitBeforeTax' => 1.0,
				],
			]
		);

		$service->test('ge-holding', 2026);
		$outcome = $service->test('ge-holding', 2026);

		$this->assertSame('comp-ie', $outcome['IE']['computation']);
		$this->assertCount(3, $this->writtenTests());
		$this->store->setSchema('Pillar2JurisdictionComputation');
		$this->assertSame(1.0, $this->store->findAll()[0]['commercialProfitBeforeTax']);
	}//end testARerunUpdatesInPlaceAndKeepsAnApprovedComputation()

	/**
	 * A 2027 year is past the transitional window: no safe harbour record, a computation everywhere.
	 *
	 * @return void
	 */
	public function testA2027YearHasNoSafeHarbour(): void {
		$service = $this->service(
			[
				[
					'id' => 's1', 'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2027',
					'jurisdiction' => 'DE', 'unrelatedPartyRevenue' => 1000.0, 'profitBeforeTax' => 10.0,
				],
				[
					'id' => 's2', 'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2027',
					'jurisdiction' => 'NL', 'profitBeforeTax' => 100.0, 'incomeTaxAccrued' => 30.0,
				],
			],
			[self::assessment(2027)]
		);

		$outcome = $service->test('ge-holding', 2027);

		$this->assertSame([], $this->writtenTests());
		$this->assertSame([[], []], array_column($outcome, 'passed'));
		$this->store->setSchema('Pillar2JurisdictionComputation');
		$this->assertCount(2, $this->store->findAll());
		$this->assertSame(0.09, $this->store->findAll()[0]['carveOutRatePayroll']);
	}//end testA2027YearHasNoSafeHarbour()

	/**
	 * Without the minimum tax in scope the tests refuse.
	 *
	 * @return void
	 */
	public function testAYearOutOfScopeIsRefused(): void {
		$service = $this->service([], [['id' => 'gsa', 'ultimateParentEntity' => 'ge-holding', 'fiscalYear' => 2026, 'pillar2InScope' => false]]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The minimum tax does not apply to 2026 for this group; assess its scope first.');
		$service->test('ge-holding', 2026);
	}//end testAYearOutOfScopeIsRefused()

	/**
	 * "NL-dochter met ETR 12%": GloBE income EUR 6M, ETR 12% and SBIE EUR 0.5M
	 * give a QDMTT of EUR 165,000, payable by the bijheffing deadline.
	 *
	 * @return void
	 */
	public function testTheNlTwelvePercentCaseGivesAQdmttOf165000(): void {
		$service = $this->service(
			[],
			[self::assessment(2027, '2029-08-31')],
			[
				[
					'id' => 'comp-nl', 'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2027', 'jurisdiction' => 'NL', 'state' => 'approved',
					'commercialProfitBeforeTax' => 6200000.0, 'globeIncomeAdjustments' => [['type' => 'excluded-dividends', 'amount' => -200000.0]],
					'adjustedCoveredTaxes' => 720000.0, 'payroll' => 5000000.0, 'carveOutRatePayroll' => 0.09,
					'tangibleAssetsNbv' => 714285.72, 'carveOutRateTangible' => 0.07, 'minimumRate' => 0.15,
				],
			]
		);

		$return = $service->applyQdmtt('comp-nl');

		$this->assertSame(
			[
				'period' => 'FY2027', 'entity' => 'ge-holding', 'taxableGlobeIncome' => 6000000.0, 'qualifyingDomesticEtr' => 0.12,
				'qdmttPayable' => 165000.0, 'filingDueDate' => '2029-08-31', 'paymentDueDate' => '2029-08-31', 'state' => 'draft',
			],
			array_diff_key($return, ['id' => true])
		);
		$this->store->setSchema('Pillar2JurisdictionComputation');
		$computation = $this->store->findAll()[0];
		$this->assertTrue($computation['qdmttApplicable']);
		$this->assertSame(165000.0, $computation['qdmttAmount']);
	}//end testTheNlTwelvePercentCaseGivesAQdmttOf165000()

	/**
	 * No QDMTT for another jurisdiction, at or above 15%, or under a safe harbour.
	 *
	 * @return void
	 */
	public function testNoQdmttOutsideTheNlLowTaxCase(): void {
		$base    = [
			'ultimateParentEntity' => 'ge-holding', 'period' => 'FY2026', 'state' => 'approved',
			'commercialProfitBeforeTax' => 1000000.0, 'minimumRate' => 0.15,
		];
		$service = $this->service(
			[],
			[self::assessment(2026)],
			[
				['id' => 'comp-de', 'jurisdiction' => 'DE', 'adjustedCoveredTaxes' => 10000.0] + $base,
				['id' => 'comp-nl', 'jurisdiction' => 'NL', 'adjustedCoveredTaxes' => 150000.0] + $base,
			]
		);

		$this->assertNull($service->applyQdmtt('comp-de'));
		$this->assertNull($service->applyQdmtt('comp-nl'));

		$this->store->setSchema('Pillar2JurisdictionComputation');
		$this->store->saveObject(['id' => 'comp-nl', 'jurisdiction' => 'NL', 'adjustedCoveredTaxes' => 10000.0] + $base);
		$this->store->setSchema('Pillar2SafeHarbour');
		$this->store->saveObject(
			['ultimateParentEntity' => 'ge-holding', 'period' => 'FY2026', 'jurisdiction' => 'NL', 'testApplied' => 'de-minimis', 'testResult' => 'pass']
		);
		$this->assertNull($service->applyQdmtt('comp-nl'));

		$this->store->setSchema('QdmttReturn');
		$this->assertSame([], $this->store->findAll());
	}//end testNoQdmttOutsideTheNlLowTaxCase()

	/**
	 * The carve-out table follows GloBE art. 9.2: 9.2% and 7.2% for 2026, 5% from 2033.
	 *
	 * @return void
	 */
	public function testTheCarveOutRatesFollowTheTransitionTable(): void {
		$this->assertSame(['payroll' => 0.098, 'tangible' => 0.078], Pillar2SafeHarbourService::carveOutRates(2023));
		$this->assertSame(['payroll' => 0.092, 'tangible' => 0.072], Pillar2SafeHarbourService::carveOutRates(2026));
		$this->assertSame(['payroll' => 0.082, 'tangible' => 0.066], Pillar2SafeHarbourService::carveOutRates(2028));
		$this->assertSame(['payroll' => 0.05, 'tangible' => 0.05], Pillar2SafeHarbourService::carveOutRates(2033));
		$this->assertSame(['payroll' => 0.098, 'tangible' => 0.078], Pillar2SafeHarbourService::carveOutRates(2021));
	}//end testTheCarveOutRatesFollowTheTransitionTable()
}//end class
