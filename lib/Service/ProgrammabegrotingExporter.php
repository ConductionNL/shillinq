<?php

/**
 * Programmabegroting exporter.
 *
 * ADR-031 exception-path calculator for the three export modes of REQ-012:
 * iv3-aanlevering (CBS, taakveld-aggregated per economische categorie),
 * EMU-saldo (Wet Hof / SNA-2010), and JSON (OpenCatalogi hergebruik). These are
 * pure shape transformations over already-fetched register data — the transport
 * (CBS-portaal, OpenConnector) is out of scope. All monetary aggregation is in
 * integer euro-cents to avoid IEEE-754 drift. No persistence, no I/O.
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
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-28
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure calculator producing iv3, EMU-saldo and JSON export shapes.
 *
 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-28
 */
class ProgrammabegrotingExporter
{
    /**
     * Build the iv3-aanlevering rows: one row per taakveldCode (REQ-012).
     *
     * Aggregates baten and lasten per taakveldCode across all programma's that
     * contain that taakveld (summing in integer cents), exactly matching the
     * taakveld-first view the raad adopted. Rows are sorted by taakveldCode for
     * deterministic output.
     *
     * @param array<int,array<string,mixed>> $taakvelden The vastgestelde Taakveld rows.
     *
     * @return array<int,array{taakveldCode:string,baten:float,lasten:float}> Sorted iv3 rows.
     *
     * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-28
     */
    public function iv3Rows(array $taakvelden): array
    {
        $byCode = [];
        foreach ($taakvelden as $taakveld) {
            $code = (string) ($taakveld['taakveldCode'] ?? '');
            if ($code === '') {
                continue;
            }

            if (isset($byCode[$code]) === false) {
                $byCode[$code] = ['batenCents' => 0, 'lastenCents' => 0];
            }

            $byCode[$code]['batenCents']  += (int) round(((float) ($taakveld['baten'] ?? 0)) * 100);
            $byCode[$code]['lastenCents'] += (int) round(((float) ($taakveld['lasten'] ?? 0)) * 100);
        }

        ksort($byCode);

        $rows = [];
        foreach ($byCode as $code => $cents) {
            $rows[] = [
                'taakveldCode' => $code,
                'baten'        => (float) ($cents['batenCents'] / 100),
                'lasten'       => (float) ($cents['lastenCents'] / 100),
            ];
        }

        return $rows;

    }//end iv3Rows()

    /**
     * Compute the EMU-saldo per Wet Hof / SNA-2010 (REQ-012).
     *
     * EMU-saldo = Σ(baten) - Σ(lasten) with corrections: investeringen are
     * capitalised (added back, as the cash outflow is not an EMU-relevant last),
     * and reserve/voorziening mutations net to zero on the EMU basis (added
     * back). Computed in integer cents and returned in euro.
     *
     * @param array<int,array<string,mixed>> $taakvelden      The vastgestelde Taakveld rows.
     * @param array<int,array<string,mixed>> $investeringen   The Investering rows (bruto capitalised).
     * @param float                          $reserveMutaties Net reserve mutations to correct out.
     *
     * @return float The EMU-saldo in euro (positive = overschot).
     *
     * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-29
     */
    public function emuSaldo(array $taakvelden, array $investeringen=[], float $reserveMutaties=0.0): float
    {
        $batenCents  = 0;
        $lastenCents = 0;
        foreach ($taakvelden as $taakveld) {
            $batenCents  += (int) round(((float) ($taakveld['baten'] ?? 0)) * 100);
            $lastenCents += (int) round(((float) ($taakveld['lasten'] ?? 0)) * 100);
        }

        $investeringCents = 0;
        foreach ($investeringen as $investering) {
            $investeringCents += (int) round(((float) ($investering['bruto'] ?? 0)) * 100);
        }

        $reserveCents = (int) round($reserveMutaties * 100);

        // Nominal saldo, then add back capitalised investeringen and reserve
        // mutations that are not EMU-relevant lasten.
        $saldoCents = (($batenCents - $lastenCents) + $investeringCents + $reserveCents);

        return (float) ($saldoCents / 100);

    }//end emuSaldo()

    /**
     * Build the OpenCatalogi JSON export shape (REQ-012).
     *
     * Serialises the vastgestelde Programmabegroting metadata, its programma's
     * (with narratives), all taakvelden and all seven paragrafen into a single
     * machine-readable array suitable for json_encode and OpenCatalogi
     * publication.
     *
     * @param array<string,mixed>            $begroting  The Programmabegroting row.
     * @param array<int,array<string,mixed>> $programmas The Programma rows.
     * @param array<int,array<string,mixed>> $taakvelden The Taakveld rows.
     * @param array<int,array<string,mixed>> $paragrafen The Paragraaf rows.
     *
     * @return array<string,mixed> The JSON-serialisable export shape.
     *
     * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-30
     */
    public function jsonExport(array $begroting, array $programmas, array $taakvelden, array $paragrafen): array
    {
        return [
            'metadata'   => [
                'begrotingsjaar'      => ($begroting['begrotingsjaar'] ?? null),
                'organisationType'    => ($begroting['organisationType'] ?? null),
                'status'              => ($begroting['status'] ?? null),
                'vaststellingsDatum'  => ($begroting['vaststellingsDatum'] ?? null),
                'sluitendStructureel' => ($begroting['sluitendStructureel'] ?? null),
                'sluitendReëel'       => ($begroting['sluitendReëel'] ?? null),
                'toezichtRegime'      => ($begroting['toezichtRegime'] ?? null),
            ],
            'programmas' => array_map(
                static function (array $programma): array {
                    return [
                        'nummer'         => ($programma['nummer'] ?? null),
                        'naam'           => ($programma['naam'] ?? null),
                        'doelstellingen' => ($programma['doelstellingen'] ?? null),
                        'batenTotaal'    => ($programma['batenTotaal'] ?? null),
                        'lastenTotaal'   => ($programma['lastenTotaal'] ?? null),
                    ];
                },
                $programmas
            ),
            'taakvelden' => $this->iv3Rows(taakvelden: $taakvelden),
            'paragrafen' => array_map(
                static function (array $paragraaf): array {
                    return [
                        'type'        => ($paragraaf['type'] ?? null),
                        'narrative'   => ($paragraaf['narrative'] ?? null),
                        'kerncijfers' => ($paragraaf['kerncijfers'] ?? null),
                    ];
                },
                $paragrafen
            ),
        ];

    }//end jsonExport()
}//end class
