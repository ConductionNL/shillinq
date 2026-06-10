<?php

/**
 * Unit tests for ENSIAQuestionSetLoader.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/bookkeeping-ensia-zelfevaluatie/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\ENSIAQuestionSetLoader;
use PHPUnit\Framework\TestCase;

/**
 * Tests REQ-ENSIA-001 ENSIA cycle initialisation with VNG question-set
 * loader.
 */
class ENSIAQuestionSetLoaderTest extends TestCase
{

    private string $seedPath;

    private ENSIAQuestionSetLoader $loader;


    protected function setUp(): void
    {
        parent::setUp();

        $this->seedPath = sys_get_temp_dir() . '/ensia-vng-test-' . uniqid() . '.json';
        $payload        = [
            'vraagSetVersion' => 'BIO-1.04-2026',
            'vragen'          => [
                [
                    'domein'       => 'BIO',
                    'onderwerp'    => 'Toegangsbeveiliging',
                    'vraagCode'    => 'BIO-9.1.1',
                    'vraagtekst'   => 'Is er een formeel beleid?',
                    'antwoordType' => 'volwassenheidsniveau-1-5',
                    'normniveau'   => 3,
                ],
                [
                    'domein'       => 'DigiD',
                    'onderwerp'    => 'Authenticatie',
                    'vraagCode'    => 'DigiD-1.1',
                    'vraagtekst'   => 'Wordt DigiD-koppeling jaarlijks getoetst?',
                    'antwoordType' => 'ja-nee-nvt',
                ],
                [
                    'domein'       => 'SUWI',
                    'onderwerp'    => 'Toegang',
                    'vraagCode'    => 'SUWI-1.1',
                    'vraagtekst'   => 'Voldoet SUWInet aan NORA?',
                    'antwoordType' => 'ja-nee-nvt',
                ],
            ],
        ];
        file_put_contents($this->seedPath, json_encode($payload));

        $this->loader = new ENSIAQuestionSetLoader($this->seedPath);

    }//end setUp()


    protected function tearDown(): void
    {
        if (file_exists($this->seedPath) === true) {
            unlink($this->seedPath);
        }

        parent::tearDown();

    }//end tearDown()


    /**
     * REQ-ENSIA-001: load filters by selected verantwoordingsdomeinen.
     *
     * @return void
     */
    public function testLoadFiltersByDomeinen(): void
    {
        $result = $this->loader->load(2026, ['BIO', 'DigiD'], 'cyc-1', 'adm-1');

        $this->assertSame('BIO-1.04-2026', $result['vraagSetVersion']);
        $this->assertCount(2, $result['vragen']);

        $codes = array_map(static fn(array $v) => $v['vraagCode'], $result['vragen']);
        $this->assertContains('BIO-9.1.1', $codes);
        $this->assertContains('DigiD-1.1', $codes);
        $this->assertNotContains('SUWI-1.1', $codes);

    }//end testLoadFiltersByDomeinen()


    /**
     * REQ-ENSIA-001: each generated vraag carries cyclusId + administrationId.
     *
     * @return void
     */
    public function testLoadStampsCyclusAndAdministration(): void
    {
        $result = $this->loader->load(2026, ['BIO'], 'cyc-1', 'adm-1');
        $this->assertNotEmpty($result['vragen']);
        $this->assertSame('cyc-1', $result['vragen'][0]['cyclusId']);
        $this->assertSame('adm-1', $result['vragen'][0]['administrationId']);

    }//end testLoadStampsCyclusAndAdministration()


    /**
     * REQ-ENSIA-001: generated vragen carry the initial peerReviewStatus.
     *
     * @return void
     */
    public function testLoadInitialisesPeerReviewStatus(): void
    {
        $result = $this->loader->load(2026, ['BIO'], 'cyc-1', 'adm-1');
        foreach ($result['vragen'] as $v) {
            $this->assertSame('nog-niet-beoordeeld', $v['peerReviewStatus']);
            $this->assertSame([], $v['bewijsstukken']);
        }

    }//end testLoadInitialisesPeerReviewStatus()


    /**
     * REQ-ENSIA-001 second scenario: vraagSetVersion is recorded so external
     * auditors can trace which question set was used.
     *
     * @return void
     */
    public function testLoadRecordsQuestionSetVersionForAuditTrail(): void
    {
        $result = $this->loader->load(2026, ['BIO'], 'cyc-1', 'adm-1');
        $this->assertSame('BIO-1.04-2026', $result['vraagSetVersion']);

    }//end testLoadRecordsQuestionSetVersionForAuditTrail()


    /**
     * Missing seed file → empty result with vraagSetVersion=unknown.
     *
     * @return void
     */
    public function testLoadFailsSafeWhenSeedMissing(): void
    {
        $loader = new ENSIAQuestionSetLoader('/nonexistent/path/to/missing.json');
        $result = $loader->load(2026, ['BIO'], 'cyc-1', 'adm-1');
        $this->assertSame('unknown', $result['vraagSetVersion']);
        $this->assertSame([], $result['vragen']);

    }//end testLoadFailsSafeWhenSeedMissing()


    /**
     * REQ-ENSIA-001: empty domeinen selection yields no vragen.
     *
     * @return void
     */
    public function testLoadReturnsNoVragenForEmptyDomeinSelection(): void
    {
        $result = $this->loader->load(2026, [], 'cyc-1', 'adm-1');
        $this->assertSame([], $result['vragen']);

    }//end testLoadReturnsNoVragenForEmptyDomeinSelection()


}//end class
