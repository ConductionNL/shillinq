<?php

/**
 * Unit tests for KorThresholdCalculator.
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
 * @spec openspec/changes/bookkeeping-kor-kleine-ondernemersregeling/specs.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\KorThresholdCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the pure KOR fiscal arithmetic: running omzet, benutting, prognose,
 * alert-schijf crossing, suppletie-bedrag (REQ-KOR-004), three-year blokkade,
 * and herzieningsregels recovery (REQ-KOR-006/011).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class KorThresholdCalculatorTest extends TestCase
{

    /**
     * Subject under test.
     *
     * @var KorThresholdCalculator
     */
    private KorThresholdCalculator $calc;

    /**
     * Set up the calculator.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->calc = new KorThresholdCalculator();

    }//end setUp()

    /**
     * Only KOR-eligible, non-draft invoices in the year count (REQ-KOR-002).
     *
     * @return void
     */
    public function testRunningOmzetOnlyCountsKorEligible(): void
    {
        $invoices = [
            ['bedrag' => 200.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'status' => 'issued', 'leveringsDatum' => '2026-08-12'],
            ['bedrag' => 999.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'status' => 'draft', 'leveringsDatum' => '2026-08-13'],
            ['bedrag' => 500.0, 'vrijstellingsGrondslag' => 'REGULIER_21PCT_VAT', 'status' => 'issued', 'leveringsDatum' => '2026-08-14'],
            ['bedrag' => 100.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'status' => 'issued', 'leveringsDatum' => '2025-12-31'],
            ['bedrag' => 50.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'status' => 'issued', 'leveringsDatum' => '2026-01-05'],
        ];

        // 200 + 50 = 250.00 => 25000 cents (draft excluded, regulier excluded, prior-year excluded).
        self::assertSame(25000, $this->calc->runningOmzetCents(invoices: $invoices, year: 2026));

    }//end testRunningOmzetOnlyCountsKorEligible()

    /**
     * Benutting is omzet / drempel, with zero-threshold guard (REQ-KOR-002).
     *
     * @return void
     */
    public function testBenutting(): void
    {
        // 16.620 / 20.000 = 0.831.
        self::assertEqualsWithDelta(0.831, $this->calc->benutting(omzetCents: 1662000, drempelCents: 2000000), 0.0001);
        self::assertSame(0.0, $this->calc->benutting(omzetCents: 100, drempelCents: 0));

    }//end testBenutting()

    /**
     * Each schijf fires exactly once on crossing, never on a static benutting (REQ-KOR-003).
     *
     * @return void
     */
    public function testCrossedSchijf(): void
    {
        // Crossing 80% from below.
        self::assertSame('DREMPEL_80PCT', $this->calc->crossedSchijf(previousBenutting: 0.79, newBenutting: 0.83)['trigger']);
        // Crossing 90% from below.
        self::assertSame('DREMPEL_90PCT', $this->calc->crossedSchijf(previousBenutting: 0.85, newBenutting: 0.906)['trigger']);
        // Crossing 100% from below => OVERSCHRIJDING.
        $hit = $this->calc->crossedSchijf(previousBenutting: 0.95, newBenutting: 1.018);
        self::assertSame('DREMPEL_100PCT', $hit['trigger']);
        self::assertSame('OVERSCHRIJDING', $hit['ernst']);
        // No new schijf when staying within the same band.
        self::assertNull($this->calc->crossedSchijf(previousBenutting: 0.82, newBenutting: 0.84));

    }//end testCrossedSchijf()

    /**
     * The end-of-year prognose projects the monthly average forward (REQ-KOR-002).
     *
     * @return void
     */
    public function testPrognoseEndOfYear(): void
    {
        // 16.420 over 8 months => avg 2052.50/mo => + 4 remaining months.
        // 1642000 cents, month 8 => avg = 205250, remaining 4 => 1642000 + 821000 = 2463000.
        self::assertSame(2463000, $this->calc->prognoseEndOfYearCents(lopendeCents: 1642000, currentMonth: 8));

    }//end testPrognoseEndOfYear()

    /**
     * Prognose-status escalates with projected benutting (REQ-KOR-002).
     *
     * @return void
     */
    public function testPrognoseStatus(): void
    {
        self::assertSame('ONDER_DREMPEL', $this->calc->prognoseStatus(prognoseCents: 1500000, drempelCents: 2000000));
        self::assertSame('WAARSCHUWING', $this->calc->prognoseStatus(prognoseCents: 1700000, drempelCents: 2000000));
        self::assertSame('OVERSCHRIJDING_VERWACHT', $this->calc->prognoseStatus(prognoseCents: 2463000, drempelCents: 2000000));

    }//end testPrognoseStatus()

    /**
     * Suppletie sums the embedded VAT of KOR-facturen before the revocatie-datum (REQ-KOR-004).
     *
     * The revocatie-datum itself is exclusive (facturen on/after it are already re-marked).
     *
     * @return void
     */
    public function testSuppletieBedrag(): void
    {
        $invoices = [
            // In window: 1210 gross => embedded VAT 1210 * 0.21 / 1.21 = 210.00 => 21000 cents.
            ['bedrag' => 1210.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'leveringsDatum' => '2026-03-01'],
            // In window: 605 gross => embedded VAT 105.00 => 10500 cents.
            ['bedrag' => 605.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'leveringsDatum' => '2026-06-15'],
            // On the revocatie-datum => excluded (already re-marked).
            ['bedrag' => 1000.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'leveringsDatum' => '2026-09-04'],
            // Before ingangsDatum => excluded.
            ['bedrag' => 500.0, 'vrijstellingsGrondslag' => 'KOR_ART25_OB', 'leveringsDatum' => '2025-12-31'],
            // Not KOR => excluded.
            ['bedrag' => 1210.0, 'vrijstellingsGrondslag' => 'REGULIER_21PCT_VAT', 'leveringsDatum' => '2026-04-01'],
        ];

        $cents = $this->calc->suppletieBedragCents(
            invoices: $invoices,
            ingangsDatum: '2026-01-01',
            revocatieDatum: '2026-09-04'
        );

        // 21000 + 10500 = 31500 cents = EUR 315.00.
        self::assertSame(31500, $cents);

    }//end testSuppletieBedrag()

    /**
     * The heraanmelding-blokkade is exactly revocatieDatum + 3 years (REQ-KOR-004).
     *
     * @return void
     */
    public function testPlusThreeYears(): void
    {
        self::assertSame('2029-09-04', $this->calc->plusThreeYears(date: '2026-09-04'));
        self::assertSame('', $this->calc->plusThreeYears(date: 'not-a-date'));

    }//end testPlusThreeYears()

    /**
     * Herzieningsregels recover voorbelasting proportional to remaining life (REQ-KOR-006/011).
     *
     * @return void
     */
    public function testHerzieningRecovery(): void
    {
        // EUR 139 VAT (13900 cents), 57 of 60 months remaining => 13900 * 57/60 = 13205.
        self::assertSame(13205, $this->calc->herzieningRecoveryCents(vatCents: 13900, remainingMonths: 57, totalMonths: 60));
        // Clamp: remaining > total => full recovery.
        self::assertSame(13900, $this->calc->herzieningRecoveryCents(vatCents: 13900, remainingMonths: 99, totalMonths: 60));
        // Zero total months => zero recovery (no divide-by-zero).
        self::assertSame(0, $this->calc->herzieningRecoveryCents(vatCents: 13900, remainingMonths: 10, totalMonths: 0));

    }//end testHerzieningRecovery()

    // phpcs:enable CustomSniffs.Functions.NamedParameters
}//end class
