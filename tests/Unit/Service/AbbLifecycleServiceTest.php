<?php

/**
 * Unit tests for AbbLifecycleService.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\AbbLifecycleService;
use PHPUnit\Framework\TestCase;

/**
 * Tests ABB state-machine transitions and task generation (REQ-WMO-005).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AbbLifecycleServiceTest extends TestCase
{

    /**
     * The service under test.
     */
    private AbbLifecycleService $svc;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AbbLifecycleService();

    }//end setUp()

    /**
     * Invalid transition is rejected.
     */
    public function testCanTransitionRejectsInvalidPath(): void
    {
        $abb = ['status' => 'concept', 'kenmerk' => 'R-2025-184'];
        $r   = $this->svc->canTransition('concept', 'publicatie', $abb);
        self::assertFalse($r['ok']);

    }//end testCanTransitionRejectsInvalidPath()

    /**
     * raadsbesluit requires kenmerk.
     */
    public function testRaadsbesluitRequiresKenmerk(): void
    {
        $abb = ['status' => 'raadsvoorstel', 'kenmerk' => ''];
        $r   = $this->svc->canTransition('raadsvoorstel', 'raadsbesluit', $abb);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('kenmerk', $r['error']);

    }//end testRaadsbesluitRequiresKenmerk()

    /**
     * publicatie requires publicatieGemeenteblad + datum.
     */
    public function testPublicatieRequiresGemeentebladAndDate(): void
    {
        $abb = ['status' => 'raadsbesluit', 'kenmerk' => 'R-2025-184'];
        $r   = $this->svc->canTransition('raadsbesluit', 'publicatie', $abb);
        self::assertFalse($r['ok']);

        $abb['publicatieGemeenteblad'] = 'gmb-2025-401';
        $abb['publicatieDatum']        = '2025-12-01';
        $r2 = $this->svc->canTransition('raadsbesluit', 'publicatie', $abb);
        self::assertTrue($r2['ok']);

    }//end testPublicatieRequiresGemeentebladAndDate()

    /**
     * geldig requires publicatieDatum + ACM kenmerk.
     */
    public function testGeldigRequiresAcmKenmerk(): void
    {
        $abb = [
            'status'          => 'bezwaar',
            'publicatieDatum' => '2025-12-01',
            'kennisgevingAcm' => ['ingediend' => true, 'kenmerk' => 'ACM/IN/123'],
        ];
        $r = $this->svc->canTransition('bezwaar', 'geldig', $abb);
        self::assertTrue($r['ok']);

        $abb['kennisgevingAcm']['kenmerk'] = '';
        $r2 = $this->svc->canTransition('bezwaar', 'geldig', $abb);
        self::assertFalse($r2['ok']);

    }//end testGeldigRequiresAcmKenmerk()

    /**
     * transition raises InvalidArgumentException on disallowed transition.
     */
    public function testTransitionThrowsOnInvalidPath(): void
    {
        $abb = ['status' => 'concept'];
        $this->expectException(InvalidArgumentException::class);
        $this->svc->transition($abb, 'geldig');

    }//end testTransitionThrowsOnInvalidPath()

    /**
     * raadsbesluit generates a publish-gemeenteblad task with +14d due date.
     */
    public function testTransitionToRaadsbesluitGeneratesPublishTask(): void
    {
        $abb = ['status' => 'raadsvoorstel', 'kenmerk' => 'R-2025-184'];
        $out = $this->svc->transition($abb, 'raadsbesluit');
        self::assertSame('raadsbesluit', $out['abb']['status']);
        self::assertCount(1, $out['tasks']);
        self::assertSame('publish-gemeenteblad', $out['tasks'][0]['type']);
        self::assertSame('griffier', $out['tasks'][0]['assignedTo']);

    }//end testTransitionToRaadsbesluitGeneratesPublishTask()

    /**
     * publicatie generates a notify-ACM task with +7d due date.
     */
    public function testTransitionToPublicatieGeneratesNotifyAcmTask(): void
    {
        $abb = [
            'status'                 => 'raadsbesluit',
            'kenmerk'                => 'R-2025-184',
            'publicatieGemeenteblad' => 'gmb-2025-401',
            'publicatieDatum'        => '2025-12-01',
        ];
        $out = $this->svc->transition($abb, 'publicatie');
        self::assertCount(1, $out['tasks']);
        self::assertSame('notify-acm', $out['tasks'][0]['type']);

    }//end testTransitionToPublicatieGeneratesNotifyAcmTask()

    /**
     * Geldig sets volgendeEvaluatie based on vaststellingsdatum + ritme.
     */
    public function testGeldigCalculatesNextEvaluation(): void
    {
        $abb = [
            'status'             => 'bezwaar',
            'publicatieDatum'    => '2025-12-01',
            'vaststellingsdatum' => '2025-11-15',
            'evaluatieRitme'    => 'tweejaarlijks',
            'kennisgevingAcm'    => ['ingediend' => true, 'kenmerk' => 'ACM/IN/123'],
        ];
        $out = $this->svc->transition($abb, 'geldig');
        self::assertSame('2027-11-15', $out['abb']['volgendeEvaluatie']);

    }//end testGeldigCalculatesNextEvaluation()

    /**
     * flagDependentActivities only fires for herziening / intrekking.
     */
    public function testFlagDependentActivitiesOnlyForHerzieningIntrekking(): void
    {
        $abb        = ['status' => 'geldig', 'kenmerk' => 'R-2025-184'];
        $activities = [['id' => 'ca-001'], ['id' => 'ca-002']];

        self::assertSame([], $this->svc->flagDependentActivities($activities, $abb));

        $abb['status'] = 'intrekking';
        $flags         = $this->svc->flagDependentActivities($activities, $abb);
        self::assertCount(2, $flags);
        self::assertStringContainsString('ingetrokken', $flags[0]['reason']);

    }//end testFlagDependentActivitiesOnlyForHerzieningIntrekking()

}//end class
