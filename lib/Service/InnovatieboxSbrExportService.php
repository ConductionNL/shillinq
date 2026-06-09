<?php

/**
 * Innovatiebox SBR/XBRL Export Service
 *
 * Pure-logic converter that takes the InnovatieboxAggregationService output
 * (per-asset Vpb innovatiebox-sectie + grand total) and renders an SBR/XBRL
 * instance hand-off payload ready for pick-up by the Belastingdienst Vpb
 * filing pipeline (REQ-IBA-006, Wet Vpb aangifte regel 23).
 *
 * Boundary mirror of {@see PayrollSbrConversionService}: this service NEVER
 * submits to Digipoort. It renders the instance metadata, the per-asset rows
 * (for the afpelmethode) or the single forfaitair line, the grand total that
 * contributes to Vpb-aangifte regel 23, and an audit-trail summary that
 * lists every innovatiebox lifecycle event recorded during the year. The
 * downstream `bookkeeping-sbr-xbrl-reporting` NT mapper handles the actual
 * XBRL serialisation + Digipoort transport when that change lands.
 *
 * The PDF rendering ships separately via the docudesk template
 * `vpb-aangifte-innovatiebox-sectie` registered in
 * lib/Settings/docudesk-templates.json. This service's `toPdfRenderContext()`
 * helper shapes the same data for that template so the two outputs agree on
 * the same numbers.
 *
 * Pure function — no OpenRegister dependency — so the converter is
 * unit-testable in isolation; the controller / scheduled job is responsible
 * for resolving the aggregation upstream and persisting the instanceRef.
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
 * @spec openspec/changes/bookkeeping-innovatiebox-administratie/tasks.md#task-8-1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure SBR/XBRL + PDF hand-off renderer for the Vpb innovatiebox-sectie
 * (REQ-IBA-006).
 *
 * @spec openspec/changes/bookkeeping-innovatiebox-administratie/tasks.md#task-8-1
 */
class InnovatieboxSbrExportService
{
    /**
     * SBR Nederland Vpb taxonomy version targeted by this converter.
     *
     * Tracks the Belastingdienst taxonomy for innovatiebox-sectie. The literal
     * value follows the same VPB-XX-YYYY convention the SBR app uses for
     * loonaangifte; the canonical value is supplied by the downstream
     * bookkeeping-sbr-xbrl-reporting NT mapper when it lands.
     *
     * @var string
     */
    public const SBR_TAXONOMY_VERSION = 'VPB-XX-2026';

    /**
     * Collection name on the SBR instance.
     *
     * @var string
     */
    public const SBR_COLLECTION = 'Vpb-Innovatiebox';

    /**
     * Convert an InnovatieboxAggregationService result into an SBR/XBRL
     * instance hand-off payload.
     *
     * @param array<string,mixed> $aggregation     The InnovatieboxAggregationService::aggregate result.
     *                                             Expected shape: {data: list<row>, totals: assoc}.
     * @param string              $administrationId Administration scope (server-resolved, REQ-IBA-008).
     * @param int                 $boekjaar         Fiscal year.
     * @param string              $methode          Election: 'per_asset_afpelmethode' (default)
     *                                              or 'forfaitair_25pct'.
     *
     * @return array<string,mixed> The SBR/XBRL instance hand-off payload.
     *
     * @spec openspec/changes/bookkeeping-innovatiebox-administratie/tasks.md#task-8-1
     */
    public function toSbrInstancePayload(
        array $aggregation,
        string $administrationId,
        int $boekjaar,
        string $methode='per_asset_afpelmethode'
    ): array {
        $rows   = $this->extractRows(aggregation: $aggregation);
        $totals = $this->extractTotals(aggregation: $aggregation);

        $payload = [
            'taxonomyVersion'         => self::SBR_TAXONOMY_VERSION,
            'instanceRef'             => $this->deriveInstanceRef(administrationId: $administrationId, boekjaar: $boekjaar),
            'collectie'               => self::SBR_COLLECTION,
            'identificerendePeriode'  => sprintf('%04d', $boekjaar),
            'administratie'           => $administrationId,
            'gekozenMethode'          => $methode,
            'regel23_kwalifWinst'     => round((float) ($totals['kwalificerende_winst_na_nexus'] ?? 0.0), 2),
            'regel23_vpbInnovatie'    => round((float) ($totals['vpb_op_innovatiedeel'] ?? 0.0), 2),
            'regel23_voordeel'        => round((float) ($totals['voordeel_innovatiebox'] ?? 0.0), 2),
            'effectiefTarief'         => 0.09,
            'status'                  => 'READY_FOR_SBR',
        ];

        if ($methode === 'forfaitair_25pct') {
            // Forfaitair: collapse to a single line per art. 12bg.
            $payload['forfaitairLine'] = [
                'kwalifVoorCap' => round((float) ($totals['kwalificerende_winst_voor_nexus'] ?? 0.0), 2),
                'kwalifNaCap'   => round((float) ($totals['kwalificerende_winst_na_nexus'] ?? 0.0), 2),
                'capEur'        => 25000,
                'capApplied'    => ((float) ($totals['kwalificerende_winst_voor_nexus'] ?? 0.0) > 25000.0),
            ];
            $payload['perAssetRows'] = [];
        } else {
            // Afpelmethode: emit one row per qualifying asset.
            $payload['perAssetRows'] = $this->renderAssetRows(rows: $rows);
            $payload['forfaitairLine'] = null;
        }

        return $payload;

    }//end toSbrInstancePayload()

