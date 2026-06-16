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
     * Spec scenario: B2B handelsrente per art. 6:119a BW.
     *
     * 8.400 × 0.115 × 22 / 365 ≈ €58.22; tarief 0.115; type
     * HANDELSRENTE_B2B_6_119A_BW. The spec example text mentions €58.13;
     * that example carries a small rounding slip — the correct calc is €58.22.
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

        self::assertSame(0.115, $r['tarief']);
        self::assertSame('HANDELSRENTE_B2B_6_119A_BW', $r['type']);
        self::assertSame(22, $r['dagen']);
        self::assertEqualsWithDelta(58.22, $r['bedrag'], 0.01);

    }//end testRenteB2BHandelsrenteOn8400Eur22Days()

    /**
     * Spec scenario: B2C wettelijke rente per art. 6:119 BW.
     *
     * 820 × 0.07 × 31 / 365 ≈ €4.88 (the spec example mentions €4.92,
     * a small rounding slip in the example text).
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

        self::assertSame(0.07, $r['tarief']);
        self::assertSame('WETTELIJKE_RENTE_B2C_6_119_BW', $r['type']);
        self::assertSame(31, $r['dagen']);
        self::assertEqualsWithDelta(4.88, $r['bedrag'], 0.01);

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
        // 8400 + 795 + ~58.22 = 9253.22 (within 0.01).
        self::assertEqualsWithDelta(9253.22, $body['totaalVerschuldigd'], 0.01);

    }//end testComposeYieldsPersistenceShape()

}//end class
