<?php

/**
 * Unit tests for the BIKStaffelCalculator (REQ-CCD-003).
 *
 * Exercises the Besluit BIK staffel (15%/10%/5%/1%/0,5% over five graduated
 * slabs with €40 minimum) and the wettelijke rente accrual per art. 6:119 BW
 * (B2C 7%) and art. 6:119a BW (B2B handelsrente 11,5%).
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\Shillinq\Service\BIKStaffelCalculator;
use PHPUnit\Framework\TestCase;

/**
 * BIKStaffelCalculator unit tests.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class BIKStaffelCalculatorTest extends TestCase {
	/**
	 * Subject under test.
	 *
	 * @var BIKStaffelCalculator
	 */
	private BIKStaffelCalculator $calc;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->calc = new BIKStaffelCalculator();

	}//end setUp()

	/**
	 * Spec scenario: B2B incassokostenstaffel calculated correctly on €8.400.
	 *
	 * Per REQ-CCD-003: 15% × €2.500 (€375) + 10% × €2.500 (€250)
	 *                + 5% × €3.400 (€170) = €795 total.
	 *
	 * @return void
	 */
	public function testStaffelOn8400EurEquals795(): void {
		$s = $this->calc->staffel(principal: 8400.00);
		self::assertSame(375.0, $s['scale1_0_2500']);
		self::assertSame(250.0, $s['scale2_2500_5000']);
		self::assertSame(170.0, $s['scale3_5000_10000']);
		self::assertSame(0.0, $s['scale4_10000_200000']);
		self::assertSame(0.0, $s['scale5_200000plus']);
		self::assertSame(795.0, $s['total']);
		self::assertSame(795.0, $s['applied']);
		self::assertSame(40.0, $s['minimum']);

	}//end testStaffelOn8400EurEquals795()

	/**
	 * Spec scenario: minimum €40 floor applies for tiny hoofdsom.
	 *
	 * Per REQ-CCD-004 scenario 2: €100 → toegepast must be €40, not €15.
	 *
	 * @return void
	 */
	public function testStaffelMinimumFloorAt40(): void {
		$s = $this->calc->staffel(principal: 100.00);
		self::assertSame(15.0, $s['total']);
		self::assertSame(40.0, $s['applied']);

	}//end testStaffelMinimumFloorAt40()

	/**
	 * Mid-band ladder hits €5.000 exactly (15% × 2500 + 10% × 2500 = €625).
	 *
	 * @return void
	 */
	public function testStaffelAt5000EurExactly(): void {
		$s = $this->calc->staffel(principal: 5000.00);
		self::assertSame(625.0, $s['applied']);

	}//end testStaffelAt5000EurExactly()

	/**
	 * High-band ladder (250000) — 5 slabs all populated.
	 *
	 * 15% × 2500 + 10% × 2500 + 5% × 5000 + 1% × 190000 + 0.5% × 50000
	 * = 375 + 250 + 250 + 1900 + 250 = 3025.
	 *
	 * @return void
	 */
	public function testStaffelOn250000(): void {
		$s = $this->calc->staffel(principal: 250000.00);
		self::assertSame(375.0, $s['scale1_0_2500']);
		self::assertSame(250.0, $s['scale2_2500_5000']);
		self::assertSame(250.0, $s['scale3_5000_10000']);
		self::assertSame(1900.0, $s['scale4_10000_200000']);
		self::assertSame(250.0, $s['scale5_200000plus']);
		self::assertSame(3025.0, $s['applied']);

	}//end testStaffelOn250000()

	/**
	 * Negative hoofdsom raises InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testStaffelRejectsNegativeHoofdsom(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->calc->staffel(principal: -10.00);

	}//end testStaffelRejectsNegativeHoofdsom()

	/**
	 * Spec scenario: B2B handelsrente per art. 6:119a BW (current 2026 rate).
	 *
	 * The rate is resolved from the maintained date-keyed table: for a 2026
	 * accrual window the handelsrente is 10,15% (per 1-1-2026, Wieringa /
	 * wettelijke-rente.com), NOT the stale 11,5% the pre-WIK code hard-coded.
	 *
	 * 8.400 × 0.1015 × 22 / 365 = €51.39; tarief 0.1015; type
	 * HANDELSRENTE_B2B_6_119A_BW.
	 *
	 * @return void
	 */
	public function testRenteB2BHandelsrenteOn8400Eur22Days(): void {
		$r = $this->calc->rente(
			partyType: 'B2B',
			principal: 8400.00,
			effectiveDate: new DateTimeImmutable('2026-05-30'),
			calculatedOn: new DateTimeImmutable('2026-06-21')
		);

		self::assertSame(0.1015, $r['rate']);
		self::assertSame('COMMERCIAL_INTEREST_B2_B_6_119_A_BW', $r['type']);
		self::assertSame(22, $r['days']);
		// 840000 × 0.1015 × 22 / 365 = 5138.96 → 5139 cents.
		self::assertEqualsWithDelta(51.39, $r['amount'], 0.01);

	}//end testRenteB2BHandelsrenteOn8400Eur22Days()

	/**
	 * Spec scenario: B2C wettelijke rente per art. 6:119 BW (current 2026 rate).
	 *
	 * For a 2026 accrual window the wettelijke rente is 4% (per 1-1-2026,
	 * AMvB 10-12-2025), NOT the stale 7% the pre-WIK code hard-coded.
	 *
	 * 820 × 0.04 × 31 / 365 = €2.79; tarief 0.04; type
	 * WETTELIJKE_RENTE_B2C_6_119_BW.
	 *
	 * @return void
	 */
	public function testRenteB2CWettelijkeRenteOn820Eur31Days(): void {
		$r = $this->calc->rente(
			partyType: 'B2C',
			principal: 820.00,
			effectiveDate: new DateTimeImmutable('2026-05-30'),
			calculatedOn: new DateTimeImmutable('2026-06-30')
		);

		self::assertSame(0.04, $r['rate']);
		self::assertSame('STATUTORY_INTEREST_B2_C_6_119_BW', $r['type']);
		self::assertSame(31, $r['days']);
		// 82000 × 0.04 × 31 / 365 = 278.6 → 279 cents.
		self::assertEqualsWithDelta(2.79, $r['amount'], 0.01);

	}//end testRenteB2CWettelijkeRenteOn820Eur31Days()

	/**
	 * berekendOp before ingangsdatum is rejected.
	 *
	 * @return void
	 */
	public function testRenteRejectsBerekendOpBeforeIngangsdatum(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->calc->rente(
			partyType: 'B2B',
			principal: 1000,
			effectiveDate: new DateTimeImmutable('2026-06-01'),
			calculatedOn: new DateTimeImmutable('2026-05-01')
		);

	}//end testRenteRejectsBerekendOpBeforeIngangsdatum()

	/**
	 * REQ-CCD-006: B2C calc is blocked before dag 44.
	 *
	 * @return void
	 */
	public function testB2C_14DayGraceBlocksBeforeDay44(): void {
		self::assertFalse($this->calc->isCalculationPermitted(partyType: 'B2C', daysInArrears: 35));
		self::assertFalse($this->calc->isCalculationPermitted(partyType: 'B2C', daysInArrears: 43));
		self::assertTrue($this->calc->isCalculationPermitted(partyType: 'B2C', daysInArrears: 44));
		self::assertTrue($this->calc->isCalculationPermitted(partyType: 'B2C', daysInArrears: 60));
		// B2B has no grace — verzuim van rechtswege per art. 6:83 BW.
		self::assertTrue($this->calc->isCalculationPermitted(partyType: 'B2B', daysInArrears: 0));

	}//end testB2C_14DayGraceBlocksBeforeDay44()

	/**
	 * compose() returns a record ready to persist on IncassoKostenBerekening.
	 *
	 * @return void
	 */
	public function testComposeYieldsPersistenceShape(): void {
		$body = $this->calc->compose(
			invoiceId: 'inv-2026-0247',
			administrationId: 'adm-1',
			partyType: 'B2B',
			principal: 8400.00,
			effectiveDate: new DateTimeImmutable('2026-05-30'),
			calculatedOn: new DateTimeImmutable('2026-06-21')
		);

		self::assertSame('inv-2026-0247', $body['invoiceId']);
		self::assertSame('adm-1', $body['administrationId']);
		self::assertSame('B2B', $body['partyType']);
		self::assertSame(8400.0, $body['principal']);
		self::assertSame(795.0, $body['calculation']['applied']);
		self::assertSame('COMMERCIAL_INTEREST_B2_B_6_119_A_BW', $body['statutoryRente']['type']);
		// 8400 + 795 (no BTW surcharge, creditor can offset) + 51.39 rente = 9246.39.
		self::assertEqualsWithDelta(9246.39, $body['totalDue'], 0.01);

	}//end testComposeYieldsPersistenceShape()

	/**
	 * Worked example — statutory maximum €6.775 cap (Besluit BIK).
	 *
	 * Uncapped, a €2.000.000 hoofdsom would yield
	 *   375 + 250 + 250 + 1900 + 0,5% × 1.800.000 (=9000) = €11.775.
	 * The legal ceiling is €6.775, reached at a €1.000.000 claim, so both
	 * €1.000.000 and €2.000.000 MUST return toegepast = €6.775.
	 *
	 * @return void
	 */
	public function testStaffelMaximumCapAt6775(): void {
		$atCap = $this->calc->staffel(principal: 1000000.00);
		self::assertSame(6775.0, $atCap['maximum']);
		self::assertSame(6775.0, $atCap['applied']);
		// Raw staffel at exactly €1M lands on the cap: 375+250+250+1900+4000.
		self::assertSame(6775.0, $atCap['total']);

		$overCap = $this->calc->staffel(principal: 2000000.00);
		// Uncapped totaal is €11.775, but toegepast is clamped to €6.775.
		self::assertSame(11775.0, $overCap['total']);
		self::assertSame(6775.0, $overCap['applied']);

	}//end testStaffelMaximumCapAt6775()

	/**
	 * Worked example — BTW-over-incassokosten (art. 2 lid 2 Besluit BIK).
	 *
	 * When the creditor cannot offset VAT (btwVerrekenbaar=false) and declares
	 * this, the €795 staffel fee is increased by 21%: €166.95 BTW →
	 * €961.95 incl. BTW. When the creditor CAN offset (default), no surcharge.
	 *
	 * @return void
	 */
	public function testStaffelBtwSurchargeWhenNotDeductible(): void {
		$withVat = $this->calc->staffel(principal: 8400.00, vatOffsettable: false);
		self::assertSame(795.0, $withVat['applied']);
		self::assertSame(0.21, $withVat['vatPercentage']);
		// 79500 × 0.21 = 16695 cents.
		self::assertSame(166.95, $withVat['vatAmount']);
		self::assertSame(961.95, $withVat['appliedInclVat']);

		$noVat = $this->calc->staffel(principal: 8400.00);
		self::assertTrue($noVat['vatOffsettable']);
		self::assertSame(0.0, $noVat['vatAmount']);
		self::assertSame(795.0, $noVat['appliedInclVat']);

	}//end testStaffelBtwSurchargeWhenNotDeductible()

	/**
	 * Worked example — rente accrual that crosses a statutory rate boundary.
	 *
	 * A €10.000 B2C claim accruing 2025-12-17 → 2026-01-16 spans the
	 * 1-1-2026 boundary where wettelijke rente drops 6% → 4%:
	 *   - 2025-12-17 → 2026-01-01 : 15 days @ 6% = €24.66
	 *   - 2026-01-01 → 2026-01-16 : 15 days @ 4% = €16.44
	 *   total 30 days = €41.10 (a single flat 4% would wrongly yield €32.88).
	 *
	 * @return void
	 */
	public function testRenteSplitsAcrossRateBoundary(): void {
		$r = $this->calc->rente(
			partyType: 'B2C',
			principal: 10000.00,
			effectiveDate: new DateTimeImmutable('2025-12-17'),
			calculatedOn: new DateTimeImmutable('2026-01-16')
		);

		self::assertSame(30, $r['days']);
		self::assertCount(2, $r['periods']);
		self::assertSame(0.06, $r['periods'][0]['rate']);
		self::assertSame(0.04, $r['periods'][1]['rate']);
		self::assertEqualsWithDelta(24.66, $r['periods'][0]['amount'], 0.01);
		self::assertEqualsWithDelta(16.44, $r['periods'][1]['amount'], 0.01);
		self::assertEqualsWithDelta(41.10, $r['amount'], 0.01);
		// Headline tarief is the rate in force on berekendOp (4% in 2026).
		self::assertSame(0.04, $r['rate']);

	}//end testRenteSplitsAcrossRateBoundary()

	/**
	 * Explicit B2B override forces a flat rate (art. 6:119a lid 3 contractual).
	 *
	 * @return void
	 */
	public function testRenteHonoursExplicitOverride(): void {
		$r = $this->calc->rente(
			partyType: 'B2B',
			principal: 8400.00,
			effectiveDate: new DateTimeImmutable('2026-05-30'),
			calculatedOn: new DateTimeImmutable('2026-06-21'),
			rateB2B: 0.12
		);

		self::assertSame(0.12, $r['rate']);
		self::assertCount(1, $r['periods']);
		// 840000 × 0.12 × 22 / 365 = 6075.6 → 6076 cents.
		self::assertEqualsWithDelta(60.76, $r['amount'], 0.01);

	}//end testRenteHonoursExplicitOverride()

}//end class
