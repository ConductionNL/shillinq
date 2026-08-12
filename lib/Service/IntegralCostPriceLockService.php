<?php

/**
 * IntegralCostPrice Year-End Lock Service (REQ-WMO-002 §year-end lock)
 *
 * Pure-logic service that takes the monthly voorlopig IKP records for a fiscal
 * year and aggregates them into a single definitief IKP record signed by the
 * accountant on 31 March of the following year. Component sums are
 * re-aggregated server-side (not trusting the voorlopig totals) so the lock is
 * an authoritative re-statement.
 *
 * Side-effect-free; the caller persists the result via OR ObjectService and
 * marks the source voorlopig records as superseded.
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
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Side-effect-free year-end IKP definitief lock (REQ-WMO-002 §year-end lock).
 *
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Pre-existing debt (issue
 *     #506): inherent branch complexity in this domain logic; deferred
 *     pending a dedicated refactor.
 */
class IntegralCostPriceLockService
{
    /**
     * Aggregate monthly voorlopig records into a single definitief record (REQ-WMO-002).
     *
     * @param array<string,mixed> $input Lock inputs (commercialActivityId,
     *                                   fiscalYear, voorlopigRecords, signedBy,
     *                                   signatureFingerprint, administrationId,
     *                                   gehanteerdTarief, verkochteEenheden,
     *                                   eenheidLabel).
     *
     * @return array<string,mixed> Definitief IKP record.
     *
     * @throws InvalidArgumentException When inputs are invalid.
     *
     * @spec exclude Touched by the Dutch-to-English vocabulary rename only; the change is to
     *  property-name string literals inside the body, with no behaviour change. No canonical
     *  spec covers this capability yet.
     */
    public function lock(array $input): array
    {
        $voorlopig = (array) ($input['voorlopigRecords'] ?? []);
        if ($voorlopig === []) {
            throw new InvalidArgumentException('Cannot lock without any voorlopig IKP records');
        }

        $signedBy = (string) ($input['signedBy'] ?? '');
        if (trim($signedBy) === '') {
            throw new InvalidArgumentException('Year-end lock requires an accountant user-id (signedBy)');
        }

        $fiscalYear = (string) ($input['fiscalYear'] ?? '');
        if (preg_match('/^[0-9]{4}$/', $fiscalYear) !== 1) {
            throw new InvalidArgumentException('Invalid fiscalYear (expected YYYY): '.$fiscalYear);
        }

        $loonkostenSum     = 0.0;
        $materialenSum     = 0.0;
        $afschrijvingenSum = 0.0;
        $vermogensSum      = 0.0;
        $winstopslagSum    = 0.0;
        $overheadBuckets   = [];
        $totaleKostenSum   = 0.0;

        foreach ($voorlopig as $record) {
            if (is_array($record) === false) {
                continue;
            }

            $componenten = (array) ($record['componenten'] ?? []);

            $loonkostenSum     += (float) ($componenten['directeLoonkosten'] ?? 0);
            $materialenSum     += (float) ($componenten['directeMaterialen'] ?? 0);
            $afschrijvingenSum += (float) ($componenten['directeAfschrijvingen'] ?? 0);
            $vermogensSum      += (float) ($componenten['vermogenskosten'] ?? 0);
            $winstopslagSum    += (float) ($componenten['winstopslag'] ?? 0);

            $overheadInRecord = (array) ($componenten['indirecteOverhead'] ?? []);
            foreach ($overheadInRecord as $bucket => $amount) {
                $overheadBuckets[(string) $bucket] = ($overheadBuckets[(string) $bucket] ?? 0.0) + (float) $amount;
            }

            $totaleKostenSum += (float) ($record['totaleKosten'] ?? 0);
        }

        $verkochteEenheden   = (float) ($input['verkochteEenheden'] ?? 0);
        $kostprijsPerEenheid = null;
        if ($verkochteEenheden > 0.0) {
            $kostprijsPerEenheid = round(($totaleKostenSum / $verkochteEenheden), 4);
        }

        $gehanteerdTarief = null;
        if (isset($input['gehanteerdTarief']) === true) {
            $gehanteerdTarief = (float) $input['gehanteerdTarief'];
        }

        $marge           = null;
        $margePercentage = null;
        if ($gehanteerdTarief !== null && $kostprijsPerEenheid !== null) {
            $marge = round(($gehanteerdTarief - $kostprijsPerEenheid), 4);
            $base  = 1.0;
            if ($kostprijsPerEenheid > 0.0) {
                $base = $kostprijsPerEenheid;
            }

            $margePercentage = round((($marge / $base) * 100), 4);
        }

        $compliant = false;
        if ($gehanteerdTarief !== null && $kostprijsPerEenheid !== null) {
            $compliant = ($gehanteerdTarief >= $kostprijsPerEenheid);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $verkochteEenhedenOut = null;
        if ($verkochteEenheden > 0.0) {
            $verkochteEenhedenOut = $verkochteEenheden;
        }

        return [
            'commercialActivityId' => (string) $input['commercialActivityId'],
            'period'               => $fiscalYear.'-YTD',
            'calculatedOn'         => $now->format(DateTimeImmutable::ATOM),
            'status'               => 'definitief',
            'componenten'          => [
                'directeLoonkosten'     => round($loonkostenSum, 2),
                'directeMaterialen'     => round($materialenSum, 2),
                'directeAfschrijvingen' => round($afschrijvingenSum, 2),
                'indirecteOverhead'     => array_map(fn (float $v): float => round($v, 2), $overheadBuckets),
                'vermogenskosten'       => round($vermogensSum, 2),
                'winstopslag'           => round($winstopslagSum, 2),
            ],
            'totaleKosten'         => round($totaleKostenSum, 2),
            'verkochteEenheden'    => $verkochteEenhedenOut,
            'unitLabel'            => ($input['unitLabel'] ?? null),
            'kostprijsPerEenheid'  => $kostprijsPerEenheid,
            'gehanteerdTarief'     => $gehanteerdTarief,
            'marge'                => $marge,
            'margePercentage'      => $margePercentage,
            'compliant'            => $compliant,
            'definitiefSignedBy'   => $signedBy,
            'definitiefSignedAt'   => $now->format(DateTimeImmutable::ATOM),
            'administrationId'     => (string) $input['administrationId'],
        ];

    }//end lock()

    /**
     * Determine whether today is past the year-end lock trigger date (31 March of FY+1).
     *
     * @param string $fiscalYear Fiscal year (YYYY).
     * @param string $today      Today's ISO date.
     *
     * @return bool True when lock should run.
     */
    public function shouldLock(string $fiscalYear, string $today): bool
    {
        if (preg_match('/^[0-9]{4}$/', $fiscalYear) !== 1) {
            return false;
        }

        try {
            $lockDate = new DateTimeImmutable(((int) $fiscalYear + 1).'-03-31');
            $now      = new DateTimeImmutable($today);
        } catch (\Throwable) {
            return false;
        }

        return $now >= $lockDate;

    }//end shouldLock()
}//end class
