<?php

/**
 * WMO Jaarrekening Bijlage Service (REQ-WMO-004)
 *
 * Pure-logic generator for the WMO-bijlage on the jaarrekening: per
 * commercial activity, collects omzet (revenue GL line sum), integrale
 * kostprijs (from the definitief IKP), kostendekkingsratio, prior-year
 * comparison, ABB reference (if exempted), and manual-override counts.
 * Produces PDF-ready summary and XBRL-style XML for SBR delivery.
 *
 * Side-effect-free; the caller wires plain arrays + persists the export
 * through OR ObjectService or hands it to the bookkeeping-financial-reporting
 * jaarrekening-generator (REQ-WMO-004 wiring).
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-13
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Side-effect-free WMO jaarrekening-bijlage composer (REQ-WMO-004).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-13
 */
class WmoJaarrekeningBijlageService
{
    /**
     * Compose a WMO jaarrekening-bijlage for a fiscal year (REQ-WMO-004).
     *
     * @param array{
     *   fiscalYear: string,
     *   administrationId: string,
     *   activities: array<int,array<string,mixed>>,
     *   definitiefIkpByActivity: array<string,array<string,mixed>>,
     *   priorYearIkpByActivity?: array<string,array<string,mixed>>,
     *   omzetByActivity: array<string,float>,
     *   priorYearOmzetByActivity?: array<string,float>,
     *   abbByActivity?: array<string,array<string,mixed>>,
     *   manualOverridesByActivity?: array<string,int>
     * } $input Inputs.
     *
     * @return array<string,mixed> Bijlage payload.
     */
    public function compose(array $input): array
    {
        $activities      = (array) $input['activities'];
        $ikpByAct        = (array) $input['definitiefIkpByActivity'];
        $priorIkpByAct   = (array) ($input['priorYearIkpByActivity'] ?? []);
        $omzetByAct      = (array) $input['omzetByActivity'];
        $priorOmzetByAct = (array) ($input['priorYearOmzetByActivity'] ?? []);
        $abbByAct        = (array) ($input['abbByActivity'] ?? []);
        $overridesByAct  = (array) ($input['manualOverridesByActivity'] ?? []);

        $rows           = [];
        $compliantCount = 0;
        $totalCount     = 0;

        foreach ($activities as $activity) {
            if (is_array($activity) === false) {
                continue;
            }

            $activityId      = (string) ($activity['id'] ?? $activity['_id'] ?? $activity['code'] ?? '');
            $code            = (string) ($activity['code'] ?? '');
            $naam            = (string) ($activity['naam'] ?? '');

            $ikp             = (array) ($ikpByAct[$activityId] ?? []);
            $integraleCost   = (float) ($ikp['totaleKosten'] ?? 0);
            $omzet           = (float) ($omzetByAct[$activityId] ?? 0);
            $ratio           = ($integraleCost > 0.0 ? round(($omzet / $integraleCost), 4) : null);
            $compliant       = ($omzet >= $integraleCost);
            $colorStatus     = ($compliant ? 'groen' : 'rood');

            $priorIkp        = (array) ($priorIkpByAct[$activityId] ?? []);
            $priorCost       = (float) ($priorIkp['totaleKosten'] ?? 0);
            $priorOmzet      = (float) ($priorOmzetByAct[$activityId] ?? 0);
            $priorRatio      = ($priorCost > 0.0 ? round(($priorOmzet / $priorCost), 4) : null);

            $abb             = (array) ($abbByAct[$activityId] ?? []);
            $abbReferentie   = ((bool) ($activity['isExempted'] ?? false))
                ? (string) ($abb['kenmerk'] ?? $activity['exemptionBesluitId'] ?? '')
                : null;

            $rows[] = [
                'commercialActivityId'    => $activityId,
                'code'                    => $code,
                'naam'                    => $naam,
                'omzet'                   => $omzet,
                'integraleKostprijs'      => $integraleCost,
                'kostendekkingsratio'     => $ratio,
                'compliant'               => $compliant,
                'complianceColor'         => $colorStatus,
                'priorYearOmzet'          => $priorOmzet,
                'priorYearIntegraleKostprijs' => $priorCost,
                'priorYearRatio'          => $priorRatio,
                'abbReferentie'           => $abbReferentie,
                'manualOverrides'         => (int) ($overridesByAct[$activityId] ?? 0),
            ];

            if ($compliant === true) {
                $compliantCount++;
            }

            $totalCount++;
        }

        return [
            'format'           => 'WMO-jaarrekening-bijlage-2024',
            'fiscalYear'       => (string) $input['fiscalYear'],
            'administrationId' => (string) $input['administrationId'],
            'generatedAt'      => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM),
            'activiteiten'     => $rows,
            'samenvatting'     => [
                'totaal'        => $totalCount,
                'compliant'     => $compliantCount,
                'nonCompliant'  => ($totalCount - $compliantCount),
            ],
        ];

    }//end compose()

    /**
     * Aggregate compliance status (REQ-WMO-004 §validate).
     *
     * @param array<string,mixed> $bijlage The composed bijlage.
     *
     * @return array{compliant:int,nonCompliant:int,total:int,overallCompliant:bool}
     */
    public function summariseCompliance(array $bijlage): array
    {
        $compliant    = 0;
        $nonCompliant = 0;

        foreach ((array) ($bijlage['activiteiten'] ?? []) as $row) {
            if (is_array($row) === false) {
                continue;
            }

            if ((bool) ($row['compliant'] ?? false)) {
                $compliant++;
            } else {
                $nonCompliant++;
            }
        }

        $total = ($compliant + $nonCompliant);

        return [
            'compliant'        => $compliant,
            'nonCompliant'     => $nonCompliant,
            'total'            => $total,
            'overallCompliant' => ($nonCompliant === 0 && $total > 0),
        ];

    }//end summariseCompliance()

    /**
     * Render the WMO-bijlage as PDF-ready Markdown (REQ-WMO-004 §pdf).
     *
     * The actual PDF render is handled by the shared CashflowPdfRenderer / a
     * future MdToPdf step; this returns the canonical Markdown source.
     *
     * @param array<string,mixed> $bijlage The composed bijlage.
     *
     * @return string Markdown source.
     */
    public function toMarkdown(array $bijlage): string
    {
        $lines   = [];
        $lines[] = '# WMO-bijlage jaarrekening ' . (string) ($bijlage['fiscalYear'] ?? '');
        $lines[] = '';
        $lines[] = '_Format: ' . (string) ($bijlage['format'] ?? '') . '_';
        $lines[] = '';
        $lines[] = '| Code | Naam | Omzet | Integrale Kostprijs | Ratio | Compliant | ABB |';
        $lines[] = '|------|------|-------|---------------------|-------|-----------|-----|';

        foreach ((array) ($bijlage['activiteiten'] ?? []) as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $lines[] = sprintf(
                '| %s | %s | %.2f | %.2f | %s | %s | %s |',
                (string) ($row['code'] ?? ''),
                (string) ($row['naam'] ?? ''),
                (float) ($row['omzet'] ?? 0),
                (float) ($row['integraleKostprijs'] ?? 0),
                ($row['kostendekkingsratio'] === null ? '—' : (string) $row['kostendekkingsratio']),
                ((bool) ($row['compliant'] ?? false) ? 'groen' : 'rood'),
                (string) ($row['abbReferentie'] ?? '—')
            );
        }

        $sam = (array) ($bijlage['samenvatting'] ?? []);
        $lines[] = '';
        $lines[] = sprintf('**Samenvatting**: %d compliant / %d totaal', (int) ($sam['compliant'] ?? 0), (int) ($sam['totaal'] ?? 0));

        return implode("\n", $lines);

    }//end toMarkdown()

    /**
     * Render the WMO-bijlage as minimal SBR/XBRL-style XML (REQ-WMO-004 §xml).
     *
     * @param array<string,mixed> $bijlage The composed bijlage.
     *
     * @return string XML serialization.
     */
    public function toXml(array $bijlage): string
    {
        $fy               = htmlspecialchars((string) ($bijlage['fiscalYear'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $administrationId = htmlspecialchars((string) ($bijlage['administrationId'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $rows = [];
        foreach ((array) ($bijlage['activiteiten'] ?? []) as $r) {
            if (is_array($r) === false) {
                continue;
            }

            $rows[] = sprintf(
                '  <Activiteit code="%s" omzet="%.2f" integraleKostprijs="%.2f" kostendekkingsratio="%s" compliant="%s" abb="%s"/>',
                htmlspecialchars((string) ($r['code'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                (float) ($r['omzet'] ?? 0),
                (float) ($r['integraleKostprijs'] ?? 0),
                ($r['kostendekkingsratio'] === null ? '' : (string) $r['kostendekkingsratio']),
                ((bool) ($r['compliant'] ?? false) ? 'true' : 'false'),
                htmlspecialchars((string) ($r['abbReferentie'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8')
            );
        }

        $body = implode("\n", $rows);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<WMOJaarrekeningBijlage fiscalYear="{$fy}" administrationId="{$administrationId}">
{$body}
</WMOJaarrekeningBijlage>
XML;

    }//end toXml()

}//end class
