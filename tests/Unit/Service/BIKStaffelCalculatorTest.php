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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/specs.md
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
final class BIKStaffelCalculatorTest extends TestCase
{
    /**
     * Subject under test.
     *
     * @var BIKStaffelCalculator
     */
    private BIKStaffelCalculator $calc;

    /**
     * @return void
     */
    protected function setUp(): void
    {
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
    public function testStaffelOn8400EurEquals795(): void
    {
        $s = $this->calc->staffel(hoofdsom: 8400.00);
        self::assertSame(375.0, $s['schaal1_0_2500']);
        self::assertSame(250.0, $s['schaal2_2500_5000']);
        self::assertSame(170.0, $s['schaal3_5000_10000']);
        self::assertSame(0.0, $s['schaal4_10000_200000']);
        self::assertSame(0.0, $s['schaal5_200000plus']);
        self::assertSame(795.0, $s['totaal']);
        self::assertSame(795.0, $s['toegepast']);
        self::assertSame(40.0, $s['minimum']);

    }//end testStaffelOn8400EurEquals795()

    /**
     * Spec scenario: minimum €40 floor applies for tiny hoofdsom.
     *
     * Per REQ-CCD-004 scenario 2: €100 → toegepast must be €40, not €15.
     *
     * @return void
     */
    public function testStaffelMinimumFloorAt40(): void
    {
        $s = $this->calc->staffel(hoofdsom: 100.00);
        self::assertSame(15.0, $s['totaal']);
        self::assertSame(40.0, $s['toegepast']);

    }//end testStaffelMinimumFloorAt40()

    /**
     * Mid-band ladder hits €5.000 exactly (15% × 2500 + 10% × 2500 = €625).
     *
     * @return void
     */
    public function testStaffelAt5000EurExactly(): void
    {
        $s = $this->calc->staffel(hoofdsom: 5000.00);
        self::assertSame(625.0, $s['toegepast']);

    }//end testStaffelAt5000EurExactly()

    /**
     * High-band ladder (250000) — 5 slabs all populated.
     *
     * 15% × 2500 + 10% × 2500 + 5% × 5000 + 1% × 190000 + 0.5% × 50000
     * = 375 + 250 + 250 + 1900 + 250 = 3025.
     *
     * @return void
     */
    public function testStaffelOn250000(): void
    {
        $s = $this->calc->staffel(hoofdsom: 250000.00);
        self::assertSame(375.0, $s['schaal1_0_2500']);
        self::assertSame(250.0, $s['schaal2_2500_5000']);
        self::assertSame(250.0, $s['schaal3_5000_10000']);
        self::assertSame(1900.0, $s['schaal4_10000_200000']);
        self::assertSame(250.0, $s['schaal5_200000plus']);
        self::assertSame(3025.0, $s['toegepast']);

    }//end testStaffelOn250000()

    /**
     * Negative hoofdsom raises InvalidArgumentException.
     *
     * @return void
     */
    public function testStaffelRejectsNegativeHoofdsom(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->staffel(hoofdsom: -10.00);

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
    public function testRenteB2BHandelsrenteOn8400Eur22Days(): void
    {
        $r = $this->calc->rente(
            partyType: 'B2B',
            hoofdsom: 8400.00,
            ingangsdatum: new DateTimeImmutable('2026-05-30'),
            berekendOp: new DateTimeImmutable('2026-06-21')
        );

        self::assertSame(0.1015, $r['tarief']);
        self::assertSame('HANDELSRENTE_B2B_6_119A_BW', $r['type']);
        self::assertSame(22, $r['dagen']);
        // 840000 × 0.1015 × 22 / 365 = 5138.96 → 5139 cents.
        self::assertEqualsWithDelta(51.39, $r['bedrag'], 0.01);

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
    public function testRenteB2CWettelijkeRenteOn820Eur31Days(): void
    {
        $r = $this->calc->rente(
            partyType: 'B2C',
            hoofdsom: 820.00,
            ingangsdatum: new DateTimeImmutable('2026-05-30'),
            berekendOp: new DateTimeImmutable('2026-06-30')
        );

        self::assertSame(0.04, $r['tarief']);
        self::assertSame('WETTELIJKE_RENTE_B2C_6_119_BW', $r['type']);
        self::assertSame(31, $r['dagen']);
        // 82000 × 0.04 × 31 / 365 = 278.6 → 279 cents.
        self::assertEqualsWithDelta(2.79, $r['bedrag'], 0.01);

    }//end testRenteB2CWettelijkeRenteOn820Eur31Days()

    /**
     * berekendOp before ingangsdatum is rejected.
     *
     * @return void
     */
    public function testRenteRejectsBerekendOpBeforeIngangsdatum(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calc->rente(
            partyType: 'B2B',
            hoofdsom: 1000,
            ingangsdatum: new DateTimeImmutable('2026-06-01'),
            berekendOp: new DateTimeImmutable('2026-05-01')
        );

    }//end testRenteRejectsBerekendOpBeforeIngangsdatum()

    /**
     * REQ-CCD-006: B2C calc is blocked before dag 44.
     *
     * @return void
     */
    public function testB2C_14DayGraceBlocksBeforeDay44(): void
    {
        self::assertFalse($this->calc->isCalculationPermitted(partyType: 'B2C', dagenVerzuim: 35));
        self::assertFalse($this->calc->isCalculationPermitted(partyType: 'B2C', dagenVerzuim: 43));
        self::assertTrue($this->calc->isCalculationPermitted(partyType: 'B2C', dagenVerzuim: 44));
        self::assertTrue($this->calc->isCalculationPermitted(partyType: 'B2C', dagenVerzuim: 60));
        // B2B has no grace — verzuim van rechtswege per art. 6:83 BW.
        self::assertTrue($this->calc->isCalculationPermitted(partyType: 'B2B', dagenVerzuim: 0));

    }//end testB2C_14DayGraceBlocksBeforeDay44()

    /**
     * compose() returns a record ready to persist on IncassoKostenBerekening.
     *
     * @return void
     */
    public function testComposeYieldsPersistenceShape(): void
    {
        $body = $this->calc->compose(
            factuurId: 'inv-2026-0247',
            administrationId: 'adm-1',
            partyType: 'B2B',
            hoofdsom: 8400.00,
            ingangsdatum: new DateTimeImmutable('2026-05-30'),
            berekendOp: new DateTimeImmutable('2026-06-21')
        );

        self::assertSame('inv-2026-0247', $body['factuurId']);
        self::assertSame('adm-1', $body['administrationId']);
        self::assertSame('B2B', $body['partyType']);
        self::assertSame(8400.0, $body['hoofdsom']);
        self::assertSame(795.0, $body['berekening']['toegepast']);
        self::assertSame('HANDELSRENTE_B2B_6_119A_BW', $body['wettelijkeRente']['type']);
        // 8400 + 795 (no BTW surcharge, creditor can offset) + 51.39 rente = 9246.39.
        self::assertEqualsWithDelta(9246.39, $body['totaalVerschuldigd'], 0.01);

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
    public function testStaffelMaximumCapAt6775(): void
    {
        $atCap = $this->calc->staffel(hoofdsom: 1000000.00);
        self::assertSame(6775.0, $atCap['maximum']);
        self::assertSame(6775.0, $atCap['toegepast']);
        // Raw staffel at exactly €1M lands on the cap: 375+250+250+1900+4000.
        self::assertSame(6775.0, $atCap['totaal']);

        $overCap = $this->calc->staffel(hoofdsom: 2000000.00);
        // Uncapped totaal is €11.775, but toegepast is clamped to €6.775.
        self::assertSame(11775.0, $overCap['totaal']);
        self::assertSame(6775.0, $overCap['toegepast']);

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
    public function testStaffelBtwSurchargeWhenNotDeductible(): void
    {
        $withBtw = $this->calc->staffel(hoofdsom: 8400.00, btwVerrekenbaar: false);
        self::assertSame(795.0, $withBtw['toegepast']);
        self::assertSame(0.21, $withBtw['btwPercentage']);
        // 79500 × 0.21 = 16695 cents.
        self::assertSame(166.95, $withBtw['btwBedrag']);
        self::assertSame(961.95, $withBtw['toegepastInclBtw']);

        $noBtw = $this->calc->staffel(hoofdsom: 8400.00);
        self::assertTrue($noBtw['btwVerrekenbaar']);
        self::assertSame(0.0, $noBtw['btwBedrag']);
        self::assertSame(795.0, $noBtw['toegepastInclBtw']);

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
    public function testRenteSplitsAcrossRateBoundary(): void
    {
        $r = $this->calc->rente(
            partyType: 'B2C',
            hoofdsom: 10000.00,
            ingangsdatum: new DateTimeImmutable('2025-12-17'),
            berekendOp: new DateTimeImmutable('2026-01-16')
        );

        self::assertSame(30, $r['dagen']);
        self::assertCount(2, $r['perioden']);
        self::assertSame(0.06, $r['perioden'][0]['tarief']);
        self::assertSame(0.04, $r['perioden'][1]['tarief']);
        self::assertEqualsWithDelta(24.66, $r['perioden'][0]['bedrag'], 0.01);
        self::assertEqualsWithDelta(16.44, $r['perioden'][1]['bedrag'], 0.01);
        self::assertEqualsWithDelta(41.10, $r['bedrag'], 0.01);
        // Headline tarief is the rate in force on berekendOp (4% in 2026).
        self::assertSame(0.04, $r['tarief']);

    }//end testRenteSplitsAcrossRateBoundary()

    /**
     * Explicit B2B override forces a flat rate (art. 6:119a lid 3 contractual).
     *
     * @return void
     */
    public function testRenteHonoursExplicitOverride(): void
    {
        $r = $this->calc->rente(
            partyType: 'B2B',
            hoofdsom: 8400.00,
            ingangsdatum: new DateTimeImmutable('2026-05-30'),
            berekendOp: new DateTimeImmutable('2026-06-21'),
            tariefB2B: 0.12
        );

        self::assertSame(0.12, $r['tarief']);
        self::assertCount(1, $r['perioden']);
        // 840000 × 0.12 × 22 / 365 = 6075.6 → 6076 cents.
        self::assertEqualsWithDelta(60.76, $r['bedrag'], 0.01);

    }//end testRenteHonoursExplicitOverride()

}//end class
