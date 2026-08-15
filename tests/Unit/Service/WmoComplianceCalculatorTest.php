<?php

/**
 * Unit tests for WmoComplianceCalculator.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\WmoComplianceCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure-logic WMO compliance arithmetic helper.
 *
 * Covers REQ-WMO-001c (stale-activity review trigger), REQ-WMO-002 (IKP
 * composition, vermogenskosten, winstopslag, compliance verdict), REQ-WMO-003
 * (cent-conserving transaction split) and REQ-WMO-004 (kostendekkingsratio).
 *
 * PHPUnit assertions take positional ($actual, $expected) arguments; the custom
 * named-parameter sniff does not apply to them.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class WmoComplianceCalculatorTest extends TestCase {

	/**
	 * The helper under test.
	 *
	 * @var WmoComplianceCalculator
	 */
	private WmoComplianceCalculator $calc;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->calc = new WmoComplianceCalculator();

	}//end setUp()

	/**
	 * Integer-cent conversion avoids float drift (REQ-WMO-002 precision).
	 *
	 * @return void
	 */
	public function testCentConversionRoundsHalfUp(): void {
		self::assertSame(18400, $this->calc->toCents(184.00));
		self::assertSame(280, $this->calc->toCents(2.795));
		self::assertSame(0, $this->calc->toCents(null));
		self::assertSame(2.8, $this->calc->fromCents(280));

	}//end testCentConversionRoundsHalfUp()

	/**
	 * The IKP sums the six statutory components, flattening the overhead map (REQ-WMO-002).
	 *
	 * Mirrors the spec scenario: directe loonkosten €41.25k + materialen €8.73k +
	 * afschrijvingen €6.9k + overhead €26.14k + vermogenskosten €1.82k + winstopslag
	 * €2.55k = €87.39k, and over 312 dagdelen yields €280.10/unit.
	 *
	 * @return void
	 */
	public function testIntegralCostPriceSumsComponentsAndPerUnit(): void {
		$componenten = [
			'directPayrollCost' => 41250,
			'directMaterials' => 8730,
			'directDepreciations' => 6900,
			'indirecteOverhead' => [
				'huisvesting' => 12000,
				'ict' => 6140,
				'directieEnStaf' => 5000,
				'facilitair' => 3000,
			],
			'capitalCost' => 1820,
			'profitMarkup' => 2550,
		];

		$result = $this->calc->integralCostPrice($componenten, 312.0);

		self::assertSame(87390.0, $result['totalCost']);
		self::assertSame(280.10, $result['costPricePerUnit']);

	}//end testIntegralCostPriceSumsComponentsAndPerUnit()

	/**
	 * Without tracked units the per-unit price is null but the total still sums (REQ-WMO-002).
	 *
	 * @return void
	 */
	public function testIntegralCostPriceNullPerUnitWhenNoUnits(): void {
		$componenten = [
			'directPayrollCost' => 1000,
			'directMaterials' => 0,
			'directDepreciations' => 0,
			'indirecteOverhead' => [],
			'capitalCost' => 0,
			'profitMarkup' => 0,
		];

		$result = $this->calc->integralCostPrice($componenten, null);
		self::assertSame(1000.0, $result['totalCost']);
		self::assertNull($result['costPricePerUnit']);

		$zeroUnits = $this->calc->integralCostPrice($componenten, 0.0);
		self::assertNull($zeroUnits['costPricePerUnit']);

	}//end testIntegralCostPriceNullPerUnitWhenNoUnits()

	/**
	 * Vermogenskosten applies the WACC rate to the capital base (REQ-WMO-002).
	 *
	 * @return void
	 */
	public function testVermogenskostenAppliesWacc(): void {
		// 4.5% on €40,400 equipment ≈ €1,818.
		self::assertSame(1818.0, $this->calc->vermogenskosten(40400.0));
		// Configurable rate.
		self::assertSame(2000.0, $this->calc->vermogenskosten(40000.0, 0.05));

	}//end testVermogenskostenAppliesWacc()

	/**
	 * The profit mark-up applies its rate to the costs-before-mark-up (REQ-WMO-002).
	 *
	 * @return void
	 */
	public function testProfitMarkupAppliesRate(): void {
		// 3% default on €85,000.
		self::assertSame(2550.0, $this->calc->profitMarkup(85000.0));
		// Configurable rate per activity.
		self::assertSame(4250.0, $this->calc->profitMarkup(85000.0, 0.05));

	}//end testProfitMarkupAppliesRate()

	/**
	 * Compliance is per-unit when units are tracked: tarief covers per-unit IKP (REQ-WMO-002).
	 *
	 * @return void
	 */
	public function testComplianceVerdictPerUnitCompliant(): void {
		$verdict = $this->calc->complianceVerdict(295.0, 87390.0, 280.10);
		self::assertTrue($verdict['compliant']);
		self::assertSame(14.90, $verdict['marge']);
		self::assertSame(5.32, $verdict['margePercentage']);

	}//end testComplianceVerdictPerUnitCompliant()

	/**
	 * A tarief below the per-unit IKP is non-compliant with a negative marge (REQ-WMO-002).
	 *
	 * @return void
	 */
	public function testComplianceVerdictPerUnitNonCompliant(): void {
		$verdict = $this->calc->complianceVerdict(250.0, 87390.0, 280.10);
		self::assertFalse($verdict['compliant']);
		self::assertSame(-30.10, $verdict['marge']);
		self::assertTrue($verdict['margePercentage'] < 0.0);

	}//end testComplianceVerdictPerUnitNonCompliant()

	/**
	 * Compliance falls back to the total when no units are tracked (REQ-WMO-002).
	 *
	 * @return void
	 */
	public function testComplianceVerdictAgainstTotalWhenNoUnits(): void {
		$verdict = $this->calc->complianceVerdict(90000.0, 87390.0, null);
		self::assertTrue($verdict['compliant']);
		self::assertSame(2610.0, $verdict['marge']);

	}//end testComplianceVerdictAgainstTotalWhenNoUnits()

	/**
	 * A missing tarief is treated as non-compliant — a price MUST prove cost coverage.
	 *
	 * @return void
	 */
	public function testComplianceVerdictNonCompliantWhenNoTariff(): void {
		$verdict = $this->calc->complianceVerdict(null, 87390.0, 280.10);
		self::assertFalse($verdict['compliant']);
		self::assertSame(0.0, $verdict['marge']);
		self::assertSame(0.0, $verdict['margePercentage']);

	}//end testComplianceVerdictNonCompliantWhenNoTariff()

	/**
	 * A transaction splits per the rule ratios and conserves the total to the cent (REQ-WMO-003).
	 *
	 * Mirrors the Eneco energy-invoice scenario: €18,400 split 64/36 over two
	 * sub-administraties yields €11,776 publiek + €6,624 commercieel.
	 *
	 * @return void
	 */
	public function testSplitTransactionConservesTotal(): void {
		$targets = [
			['costObject' => 'D-PUBL-AWZI-01', 'ratio' => 0.64, 'dimension' => 'PUBL', 'generalLedger' => '443100'],
			['costObject' => 'D-MO-SVS-04', 'ratio' => 0.36, 'dimension' => 'MO', 'generalLedger' => '443900'],
		];

		$splits = $this->calc->splitTransaction(18400.0, $targets);

		self::assertCount(2, $splits);
		self::assertSame(11776.0, $splits[0]['amount']);
		self::assertSame(6624.0, $splits[1]['amount']);
		self::assertSame('PUBL', $splits[0]['dimension']);
		self::assertTrue($this->calc->splitsAreBalanced(18400.0, $splits));

	}//end testSplitTransactionConservesTotal()

	/**
	 * Rounding remainders are pushed onto the largest split so no cent is lost (REQ-WMO-003).
	 *
	 * €100.00 split three ways (1/3 each) is 33.33 + 33.33 + 33.34 = 100.00.
	 *
	 * @return void
	 */
	public function testSplitTransactionAbsorbsRoundingRemainder(): void {
		$third = (1.0 / 3.0);
		$targets = [
			['costObject' => 'A', 'ratio' => $third, 'dimension' => 'PUBL'],
			['costObject' => 'B', 'ratio' => $third, 'dimension' => 'PUBL'],
			['costObject' => 'C', 'ratio' => $third, 'dimension' => 'MO'],
		];

		$splits = $this->calc->splitTransaction(100.0, $targets);
		self::assertTrue($this->calc->splitsAreBalanced(100.0, $splits));

		$sum = 0.0;
		foreach ($splits as $split) {
			$sum += $split['amount'];
		}

		self::assertSame(100.0, round($sum, 2));

	}//end testSplitTransactionAbsorbsRoundingRemainder()

	/**
	 * The kostendekkingsratio is omzet/IKP as a percentage; >=100% is compliant (REQ-WMO-004).
	 *
	 * @return void
	 */
	public function testKostendekkingsratioCompliant(): void {
		$result = $this->calc->kostendekkingsratio(95000.0, 87390.0);
		self::assertSame(108.71, $result['ratio']);
		self::assertTrue($result['compliant']);

	}//end testKostendekkingsratioCompliant()

	/**
	 * A ratio below 100% (omzet < IKP) is non-compliant (REQ-WMO-004).
	 *
	 * @return void
	 */
	public function testKostendekkingsratioNonCompliant(): void {
		$result = $this->calc->kostendekkingsratio(80000.0, 87390.0);
		self::assertTrue($result['ratio'] < 100.0);
		self::assertFalse($result['compliant']);

	}//end testKostendekkingsratioNonCompliant()

	/**
	 * A zero or negative IKP yields a 0.0 ratio rather than a division by zero (REQ-WMO-004).
	 *
	 * @return void
	 */
	public function testKostendekkingsratioGuardsZeroDivision(): void {
		$result = $this->calc->kostendekkingsratio(50000.0, 0.0);
		self::assertSame(0.0, $result['ratio']);
		self::assertFalse($result['compliant']);

	}//end testKostendekkingsratioGuardsZeroDivision()

	/**
	 * An activity reviewed more than 365 days ago is due for review (REQ-WMO-001c).
	 *
	 * @return void
	 */
	public function testReviewDueWhenStale(): void {
		self::assertTrue($this->calc->reviewIsDue('2024-12-31T00:00:00Z', '2026-01-15T00:00:00Z'));

	}//end testReviewDueWhenStale()

	/**
	 * A recently reviewed activity is not yet due (REQ-WMO-001c).
	 *
	 * @return void
	 */
	public function testReviewNotDueWhenRecent(): void {
		self::assertFalse($this->calc->reviewIsDue('2025-12-31T00:00:00Z', '2026-01-15T00:00:00Z'));

	}//end testReviewNotDueWhenRecent()

	/**
	 * A never-reviewed (null) or unparseable timestamp is always due — never silently skipped.
	 *
	 * @return void
	 */
	public function testReviewDueWhenNeverReviewedOrMalformed(): void {
		self::assertTrue($this->calc->reviewIsDue(null, '2026-01-15T00:00:00Z'));
		self::assertTrue($this->calc->reviewIsDue('', '2026-01-15T00:00:00Z'));
		self::assertTrue($this->calc->reviewIsDue('not-a-date', '2026-01-15T00:00:00Z'));

	}//end testReviewDueWhenNeverReviewedOrMalformed()
}//end class
