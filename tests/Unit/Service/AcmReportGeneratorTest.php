<?php

/**
 * Unit tests for AcmReportGenerator.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-7
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Shillinq\Service\AcmReportGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Tests the ACM report generator (REQ-WMO-006).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class AcmReportGeneratorTest extends TestCase
{

    /**
     * The service under test.
     */
    private AcmReportGenerator $svc;

    /**
     * Set up test fixtures.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AcmReportGenerator();

    }//end setUp()

    /**
     * Compose aggregates per-activity lines with ratio + compliance.
     */
    public function testComposeAggregatesActivities(): void
    {
        $report = $this->svc->compose([
            'period'           => '2026-Q1',
            'administrationId' => 'adm-tilburg',
            'activities'       => [
                ['id' => 'ca-001', 'code' => 'MO-SP-014', 'naam' => 'Dansschool', 'isExempted' => false],
                ['id' => 'ca-002', 'code' => 'MO-SP-016', 'naam' => 'Kantine', 'isExempted' => true, 'exemptionBesluitId' => 'abb-001'],
            ],
            'ikpRecords'       => [
                'ca-001' => ['totaleKosten' => 87_500.00],
                'ca-002' => ['totaleKosten' => 56_000.00],
            ],
            'omzetByActivity'  => [
                'ca-001' => 92_000.00,
                'ca-002' => 50_000.00,
            ],
        ]);

        self::assertSame(AcmReportGenerator::FORMAT, $report['format']);
        self::assertCount(2, $report['activiteiten']);
        self::assertTrue($report['activiteiten'][0]['compliant']);
        self::assertFalse($report['activiteiten'][1]['compliant']);
        self::assertSame('abb-001', $report['activiteiten'][1]['abbReferentie']);
        self::assertSame('draft', $report['status']);

    }//end testComposeAggregatesActivities()

    /**
     * Invalid period format raises.
     */
    public function testComposeRejectsInvalidPeriod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->compose([
            'period'           => '2026-03',
            'administrationId' => 'adm',
            'activities'       => [],
            'ikpRecords'       => [],
            'omzetByActivity'  => [],
        ]);

    }//end testComposeRejectsInvalidPeriod()

    /**
     * Submit flips ready-for-submission to verzonden.
     */
    public function testSubmitFlipsToVerzonden(): void
    {
        $report   = ['status' => 'ready-for-submission'];
        $verzond  = $this->svc->submit($report, 'gmb-2026-001');
        self::assertTrue($verzond['verzondenAanAcm']);
        self::assertSame('verzonden', $verzond['status']);
        self::assertSame('gmb-2026-001', $verzond['publicatieGemeenteblad']);

    }//end testSubmitFlipsToVerzonden()

    /**
     * reconcileOmzet checks per-activity sum vs ledger total within tolerance.
     */
    public function testReconcileOmzet(): void
    {
        $report = ['activiteiten' => [['omzet' => 92_000.00], ['omzet' => 50_000.00]]];
        self::assertTrue($this->svc->reconcileOmzet($report, 142_000.00));
        self::assertFalse($this->svc->reconcileOmzet($report, 145_000.00));

    }//end testReconcileOmzet()

    /**
     * toJson + toXml serialize without throwing.
     */
    public function testJsonAndXmlSerialize(): void
    {
        $report = $this->svc->compose([
            'period'           => '2026-Q1',
            'administrationId' => 'adm-tilburg',
            'activities'       => [['id' => 'ca-001', 'code' => 'MO-SP-014', 'naam' => 'X', 'isExempted' => false]],
            'ikpRecords'       => ['ca-001' => ['totaleKosten' => 1.0]],
            'omzetByActivity'  => ['ca-001' => 2.0],
        ]);

        $json = $this->svc->toJson($report);
        self::assertJson($json);

        $xml = $this->svc->toXml($report);
        self::assertStringContainsString('<ACMReport', $xml);
        self::assertStringContainsString('code="MO-SP-014"', $xml);

    }//end testJsonAndXmlSerialize()

}//end class
