<?php

/**
 * Lean integration test for the innovatiebox pipeline.
 *
 * Wires NexusCalculationService + ProfitAttributionService +
 * CarryForwardLossService + InnovatieboxSbrExportService end-to-end against a
 * worked-example dataset (the afpelmethode scenario from the spec) and the
 * forfaitair-cap scenario. Verifies the pipeline arithmetic agrees with the
 * spec numbers BEFORE the OR aggregation builder layers in.
 *
 * Pure-logic — no OpenRegister dependency exercised. Task 10.5 marker (the
 * full live-instance integration runs under composer test / phpunit.xml).
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
 * @spec openspec/changes/bookkeeping-innovatiebox-administratie/tasks.md#task-10-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Service;

use OCA\Shillinq\Service\CarryForwardLossService;
use OCA\Shillinq\Service\InnovatieboxSbrExportService;
use OCA\Shillinq\Service\NexusCalculationService;
use OCA\Shillinq\Service\ProfitAttributionService;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end innovatiebox pipeline arithmetic (task 10.5).
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */
final class InnovatieboxIntegrationTest extends TestCase
{

    /**
     * Afpelmethode worked example end-to-end: nexus -> profit attribution ->
     * loss carry-forward -> SBR export. Verifies the four service classes
     * agree on the same Vpb-aangifte regel 23 numbers (REQ-IBA-006/007).
     *
     * Scenario (from spec.md):
     *  - eigen R&D EUR 480k, derden EUR 120k, verbonden EUR 80k -> nexus 100%.
     *  - bruto opbrengst EUR 2.4M, directe kosten EUR 850k, routines EUR 750k
     *    -> kwalificerende winst voor nexus EUR 800k.
     *  - met nexus 100%, tariff 0.09 -> Vpb-impact EUR 72k.
     *  - open verlies 2023 EUR 215k -> eerst @ standaardtarief, rest @ 9%.
     *  - Total benefit ~EUR 108.15k.
     *
     * @return void
     */
    public function testAfpelmethodeWorkedExamplePipeline(): void
    {
        $nexus  = new NexusCalculationService();
        $profit = new ProfitAttributionService();
        $loss   = new CarryForwardLossService();
        $export = new InnovatieboxSbrExportService();

        $nexusResult = $nexus->calculateNexusBreak(
            eigenRdKosten: 480000.0,
            uitbesteedDerden: 120000.0,
            uitbesteedVerbonden: 80000.0
        );
        $this->assertSame(1.0, $nexusResult['nexusbreukToegepast'], 'Nexus caps at 100% in this scenario.');

        $profitResult = $profit->calculateKwalificerendeWinst(
            methode: 'per_asset_afpelmethode',
            brutoOpbrengst: 2400000.0,
            directeKosten: 850000.0,
            routineWinst: 750000.0,
            nexusBreak: (float) $nexusResult['nexusbreukToegepast']
        );
        $this->assertSame(800000.0, $profitResult['kwalificerendeWinstVoorNexus']);
        $this->assertSame(800000.0, $profitResult['kwalificerendeWinstNaNexus']);
        $this->assertSame(72000.0, $profitResult['vpbOpInnovatiedeel']);

        // Loss carry-forward EUR 215k against the EUR 800k qualifying profit.
        $lossResult = $loss->offsetLossAgainstProfit(
            openLoss: 215000.0,
            currentYearProfit: 800000.0,
            nexusBreak: 1.0,
            fullTariff: 0.258
        );
        // Per the existing CarryForwardLossServiceTest fixture: total benefit
        // EUR 108 120 (55 470 loss-recovery + 52 650 IB on residual).
        $this->assertEqualsWithDelta(108120.0, $lossResult['totalBenefit'], 1.0);

        // Now feed the same numbers through the SBR export.
        $aggregation = [
            'data' => [
                [
                    'qualifying_asset_id' => 'asset-1',
                    'naam'                => 'Slimme routeringsalgoritme',
                    'winst_voor_nexus'    => $profitResult['kwalificerendeWinstVoorNexus'],
                    'nexus'               => $profitResult['nexusbreukToegepast'],
                    'winst_na_nexus'      => $profitResult['kwalificerendeWinstNaNexus'],
                    'tariff'              => 0.09,
                    'vpb_impact'          => $profitResult['vpbOpInnovatiedeel'],
                ],
            ],
            'totals' => [
                'kwalificerende_winst_voor_nexus' => $profitResult['kwalificerendeWinstVoorNexus'],
                'kwalificerende_winst_na_nexus'   => $profitResult['kwalificerendeWinstNaNexus'],
                'vpb_op_innovatiedeel'            => $profitResult['vpbOpInnovatiedeel'],
                'voordeel_innovatiebox'           => $profitResult['voordeelInnovatiebox'],
            ],
        ];

        $payload = $export->toSbrInstancePayload(
            $aggregation,
            'adm-mkb-1',
            2026
        );
        $this->assertSame('VPB-XX-2026-adm-mkb-1-2026', $payload['instanceRef']);
        $this->assertCount(1, $payload['perAssetRows']);
        $this->assertSame(72000.0, $payload['regel23_vpbInnovatie']);

    }//end testAfpelmethodeWorkedExamplePipeline()

    /**
     * Forfaitair election binds at the EUR 25k cap and collapses the export
     * to a single line (REQ-IBA-003). No per-asset record required.
     *
     * @return void
     */
    public function testForfaitairElectionBindsAtCapEndToEnd(): void
    {
        $profit = new ProfitAttributionService();
        $export = new InnovatieboxSbrExportService();

        $profitResult = $profit->calculateKwalificerendeWinst(
            methode: 'forfaitair_25pct',
            brutoOpbrengst: 500000.0
        );
        $this->assertSame(25000.0, $profitResult['kwalificerendeWinstNaNexus']);
        $this->assertTrue($profitResult['forfaitairCapApplied']);

        $aggregation = [
            'data'   => [],
            'totals' => [
                'kwalificerende_winst_voor_nexus' => $profitResult['kwalificerendeWinstVoorNexus'],
                'kwalificerende_winst_na_nexus'   => $profitResult['kwalificerendeWinstNaNexus'],
                'vpb_op_innovatiedeel'            => $profitResult['vpbOpInnovatiedeel'],
                'voordeel_innovatiebox'           => $profitResult['voordeelInnovatiebox'],
            ],
        ];

        $payload = $export->toSbrInstancePayload(
            $aggregation,
            'adm-mkb-1',
            2026,
            'forfaitair_25pct'
        );

        $this->assertSame([], $payload['perAssetRows']);
        $this->assertNotNull($payload['forfaitairLine']);
        $this->assertTrue($payload['forfaitairLine']['capApplied']);
        $this->assertSame(25000.0, $payload['forfaitairLine']['kwalifNaCap']);

    }//end testForfaitairElectionBindsAtCapEndToEnd()
}//end class
