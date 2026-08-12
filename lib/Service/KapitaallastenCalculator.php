<?php

/**
 * Kapitaallasten (depreciation) calculator.
 *
 * ADR-031 exception-path calculator for the Investering kapitaallastenSchedule
 * (REQ-005). Produces a per-year straight-line depreciation schedule from the
 * gross investment, the eersteAfschrijvingsjaar and the afschrijvingstermijn
 * per the BBV notitie Materiële vaste activa (lineaire afschrijving). The last
 * year absorbs any cent-rounding remainder so the schedule sums exactly to the
 * gross amount. No persistence, no I/O.
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
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure calculator for the straight-line kapitaallasten schedule.
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */
class KapitaallastenCalculator
{
    /**
     * Compute a per-year straight-line depreciation schedule.
     *
     * Each year receives floor(bruto / termijn) cents; the final year absorbs
     * the rounding remainder so Σ(schedule) === bruto exactly (integer-cent
     * arithmetic avoids IEEE-754 drift).
     *
     * @param float $bruto                   The gross investment amount.
     * @param int   $eersteAfschrijvingsjaar The first depreciation year.
     * @param int   $afschrijvingstermijn    The depreciation period in years (> 0).
     *
     * @return array<string,float> A {year: amount} schedule keyed by year string.
     *
     * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
     *
     * @SuppressWarnings(PHPMD.LongVariable) BBV domain field names (eersteAfschrijvingsjaar).
     */
    public function schedule(float $bruto, int $eersteAfschrijvingsjaar, int $afschrijvingstermijn): array
    {
        if ($afschrijvingstermijn < 1) {
            return [];
        }

        $brutoCents   = (int) round($bruto * 100);
        $perYearCents = intdiv($brutoCents, $afschrijvingstermijn);
        $remainder    = ($brutoCents - ($perYearCents * $afschrijvingstermijn));

        $schedule = [];
        for ($i = 0; $i < $afschrijvingstermijn; $i++) {
            $year  = (string) ($eersteAfschrijvingsjaar + $i);
            $cents = $perYearCents;
            if ($i === ($afschrijvingstermijn - 1)) {
                // Final year absorbs the rounding remainder.
                $cents += $remainder;
            }

            $schedule[$year] = (float) ($cents / 100);
        }

        return $schedule;

    }//end schedule()
}//end class