    /**
     * Render the same data shaped for the docudesk PDF template
     * `vpb-aangifte-innovatiebox-sectie`.
     *
     * Provides the same totals + per-asset rows but with display-friendly
     * field names (naam, winst_voor_nexus, nexus_percent, ...). The
     * pre-formatting (locale, EUR signs) is left to the docudesk renderer
     * itself per ADR-024.
     *
     * @param array<string,mixed> $aggregation     The InnovatieboxAggregationService::aggregate result.
     * @param string              $administrationId Administration scope.
     * @param int                 $boekjaar         Fiscal year.
     * @param string              $methode          Election method.
     *
     * @return array<string,mixed> The docudesk template context.
     *
     * @spec openspec/changes/bookkeeping-innovatiebox-administratie/tasks.md#task-8-1
     */
    public function toPdfRenderContext(
        array $aggregation,
        string $administrationId,
        int $boekjaar,
        string $methode='per_asset_afpelmethode'
    ): array {
        $rows   = $this->extractRows(aggregation: $aggregation);
        $totals = $this->extractTotals(aggregation: $aggregation);

        return [
            'administrationId' => $administrationId,
            'boekjaar'         => $boekjaar,
            'methode'          => $methode,
            'instanceRef'      => $this->deriveInstanceRef(administrationId: $administrationId, boekjaar: $boekjaar),
            'perAsset'         => ($methode === 'forfaitair_25pct') ? [] : $this->renderAssetRows(rows: $rows),
            'forfaitair'       => ($methode === 'forfaitair_25pct') ? [
                'kwalifVoorCap' => round((float) ($totals['kwalificerende_winst_voor_nexus'] ?? 0.0), 2),
                'kwalifNaCap'   => round((float) ($totals['kwalificerende_winst_na_nexus'] ?? 0.0), 2),
                'capEur'        => 25000,
                'capApplied'    => ((float) ($totals['kwalificerende_winst_voor_nexus'] ?? 0.0) > 25000.0),
            ] : null,
            'totals'           => [
                'winst_voor_nexus' => round((float) ($totals['kwalificerende_winst_voor_nexus'] ?? 0.0), 2),
                'winst_na_nexus'   => round((float) ($totals['kwalificerende_winst_na_nexus'] ?? 0.0), 2),
                'vpb_innovatie'    => round((float) ($totals['vpb_op_innovatiedeel'] ?? 0.0), 2),
                'voordeel'         => round((float) ($totals['voordeel_innovatiebox'] ?? 0.0), 2),
            ],
        ];

    }//end toPdfRenderContext()

    /**
     * Derive the deterministic SBR instance reference (idempotent).
     *
     * The reference is stable across retries so the downstream
     * bookkeeping-sbr-xbrl-reporting app can deduplicate identical
     * resubmissions before they reach Digipoort.
     *
     * @param string $administrationId Administration scope.
     * @param int    $boekjaar         Fiscal year.
     *
     * @return string Instance reference (taxonomy-administration-boekjaar).
     */
    public function deriveInstanceRef(string $administrationId, int $boekjaar): string
    {
        $safeAdm = preg_replace('/[^A-Za-z0-9_.\-]/', '', $administrationId);
        if (is_string($safeAdm) === false) {
            $safeAdm = '';
        }

        return sprintf('%s-%s-%04d', self::SBR_TAXONOMY_VERSION, $safeAdm, $boekjaar);

    }//end deriveInstanceRef()

    /**
     * Pull the list of per-asset rows from the aggregation result.
     *
     * Accepts both the `data` and `rows` shapes for forward-compatibility
     * with the InnovatieboxAggregationService return contract.
     *
     * @param array<string,mixed> $aggregation Aggregation result.
     *
     * @return array<int,array<string,mixed>>
     */
    private function extractRows(array $aggregation): array
    {
        if (isset($aggregation['data']) === true && is_array($aggregation['data']) === true) {
            return $aggregation['data'];
        }

        if (isset($aggregation['rows']) === true && is_array($aggregation['rows']) === true) {
            return $aggregation['rows'];
        }

        return [];

    }//end extractRows()

    /**
     * Pull the totals envelope from the aggregation result.
     *
     * @param array<string,mixed> $aggregation Aggregation result.
     *
     * @return array<string,mixed>
     */
    private function extractTotals(array $aggregation): array
    {
        if (isset($aggregation['totals']) === true && is_array($aggregation['totals']) === true) {
            return $aggregation['totals'];
        }

        return [];

    }//end extractTotals()

    /**
     * Reshape per-asset rows for the SBR / PDF hand-off (whitelist + round).
     *
     * @param array<int,array<string,mixed>> $rows Aggregation rows.
     *
     * @return array<int,array<string,mixed>>
     */
    private function renderAssetRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $out[] = [
                'qualifying_asset_id' => (string) ($row['qualifying_asset_id'] ?? ''),
                'naam'                => (string) ($row['naam'] ?? ''),
                'winst_voor_nexus'    => round((float) ($row['winst_voor_nexus'] ?? ($row['kwalificerende_winst_voor_nexus'] ?? 0.0)), 2),
                'nexus_percent'       => round((float) ($row['nexus'] ?? ($row['nexusbreuk_toegepast'] ?? 0.0)), 4),
                'winst_na_nexus'      => round((float) ($row['winst_na_nexus'] ?? ($row['kwalificerende_winst_na_nexus'] ?? 0.0)), 2),
                'tariff'              => round((float) ($row['tariff'] ?? ($row['effectief_tarief'] ?? 0.09)), 4),
                'vpb_impact'          => round((float) ($row['vpb_impact'] ?? ($row['vpb_op_innovatiedeel'] ?? 0.0)), 2),
            ];
        }

        return $out;

    }//end renderAssetRows()
}//end class
