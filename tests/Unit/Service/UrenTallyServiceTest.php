<?php

/**
 * Unit tests for UrenTallyService.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-11
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Guard\UrenDagregistratieGuard;
use OCA\Shillinq\Service\UrenTallyService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers REQ-URC-001: daily tally with reistijd-cap + YTD tally + idempotency.
 */
final class UrenTallyServiceTest extends TestCase
{


    /**
     * Build a service with real guard.
     *
     * @return UrenTallyService
     */
    private function build(): UrenTallyService
    {
        $logger = $this->createMock(LoggerInterface::class);
        $guard  = new UrenDagregistratieGuard(logger: $logger);
        return new UrenTallyService(guard: $guard, logger: $logger);

    }//end build()


    /**
     * Day tally sums entries and applies the reistijd-cap.
     *
     * @return void
     */
    public function testTallyDagAppliesReistijdCap(): void
    {
        $result = $this->build()->tallyDag(
            entries: [
                ['categorie' => 'BILLABLE_KLANTWERK', 'uren' => 6],
                ['categorie' => 'REISTIJD_ZAKELIJK', 'uren' => 8],
                ['categorie' => 'ADMINISTRATIE', 'uren' => 1.5],
            ]
        );

        self::assertSame(11.5, $result['totaalUren']);
        self::assertSame(4.0, $result['perCategorie']['REISTIJD_ZAKELIJK']);
        self::assertCount(1, $result['overages']);
        self::assertSame('REISTIJD_ZAKELIJK', $result['overages'][0]['categorie']);

    }//end testTallyDagAppliesReistijdCap()


    /**
     * Tally is idempotent: calling it twice yields the same total.
     *
     * @return void
     */
    public function testTallyDagIsIdempotent(): void
    {
        $service = $this->build();
        $entries = [
            ['categorie' => 'BILLABLE_KLANTWERK', 'uren' => 6],
            ['categorie' => 'REISTIJD_ZAKELIJK', 'uren' => 5],
        ];

        $first  = $service->tallyDag(entries: $entries);
        $second = $service->tallyDag(entries: $entries);

        self::assertSame($first['totaalUren'], $second['totaalUren']);
        self::assertSame($first['perCategorie'], $second['perCategorie']);

    }//end testTallyDagIsIdempotent()


    /**
     * Empty input yields zero with no overages.
     *
     * @return void
     */
    public function testTallyDagEmptyYieldsZero(): void
    {
        $result = $this->build()->tallyDag(entries: []);
        self::assertSame(0.0, $result['totaalUren']);
        self::assertSame([], $result['perCategorie']);
        self::assertSame([], $result['overages']);

    }//end testTallyDagEmptyYieldsZero()


    /**
     * Garbage entries are skipped, not fatal.
     *
     * @return void
     */
    public function testTallyDagSkipsGarbage(): void
    {
        $result = $this->build()->tallyDag(
            entries: [
                ['categorie' => 'BILLABLE_KLANTWERK', 'uren' => 4],
                'garbage',
                ['uren' => 2],
                ['categorie' => '', 'uren' => 99],
                ['categorie' => 'ACQUISITIE', 'uren' => 2],
            ]
        );

        self::assertSame(6.0, $result['totaalUren']);

    }//end testTallyDagSkipsGarbage()


    /**
     * YTD tally returns the canonical UrencriteriumYear patch shape.
     *
     * @return void
     */
    public function testTallyYearToDateReturnsPatch(): void
    {
        $patch = $this->build()->tallyYearToDate(
            entries: [
                ['categorie' => 'BILLABLE_KLANTWERK', 'uren' => 800],
                ['categorie' => 'ACQUISITIE', 'uren' => 100],
                ['categorie' => 'REISTIJD_ZAKELIJK', 'uren' => 6],
            ],
            now: '2026-09-30T23:00:00Z'
        );

        self::assertSame(904.0, $patch['lopendeUren']);
        self::assertSame('2026-09-30T23:00:00Z', $patch['berekendOp']);

    }//end testTallyYearToDateReturnsPatch()


}//end class
