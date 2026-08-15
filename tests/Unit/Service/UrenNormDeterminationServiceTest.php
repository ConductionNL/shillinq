<?php

/**
 * Unit tests for UrenNormDeterminationService.
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
 * @spec openspec/specs/zzp-urencriterium-tracker/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Guard\UrencriteriumYearGuard;
use OCA\Shillinq\Service\UrenNormDeterminationService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Covers REQ-URC-000/006/007: norm determination wiring.
 */
final class UrenNormDeterminationServiceTest extends TestCase
{
    /**
     * Build a service with a real guard + null logger.
     *
     * @return UrenNormDeterminationService
     */
    private function build(): UrenNormDeterminationService
    {
        $logger = $this->createMock(LoggerInterface::class);
        $guard  = new UrencriteriumYearGuard(logger: $logger);
        return new UrenNormDeterminationService(guard: $guard, logger: $logger);

    }//end build()

    /**
     * A regular eenmanszaak with no loondienst yields the 1.225 norm seed.
     *
     * @return void
     */
    public function testRegularSeedYields1225(): void
    {
        $seed = $this->build()->bouwSeedRecord(
            profiel: [
                'administrationId' => 'adm-1',
                'enterpriseId'     => 'ond-1',
                'kalenderjaar'     => 2026,
            ]
        );

        self::assertSame(1225, $seed['doelNorm']);
        self::assertSame('art. 3.6 lid 1 Wet IB 2001', $seed['normBasis']);
        self::assertSame('NON_APPLICABLE', $seed['grotendeelsCriterium']);
        self::assertSame('ON_RATE', $seed['thresholdStatus']);
        self::assertSame(0.0, $seed['lopendeUren']);
        self::assertSame('adm-1', $seed['administrationId']);
        self::assertSame('ond-1', $seed['enterpriseId']);
        self::assertSame(2026, $seed['kalenderjaar']);

    }//end testRegularSeedYields1225()

    /**
     * AO-status drives the 800-uren norm and the lid-5 grondslag.
     *
     * @return void
     */
    public function testArbeidsongeschiktSeedYields800(): void
    {
        $seed = $this->build()->bouwSeedRecord(
            profiel: [
                'administrationId'  => 'adm-1',
                'enterpriseId'      => 'ond-2',
                'kalenderjaar'      => 2026,
                'arbeidsongeschikt' => true,
            ]
        );

        self::assertSame(800, $seed['doelNorm']);
        self::assertSame('art. 3.6 lid 5 Wet IB 2001', $seed['normBasis']);

    }//end testArbeidsongeschiktSeedYields800()

    /**
     * Meewerkende partner drives the 525-uren meewerkaftrek seed.
     *
     * @return void
     */
    public function testMeewerkendePartnerSeedYields525(): void
    {
        $seed = $this->build()->bouwSeedRecord(
            profiel: [
                'administrationId'   => 'adm-1',
                'enterpriseId'       => 'ond-3',
                'kalenderjaar'       => 2026,
                'meewerkendePartner' => true,
            ]
        );

        self::assertSame(525, $seed['doelNorm']);

    }//end testMeewerkendePartnerSeedYields525()

    /**
     * Parallel loondienst >50% flags NIET_GROTENDEELS_ONDERNEMING.
     *
     * @return void
     */
    public function testParallelLoondienstMajorityFlagsNietGrotendeels(): void
    {
        $seed = $this->build()->bouwSeedRecord(
            profiel: [
                'administrationId'    => 'adm-1',
                'enterpriseId'        => 'ond-4',
                'kalenderjaar'        => 2026,
                'ondernemingsUrenJTD' => 300.0,
                'loondienstUrenJTD'   => 600.0,
            ]
        );

        self::assertSame('NON_LARGELY_ENTERPRISE', $seed['grotendeelsCriterium']);

    }//end testParallelLoondienstMajorityFlagsNietGrotendeels()

    /**
     * Parallel loondienst <50% yields GROTENDEELS_ONDERNEMING.
     *
     * @return void
     */
    public function testParallelLoondienstMinorityFlagsGrotendeels(): void
    {
        $seed = $this->build()->bouwSeedRecord(
            profiel: [
                'administrationId'    => 'adm-1',
                'enterpriseId'        => 'ond-5',
                'kalenderjaar'        => 2026,
                'ondernemingsUrenJTD' => 800.0,
                'loondienstUrenJTD'   => 200.0,
            ]
        );

        self::assertSame('LARGELY_ENTERPRISE', $seed['grotendeelsCriterium']);

    }//end testParallelLoondienstMinorityFlagsGrotendeels()

    /**
     * The seed passes the canonical YearGuard validateOnSave by construction.
     *
     * @return void
     */
    public function testSeedPassesGuardValidateOnSave(): void
    {
        $logger  = $this->createMock(LoggerInterface::class);
        $guard   = new UrencriteriumYearGuard(logger: $logger);
        $service = new UrenNormDeterminationService(guard: $guard, logger: $logger);

        $seed = $service->bouwSeedRecord(
            profiel: [
                'administrationId'  => 'adm-1',
                'enterpriseId'      => 'ond-9',
                'kalenderjaar'      => 2026,
                'arbeidsongeschikt' => true,
            ]
        );

        self::assertTrue($guard->validateOnSave(year: $seed));

    }//end testSeedPassesGuardValidateOnSave()
}//end class
