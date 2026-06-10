<?php

/**
 * Unit tests for GrotendeelsCriteriumService.
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
 * @spec openspec/changes/zzp-urencriterium-tracker/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Guard\UrencriteriumYearGuard;
use OCA\Shillinq\Service\GrotendeelsCriteriumService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers REQ-URC-007: grotendeels-criterium daily evaluation.
 */
final class GrotendeelsCriteriumServiceTest extends TestCase
{


    /**
     * Build a service with a real guard.
     *
     * @return GrotendeelsCriteriumService
     */
    private function build(): GrotendeelsCriteriumService
    {
        $logger = $this->createMock(LoggerInterface::class);
        $guard  = new UrencriteriumYearGuard(logger: $logger);
        return new GrotendeelsCriteriumService(guard: $guard, logger: $logger);

    }//end build()


    /**
     * telOndernemingsUren sums getoldeUren (preferring it over uren).
     *
     * @return void
     */
    public function testTelOndernemingsUrenPrefersGetoldeUren(): void
    {
        $service = $this->build();
        $totaal  = $service->telOndernemingsUren(
            dagregistraties: [
                ['uren' => 8, 'getoldeUren' => 8],
                ['uren' => 6, 'getoldeUren' => 4],
                ['uren' => 2],
            ]
        );

        self::assertSame(14.0, $totaal);

    }//end testTelOndernemingsUrenPrefersGetoldeUren()


    /**
     * telOndernemingsUren tolerates non-array entries (graceful for streaming feeds).
     *
     * @return void
     */
    public function testTelOndernemingsUrenIgnoresNonArrayEntries(): void
    {
        $service = $this->build();
        $totaal  = $service->telOndernemingsUren(
            dagregistraties: [
                ['uren' => 8],
                'garbage',
                123,
                ['uren' => 4, 'getoldeUren' => 4],
            ]
        );

        self::assertSame(12.0, $totaal);

    }//end testTelOndernemingsUrenIgnoresNonArrayEntries()


    /**
     * No loondienst yields NIET_TOEPASSELIJK regardless of onderneming hours.
     *
     * @return void
     */
    public function testNoLoondienstYieldsNietToepasselijk(): void
    {
        $patch = $this->build()->bouwPatch(
            dagregistraties: [['uren' => 800]],
            loondienstUren: 0.0
        );

        self::assertSame('NIET_TOEPASSELIJK', $patch['grotendeelsCriterium']);
        self::assertFalse($patch['blokkeertZelfstandigenaftrek']);

    }//end testNoLoondienstYieldsNietToepasselijk()


    /**
     * Onderneming >50% yields GROTENDEELS_ONDERNEMING (does not block aftrek).
     *
     * @return void
     */
    public function testGrotendeelsOndernemingDoesNotBlockAftrek(): void
    {
        $patch = $this->build()->bouwPatch(
            dagregistraties: [['uren' => 1200]],
            loondienstUren: 800.0
        );

        self::assertSame('GROTENDEELS_ONDERNEMING', $patch['grotendeelsCriterium']);
        self::assertFalse($patch['blokkeertZelfstandigenaftrek']);

    }//end testGrotendeelsOndernemingDoesNotBlockAftrek()


    /**
     * Loondienst-majority blocks the zelfstandigenaftrek (REQ-URC-007).
     *
     * @return void
     */
    public function testLoondienstMajorityBlocksAftrek(): void
    {
        $patch = $this->build()->bouwPatch(
            dagregistraties: [['uren' => 400]],
            loondienstUren: 1200.0
        );

        self::assertSame('NIET_GROTENDEELS_ONDERNEMING', $patch['grotendeelsCriterium']);
        self::assertTrue($patch['blokkeertZelfstandigenaftrek']);

    }//end testLoondienstMajorityBlocksAftrek()


    /**
     * Equal onderneming + loondienst hours (50/50) is NOT grotendeels (>50 required).
     *
     * @return void
     */
    public function testFiftyFiftyIsNietGrotendeels(): void
    {
        $patch = $this->build()->bouwPatch(
            dagregistraties: [['uren' => 800]],
            loondienstUren: 800.0
        );

        self::assertSame('NIET_GROTENDEELS_ONDERNEMING', $patch['grotendeelsCriterium']);

    }//end testFiftyFiftyIsNietGrotendeels()


}//end class
