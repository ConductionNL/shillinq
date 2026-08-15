<?php

/**
 * Unit tests for IntegralCostPriceCalculator.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\IntegralCostPriceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the IKP calculator (REQ-WMO-002).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class IntegralCostPriceCalculatorTest extends TestCase {

	/**
	 * The service under test.
	 */
	private IntegralCostPriceCalculator $svc;

	/**
	 * Set up test fixtures.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->svc = new IntegralCostPriceCalculator();

	}//end setUp()

	/**
	 * Direct cost summation matches the activity's kostenplaats + kostendrager + accountKind triple.
	 */
	public function testSumDirectCostsFiltersOnTriple(): void {
		$lines = [
			['costCentre' => 'K-SP-014', 'costObject' => 'D-MO-SP-014', 'accountKind' => 'payrollCost', 'amount' => 12345.67],
			['costCentre' => 'K-SP-014', 'costObject' => 'D-MO-SP-014', 'accountKind' => 'payrollCost', 'amount' => 1000.00],
			['costCentre' => 'K-SP-014', 'costObject' => 'D-OTHER',     'accountKind' => 'payrollCost', 'amount' => 9999.00],
			['costCentre' => 'K-SP-014', 'costObject' => 'D-MO-SP-014', 'accountKind' => 'materialen', 'amount' => 500.00],
		];

		$totalCents = $this->svc->sumDirectCosts($lines, 'K-SP-014', 'D-MO-SP-014', 'payrollCost');
		self::assertSame(1334567, $totalCents);

	}//end testSumDirectCostsFiltersOnTriple()

	/**
	 * Overhead distribution applies each bucket ratio to the corporate pool and aggregates per bucket.
	 */
	public function testDistributeOverheadAggregatesPerBucket(): void {
		$rule = [
			'ratios' => [
				['bucket' => 'huisvesting', 'ratio' => 0.40, 'costObject' => 'D-MO-SP-014'],
				['bucket' => 'ict',         'ratio' => 0.20, 'costObject' => 'D-MO-SP-014'],
				['bucket' => 'huisvesting', 'ratio' => 0.10, 'costObject' => 'D-MO-SP-014'],
				['bucket' => 'directieEnStaf', 'ratio' => 0.05, 'costObject' => 'D-OTHER'],
			],
		];

		$buckets = $this->svc->distributeOverhead(2_614_000, $rule, 'D-MO-SP-014');
		// 40% + 10% = 50% to huisvesting = 1307000; 20% to ict = 522800.
		self::assertSame(1_307_000, $buckets['huisvesting']);
		self::assertSame(522_800, $buckets['ict']);
		self::assertArrayNotHasKey('directieEnStaf', $buckets);

	}//end testDistributeOverheadAggregatesPerBucket()

	/**
	 * Vermogenskosten = invested × WACC × period-fraction (REQ-WMO-002).
	 */
	public function testCalculateVermogenskosten(): void {
		// 40_400 EUR invested × 4.5% × 1 = 1818 EUR = 181800 cents.
		self::assertSame(181_800, $this->svc->calculateVermogenskosten(4_040_000, 0.045));
		// 40_400 × 4.5% × 0.25 (quarter) = 454.5 EUR = 45450 cents.
		self::assertSame(45_450, $this->svc->calculateVermogenskosten(4_040_000, 0.045, 0.25));

	}//end testCalculateVermogenskosten()

	/**
	 * The profit mark-up is a markup on the pre-markup base (REQ-WMO-002).
	 */
	public function testCalculateProfitMarkup(): void {
		// 85_000 × 3% = 2_550 EUR.
		self::assertSame(255_000, $this->svc->calculateProfitMarkup(8_500_000));
		// 100_000 × 5% custom rate = 5_000.
		self::assertSame(500_000, $this->svc->calculateProfitMarkup(10_000_000, 0.05));

	}//end testCalculateProfitMarkup()

	/**
	 * Compliance: compares per-unit when available, falls back to per-period total.
	 */
	public function testIsCompliantPerUnitAndPerPeriod(): void {
		self::assertTrue($this->svc->isCompliant(295.0, 280.0, 8_739_000));
		self::assertFalse($this->svc->isCompliant(250.0, 280.0, 8_739_000));
		// No per-unit data, fall back to omzet vs totaleKosten.
		self::assertTrue($this->svc->isCompliant(null, null, 8_739_000, 90_000.0));
		self::assertFalse($this->svc->isCompliant(null, null, 8_739_000, 80_000.0));

	}//end testIsCompliantPerUnitAndPerPeriod()

	/**
	 * Full compose: REQ-WMO-002 monthly voorlopig with BBV-sleutel overhead.
	 */
	public function testComposeMonthlyVoorlopig(): void {
		$glLines = [
			['costCentre' => 'K-SP-014', 'costObject' => 'D-MO-SP-014', 'accountKind' => 'payrollCost',     'amount' => 41250.00],
			['costCentre' => 'K-SP-014', 'costObject' => 'D-MO-SP-014', 'accountKind' => 'materialen',     'amount' => 8730.00],
			['costCentre' => 'K-SP-014', 'costObject' => 'D-MO-SP-014', 'accountKind' => 'depreciations', 'amount' => 6900.00],
		];

		$rule = [
			'id' => 'odr-bbv-2026',
			'ratios' => [
				['bucket' => 'directieEnStaf', 'ratio' => 0.072, 'costObject' => 'D-MO-SP-014'],
			],
		];

		$ikp = $this->svc->compose([
			'commercialActivityId' => 'ca-mo-sp-014',
			'period' => '2026-Q1',
			'administrationId' => 'adm-tilburg',
			'costCentre' => 'K-SP-014',
			'costObject' => 'D-MO-SP-014',
			'glLines' => $glLines,
			'corporateOverheadCents' => 36_580_000, // €365.8k
			'overheadRule' => $rule,
			'investedBookValueCents' => 4_040_000, // €40.4k
			'waccRate' => 0.045,
			'periodFraction' => 1.0,
			'profitMarkupRate' => 0.03,
			'soldUnits' => 312.0,
			'unitLabel' => 'dagdeel-zaalhuur',
			'appliedRate' => 295.0,
		]);

		self::assertSame('voorlopig', $ikp['status']);
		self::assertSame(41_250.00, $ikp['componenten']['directPayrollCost']);
		self::assertSame(8_730.00, $ikp['componenten']['directMaterials']);
		self::assertSame(6_900.00, $ikp['componenten']['directDepreciations']);
		// Overhead: 36_580_000 × 7.2% = 2_633_760 cents = €26 337.60.
		self::assertSame(26_337.60, $ikp['componenten']['indirecteOverhead']['directieEnStaf']);
		// Vermogenskosten: 4_040_000 × 4.5% = 181_800 cents = €1818.
		self::assertSame(1_818.00, $ikp['componenten']['capitalCost']);
		// Base = 41 250 + 8 730 + 6 900 + 26 337.60 + 1 818 = 85 035.60; winstopslag 3% = 2 551.07
		self::assertSame(2_551.07, $ikp['componenten']['profitMarkup']);
		self::assertSame(87_586.67, $ikp['totalCost']);
		self::assertEqualsWithDelta(280.7265, $ikp['costPricePerUnit'], 0.01);
		self::assertSame(295.0, $ikp['appliedRate']);
		self::assertTrue($ikp['compliant']);
		self::assertGreaterThan(0, $ikp['marge']);
		self::assertSame('dagdeel-zaalhuur', $ikp['unitLabel']);

	}//end testComposeMonthlyVoorlopig()

}//end class
