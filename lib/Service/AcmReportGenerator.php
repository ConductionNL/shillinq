<?php

/**
 * ACM Report Generator (WMO REQ-WMO-006)
 *
 * Pure-logic generator for ACM rapportages (ACM-standaardformulier-mo-2024).
 * Aggregates commercial activities, integrale-kostprijs records, allocations
 * and ABB-besluiten for a reporting period into a single signed-and-submittable
 * report record. Generates JSON/XML serialisations and the digital-signature
 * envelope per REQ-WMO-006 §digital signature.
 *
 * Side-effect-free: takes plain arrays and returns plain arrays / strings; the
 * caller persists the resulting record via OR ObjectService.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-7
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
 * Side-effect-free ACM report generator (REQ-WMO-006).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-7
 */
class AcmReportGenerator
{
    /**
     * Canonical ACM standard format version.
     *
     * @var string
     */
    public const FORMAT = 'ACM-standaardformulier-mo-2024';

    /**
     * Compose an ACM report record for a reporting period (REQ-WMO-006).
     *
     * @param array{
     *   period: string,
     *   administrationId: string,
     *   activities: array<int,array<string,mixed>>,
     *   ikpRecords: array<string,array<string,mixed>>,
     *   omzetByActivity: array<string,float>,
     *   abbList?: array<int,array<string,mixed>>,
     *   manualOverrides?: int,
     *   samenvatting?: string
     * } $input Inputs.
     *
     * @return array<string,mixed> ACMReport record matching the schema.
     */
    public function compose(array $input): array
    {
        $period = (string) $input['period'];
        if (preg_match('/^[0-9]{4}-(Q[1-4]|YTD)$/', $period) !== 1) {
            throw new InvalidArgumentException('Invalid period format: ' . $period);
        }

        $activities      = (array) $input['activities'];
        $ikpRecords      = (array) ($input['ikpRecords'] ?? []);
        $omzetByActivity = (array) ($input['omzetByActivity'] ?? []);
        $abbList         = (array) ($input['abbList'] ?? []);

        $activiteiten = [];
        foreach ($activities as $activity) {
            if (is_array($activity) === false) {
                continue;
            }

            $activityId       = (string) ($activity['id'] ?? $activity['_id'] ?? $activity['code'] ?? '');
            $code             = (string) ($activity['code'] ?? '');
            $naam             = (string) ($activity['naam'] ?? '');
            $ikp              = (array) ($ikpRecords[$activityId] ?? []);
            $integraleCost    = (float) ($ikp['totaleKosten'] ?? 0);
            $omzet            = (float) ($omzetByActivity[$activityId] ?? 0);
            $ratio            = ($integraleCost > 0.0 ? round(($omzet / $integraleCost), 4) : null);
            $compliant        = ($omzet >= $integraleCost);
            $abbReferentie    = ((bool) ($activity['isExempted'] ?? false)) ? ((string) ($activity['exemptionBesluitId'] ?? '')) : null;

            $activiteiten[] = [
                'commercialActivityId' => $activityId,
                'code'                 => $code,
                'naam'                 => $naam,
                'omzet'                => $omzet,
                'integraleKostprijs'   => $integraleCost,
                'kostendekkingsratio'  => $ratio,
                'compliant'            => $compliant,
                'abbReferentie'        => $abbReferentie,
            ];
        }

        $abbSummaries = [];
        foreach ($abbList as $abb) {
            if (is_array($abb) === false) {
                continue;
            }

            $abbSummaries[] = [
                'kenmerk'           => (string) ($abb['kenmerk'] ?? ''),
                'motiveringExcerpt' => mb_substr(trim((string) ($abb['motivering'] ?? '')), 0, 240),
            ];
        }

        return [
            'period'             => $period,
            'format'             => self::FORMAT,
            'generatedAt'        => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM),
            'activiteiten'       => $activiteiten,
            'samenvatting'       => ($input['samenvatting'] ?? null),
            'manualOverrides'    => (int) ($input['manualOverrides'] ?? 0),
            'abbList'            => $abbSummaries,
            'ondertekenaar'      => null,
            'ondertekendOp'      => null,
            'signatureFingerprint' => null,
            'verzondenAanAcm'    => false,
            'verzondenAanAcmOp'  => null,
            'publicatieGemeenteblad' => null,
            'administrationId'   => (string) $input['administrationId'],
            'status'             => 'draft',
        ];

    }//end compose()

    /**
     * Verify totals match the source omzetByActivity sum (REQ-WMO-006 §integriteit).
     *
     * @param array<string,mixed> $report          The composed report.
     * @param float               $omzetSumLedger  Sum of revenue GL lines for the period.
     * @param float               $tolerance       Cents tolerance (default 1.00 EUR).
     *
     * @return bool True when the report omzet matches the ledger omzet within tolerance.
     */
    public function reconcileOmzet(array $report, float $omzetSumLedger, float $tolerance=1.0): bool
    {
        $sum = 0.0;
        foreach ((array) ($report['activiteiten'] ?? []) as $line) {
            if (is_array($line) === false) {
                continue;
            }

            $sum += (float) ($line['omzet'] ?? 0);
        }

        return abs($sum - $omzetSumLedger) <= $tolerance;

    }//end reconcileOmzet()

    /**
     * Apply digital signature, locking the report into ready-for-submission state (REQ-WMO-006 §digital signature).
     *
     * @param array<string,mixed> $report      The draft report.
     * @param string              $userId      Concerncontroller user-id.
     * @param string              $fingerprint PKI certificate fingerprint.
     *
     * @return array<string,mixed> Signed report (status=ready-for-submission).
     *
     * @throws InvalidArgumentException When the report is not in draft state.
     */
    public function sign(array $report, string $userId, string $fingerprint): array
    {
        if ((string) ($report['status'] ?? '') !== 'draft') {
            throw new InvalidArgumentException('Only draft reports can be signed (current: ' . (string) ($report['status'] ?? '') . ')');
        }

        if (trim($userId) === '') {
            throw new InvalidArgumentException('Signer user-id is required');
        }

        if (trim($fingerprint) === '') {
            throw new InvalidArgumentException('Signature fingerprint is required');
        }

        $report['ondertekenaar']        = $userId;
        $report['ondertekendOp']        = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM);
        $report['signatureFingerprint'] = $fingerprint;
        $report['status']               = 'ready-for-submission';

        return $report;

    }//end sign()

    /**
     * Submit a signed report (REQ-WMO-006 §submit). Status flips to `verzonden`.
     *
     * @param array<string,mixed> $report           A signed report.
     * @param string|null         $publicatieGmblad Optional gemeenteblad reference.
     *
     * @return array<string,mixed> Submitted report (immutable from here).
     *
     * @throws InvalidArgumentException When the report is not signed.
     */
    public function submit(array $report, ?string $publicatieGmblad=null): array
    {
        if ((string) ($report['status'] ?? '') !== 'ready-for-submission') {
            throw new InvalidArgumentException('Only ready-for-submission reports can be submitted');
        }

        $report['verzondenAanAcm']        = true;
        $report['verzondenAanAcmOp']      = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM);
        $report['publicatieGemeenteblad'] = $publicatieGmblad;
        $report['status']                 = 'verzonden';

        return $report;

    }//end submit()

    /**
     * Serialize a report to JSON (ACM-API-compatible).
     *
     * @param array<string,mixed> $report The report record.
     *
     * @return string Pretty-printed JSON.
     */
    public function toJson(array $report): string
    {
        $encoded = json_encode($report, (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode report as JSON');
        }

        return $encoded;

    }//end toJson()

    /**
     * Serialize a report to a minimal SBR/XBRL-style XML (REQ-WMO-006).
     *
     * Anticipates the ACM API: a simple top-level <ACMReport format="..."/> with
     * one <Activiteit/> per line. Real SBR XBRL schemas will be wired when the
     * ACM API spec is published; this is the structural placeholder used for
     * the gemeenteblad export and offline review.
     *
     * @param array<string,mixed> $report The report record.
     *
     * @return string XML serialization.
     */
    public function toXml(array $report): string
    {
        $period           = htmlspecialchars((string) ($report['period'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $administrationId = htmlspecialchars((string) ($report['administrationId'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $format           = htmlspecialchars((string) ($report['format'] ?? self::FORMAT), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        $lines = [];
        foreach ((array) ($report['activiteiten'] ?? []) as $a) {
            if (is_array($a) === false) {
                continue;
            }

            $lines[] = sprintf(
                '  <Activiteit code="%s" omzet="%.2f" integraleKostprijs="%.2f" kostendekkingsratio="%s" compliant="%s"/>',
                htmlspecialchars((string) ($a['code'] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8'),
                (float) ($a['omzet'] ?? 0),
                (float) ($a['integraleKostprijs'] ?? 0),
                ($a['kostendekkingsratio'] === null ? '' : (string) $a['kostendekkingsratio']),
                ((bool) ($a['compliant'] ?? false) ? 'true' : 'false')
            );
        }

        $body = implode("\n", $lines);

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<ACMReport format="{$format}" period="{$period}" administrationId="{$administrationId}">
{$body}
</ACMReport>
XML;

    }//end toXml()

}//end class
