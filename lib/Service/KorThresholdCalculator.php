<?php

/**
 * KOR Threshold Calculator
 *
 * Pure-logic helper for the Kleine Ondernemersregeling (KOR) drempel-bewaking
 * (REQ-KOR-002, REQ-KOR-003, REQ-KOR-004, REQ-KOR-011). Holds the side-effect-free
 * fiscal arithmetic that KorMonitorService applies after fetching AR-invoice data
 * via the OpenRegister ObjectService: running KOR-eligible omzet, drempel-benutting,
 * the linear month-average end-of-year prognose, the 80/90/100 % alert-schijf,
 * the suppletie-bedrag on mid-year overschrijding (bedrag * 0.21 / 1.21 over the
 * KOR-facturen between ingangsDatum and revocatieDatum), and the herzieningsregels
 * proportional voorbelasting recovery. All money arithmetic is performed in integer
 * cents to avoid IEEE-754 drift, mirroring TrialBalanceCalculator.
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
 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free KOR fiscal arithmetic helper.
 *
 * No OpenRegister dependency: every method takes plain arrays/scalars and returns
 * plain arrays/scalars so the logic is unit-testable in isolation. KorMonitorService
 * wires this helper to live AR-invoice + KORRegistration data.
 *
 * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
 */
class KorThresholdCalculator
{
    /**
     * The standard NL VAT rate used for suppletie / herziening (21%).
     *
     * @var float
     */
    public const STANDARD_VAT_RATE = 0.21;

    /**
     * Convert a money amount to integer cents.
     *
     * @param mixed $amount Money amount (float|int|numeric-string|null).
     *
     * @return int Amount in whole cents.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function toCents(mixed $amount): int
    {
        return (int) round((float) ($amount ?? 0) * 100);

    }//end toCents()

    /**
     * Convert integer cents back to a float money amount.
     *
     * @param int $cents Amount in whole cents.
     *
     * @return float Money amount.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function fromCents(int $cents): float
    {
        return ($cents / 100);

    }//end fromCents()

    /**
     * Sum the KOR-eligible omzet of a set of AR invoices for a calendar year (REQ-KOR-002).
     *
     * Only invoices with vrijstellingsGrondslag == 'KOR_ART25_OB', a leveringsDatum
     * in the target year, and a non-draft status count. Excluded grounds (vrijgestelde
     * prestaties, intracommunautair, onroerend goed) are skipped because they never
     * carry KOR_ART25_OB. Arithmetic in cents.
     *
     * @param array<int,array<string,mixed>> $invoices AR-invoice records.
     * @param int                            $year     Calendar year to bound the sum.
     *
     * @return int Running KOR omzet in cents.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function runningOmzetCents(array $invoices, int $year): int
    {
        $total = 0;
        foreach ($invoices as $invoice) {
            if ($this->isKorEligible(invoice: $invoice, year: $year) === false) {
                continue;
            }

            $total += $this->toCents(amount: ($invoice['bedrag'] ?? ($invoice['netAmount'] ?? 0)));
        }

        return $total;

    }//end runningOmzetCents()

    /**
     * Decide whether an AR invoice counts toward the KOR drempel (REQ-KOR-002).
     *
     * @param array<string,mixed> $invoice The AR-invoice record.
     * @param int                 $year    Calendar year the leveringsDatum must fall in.
     *
     * @return bool True when the invoice is KOR-eligible revenue for the year.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function isKorEligible(array $invoice, int $year): bool
    {
        if ((string) ($invoice['vrijstellingsGrondslag'] ?? '') !== 'KOR_ART25_OB') {
            return false;
        }

        $status = (string) ($invoice['status'] ?? ($invoice['state'] ?? ''));
        if ($status === 'draft' || $status === 'cancelled' || $status === 'credited') {
            return false;
        }

        $leveringsDatum = (string) ($invoice['leveringsDatum'] ?? ($invoice['invoiceDate'] ?? ''));
        return (substr($leveringsDatum, 0, 4) === (string) $year);

    }//end isKorEligible()

    /**
     * Compute drempel-benutting as a fraction (REQ-KOR-002).
     *
     * @param int $omzetCents   Running KOR omzet in cents.
     * @param int $drempelCents Threshold in cents (EUR 20.000 => 2_000_000).
     *
     * @return float Benutting fraction (0..1+); zero when the threshold is zero.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function benutting(int $omzetCents, int $drempelCents): float
    {
        if ($drempelCents <= 0) {
            return 0.0;
        }

        return ($omzetCents / $drempelCents);

    }//end benutting()

    /**
     * Project end-of-year omzet from the year-to-date monthly average (REQ-KOR-002).
     *
     * Prognose = lopende + (maandgemiddelde * resterende maanden), where the average
     * is over the elapsed months (1..currentMonth). Returns cents.
     *
     * @param int $lopendeCents Running omzet in cents.
     * @param int $currentMonth Current calendar month (1..12).
     *
     * @return int Projected end-of-year omzet in cents.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function prognoseEndOfYearCents(int $lopendeCents, int $currentMonth): int
    {
        $month     = max(1, min(12, $currentMonth));
        $avg       = intdiv($lopendeCents, $month);
        $remaining = (12 - $month);
        return ($lopendeCents + ($avg * $remaining));

    }//end prognoseEndOfYearCents()

    /**
     * Resolve the prognose-status from the projected benutting (REQ-KOR-002).
     *
     * @param int $prognoseCents Projected end-of-year omzet in cents.
     * @param int $drempelCents  Threshold in cents.
     *
     * @return string ONDER_DREMPEL | WAARSCHUWING | OVERSCHRIJDING_VERWACHT.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function prognoseStatus(int $prognoseCents, int $drempelCents): string
    {
        $b = $this->benutting(omzetCents: $prognoseCents, drempelCents: $drempelCents);
        if ($b >= 1.0) {
            return 'OVERSCHRIJDING_VERWACHT';
        }

        if ($b >= 0.8) {
            return 'WAARSCHUWING';
        }

        return 'ONDER_DREMPEL';

    }//end prognoseStatus()

    /**
     * Determine which alert-schijf a benutting newly crosses (REQ-KOR-003).
     *
     * Returns the highest schijf that is reached at the new benutting but was NOT
     * yet reached at the previous benutting, so each schijf fires exactly once as
     * omzet climbs. Returns null when no new schijf is crossed.
     *
     * @param float $previousBenutting Benutting before the posting.
     * @param float $newBenutting      Benutting after the posting.
     *
     * @return array{trigger:string,ernst:string}|null The crossed schijf or null.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function crossedSchijf(float $previousBenutting, float $newBenutting): ?array
    {
        $schijven = [
            ['threshold' => 1.0, 'trigger' => 'DREMPEL_100PCT', 'ernst' => 'OVERSCHRIJDING'],
            ['threshold' => 0.9, 'trigger' => 'DREMPEL_90PCT', 'ernst' => 'KRITIEK'],
            ['threshold' => 0.8, 'trigger' => 'DREMPEL_80PCT', 'ernst' => 'VROEG'],
        ];

        foreach ($schijven as $schijf) {
            if ($newBenutting >= $schijf['threshold'] && $previousBenutting < $schijf['threshold']) {
                return ['trigger' => $schijf['trigger'], 'ernst' => $schijf['ernst']];
            }
        }

        return null;

    }//end crossedSchijf()

    /**
     * Compute the suppletie-bedrag on mid-year overschrijding (REQ-KOR-004).
     *
     * For every KOR-factuur with a leveringsDatum on/after ingangsDatum and strictly
     * before revocatieDatum, the VAT that would have been due under the regular regime
     * is bedrag * 0.21 / 1.21 (the VAT embedded in the gross KOR amount). The suppletie
     * is the sum of those amounts. Arithmetic in cents.
     *
     * @param array<int,array<string,mixed>> $invoices       KOR AR-invoice records.
     * @param string                         $ingangsDatum   KOR start date (YYYY-MM-DD).
     * @param string                         $revocatieDatum Revocation date (YYYY-MM-DD), exclusive.
     *
     * @return int Suppletie-bedrag in cents.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function suppletieBedragCents(array $invoices, string $ingangsDatum, string $revocatieDatum): int
    {
        $total = 0;
        foreach ($invoices as $invoice) {
            if ((string) ($invoice['vrijstellingsGrondslag'] ?? '') !== 'KOR_ART25_OB') {
                continue;
            }

            $leveringsDatum = (string) ($invoice['leveringsDatum'] ?? '');
            if ($leveringsDatum === '' || $leveringsDatum < $ingangsDatum || $leveringsDatum >= $revocatieDatum) {
                continue;
            }

            $grossCents = $this->toCents(amount: ($invoice['bedrag'] ?? ($invoice['netAmount'] ?? 0)));
            // VAT embedded in the gross KOR amount: gross * rate / (1 + rate).
            $vatCents = (int) round(($grossCents * self::STANDARD_VAT_RATE) / (1.0 + self::STANDARD_VAT_RATE));
            $total   += $vatCents;
        }//end foreach

        return $total;

    }//end suppletieBedragCents()

    /**
     * Add three calendar years to a date for the heraanmelding-blokkade (REQ-KOR-004).
     *
     * @param string $date Date in YYYY-MM-DD.
     *
     * @return string Date plus three years in YYYY-MM-DD; empty string when input invalid.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function plusThreeYears(string $date): string
    {
        // Validate strictly as YYYY-MM-DD; pure string arithmetic avoids a
        // DateTime dependency and keeps the leveringsdatum + 3 jaar deterministic.
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $date, $match) !== 1) {
            return '';
        }

        $year  = ((int) $match[1] + 3);
        $month = (int) $match[2];
        $day   = (int) $match[3];
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return '';
        }

        // Clamp 29 February to 28 February when +3 years lands on a non-leap year.
        if ($month === 2 && $day === 29 && checkdate(2, 29, $year) === false) {
            $day = 28;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);

    }//end plusThreeYears()

    /**
     * Resolve the canonical lock-in window for a KOR-NL registration (REQ-KOR-007).
     *
     * NL-KOR lock-in is three full calendar years counted from the ingangsDatum.
     * vroegsteOpzegDatum is the three-month opt-out window, opening on the first
     * day of October of the third year (lockInEindDatum - 3 months). The returned
     * dates are exact YYYY-MM-DD strings — the caller persists them on the
     * KORRegistration record so the manifest pages can render the window.
     *
     * @param string $ingangsDatum KOR-NL effective date (YYYY-MM-DD).
     *
     * @return array{lockInEindDatum:string,vroegsteOpzegDatum:string}|null Window or null on invalid input.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function lockInWindow(string $ingangsDatum): ?array
    {
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $ingangsDatum, $match) !== 1) {
            return null;
        }

        $year  = ((int) $match[1] + 3);
        $month = (int) $match[2];
        $day   = (int) $match[3];
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        // LockInEindDatum: ingangsDatum - 1 day + 3 years = end of third calendar year.
        // For a 1-1 ingangsdatum, that is 31-12 of (year + 2). We model the general
        // case as +3 years - 1 day; for the canonical 1-1 start the prior day is 31-12.
        if ($month === 1 && $day === 1) {
            $lockInYear  = ($year - 1);
            $lockInEinde = sprintf('%04d-12-31', $lockInYear);
        } else {
            $priorDay   = ($day - 1);
            $priorMonth = $month;
            if ($priorDay < 1) {
                $priorMonth = ($month - 1);
                if ($priorMonth < 1) {
                    $priorMonth = 12;
                    $year      -= 1;
                }

                $priorDay = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $priorMonth)));
            }

            $lockInEinde = sprintf('%04d-%02d-%02d', $year, $priorMonth, $priorDay);
        }

        // Vroegste opzeg-datum = three months before lockInEinde, rounded to the
        // first day of that month (canonical opt-out window opens 1 October when
        // lockInEinde is 31 December).
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $lockInEinde, $m2) !== 1) {
            return null;
        }

        $lyear      = (int) $m2[1];
        $lmonth     = (int) $m2[2];
        $opzegMonth = ($lmonth - 2);
        $opzegYear  = $lyear;
        if ($opzegMonth < 1) {
            $opzegMonth += 12;
            $opzegYear  -= 1;
        }

        $vroegsteOpzeg = sprintf('%04d-%02d-01', $opzegYear, $opzegMonth);

        return [
            'lockInEindDatum'    => $lockInEinde,
            'vroegsteOpzegDatum' => $vroegsteOpzeg,
        ];

    }//end lockInWindow()

    /**
     * Decide whether a vrijwillige opzegging is permitted at a moment in time (REQ-KOR-007).
     *
     * Opt-out is blocked until vroegsteOpzegDatum (three months before the end of
     * the three-year lock-in) and again after lockInEindDatum the registration is
     * already at its natural end (different lifecycle path).
     *
     * @param string $today              Today's date (YYYY-MM-DD).
     * @param string $vroegsteOpzegDatum Earliest opt-out date (YYYY-MM-DD).
     * @param string $lockInEindDatum    End of the lock-in window (YYYY-MM-DD).
     *
     * @return bool True when an operator-initiated opt-out is permitted.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function isOptOutPermitted(string $today, string $vroegsteOpzegDatum, string $lockInEindDatum): bool
    {
        if ($vroegsteOpzegDatum === '' || $lockInEindDatum === '') {
            return false;
        }

        return ($today >= $vroegsteOpzegDatum && $today <= $lockInEindDatum);

    }//end isOptOutPermitted()

    /**
     * Aggregate cross-border KOR-EU omzet per lidstaat (REQ-KOR-008).
     *
     * Walks the KOR-EU AR invoices, groups them by lidstaat-ISO-code, sums omzet
     * per country, and resolves benutting against the per-lidstaat drempel passed
     * in $drempelsPerLidstaat. Countries not in the drempels map fall back to
     * the EU-wide default 100000 EUR ceiling. Arithmetic in cents.
     *
     * @param array<int,array<string,mixed>> $invoices            KOR-EU AR-invoice records (must carry `lidstaat`).
     * @param array<string,float>            $drempelsPerLidstaat Per-country drempel (EUR); e.g. ['BE' => 25000, 'DE' => 22000].
     * @param int                            $year                Calendar year to bound the aggregation.
     *
     * @return array<string,array{omzet:float,drempel:float,benutting:float}> Per-lidstaat aggregate.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function perLidstaatAggregate(array $invoices, array $drempelsPerLidstaat, int $year): array
    {
        $defaultDrempelCents = $this->toCents(amount: 100000);
        $cents = [];
        foreach ($invoices as $invoice) {
            if ((string) ($invoice['vrijstellingsGrondslag'] ?? '') !== 'KOR_ART25_OB') {
                continue;
            }

            $lidstaat = strtoupper((string) ($invoice['lidstaat'] ?? ''));
            if ($lidstaat === '') {
                continue;
            }

            $leveringsDatum = (string) ($invoice['leveringsDatum'] ?? '');
            if (substr($leveringsDatum, 0, 4) !== (string) $year) {
                continue;
            }

            $cents[$lidstaat] = (($cents[$lidstaat] ?? 0) + $this->toCents(amount: ($invoice['bedrag'] ?? 0)));
        }

        $result = [];
        foreach ($cents as $lidstaat => $omzetCents) {
            $drempelCents = ($defaultDrempelCents);
            if (isset($drempelsPerLidstaat[$lidstaat]) === true) {
                $drempelCents = $this->toCents(amount: $drempelsPerLidstaat[$lidstaat]);
            }

            $result[$lidstaat] = [
                'omzet'     => $this->fromCents(cents: $omzetCents),
                'drempel'   => $this->fromCents(cents: $drempelCents),
                'benutting' => round($this->benutting(omzetCents: $omzetCents, drempelCents: $drempelCents), 4),
            ];
        }

        ksort($result);
        return $result;

    }//end perLidstaatAggregate()

    /**
     * Resolve a branche-specifieke advisory for a KOR-NL aanmelding (REQ-KOR-010).
     *
     * Combines the KvK activiteitscode-class and the administration's vrijstellingen
     * to surface compatibility issues before lock-in:
     *  - art. 11 OB full exemption -> KOR adds no benefit and disables voorbelasting (BLOCK).
     *  - mixed-use vrijgesteld+belast -> effective drempel only on the belaste deel (WARN).
     *  - intracommunautair -> OSS-regime is the better fit (WARN).
     *  - fiscale-eenheid -> eenheid must apply, not the individual (BLOCK).
     * Otherwise OK. The returned advisory is text-based (REQ-KOR-010 no chatbot).
     *
     * @param array<string,mixed> $branche Administration's branche profile.
     *
     * @return array{verdict:string,reden:string} Verdict (OK|WARN|BLOCK) + reden.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function brancheCompatibility(array $branche): array
    {
        $isFiscaleEenheid = (bool) ($branche['fiscaleEenheid'] ?? false);
        if ($isFiscaleEenheid === true) {
            return [
                'verdict' => 'BLOCK',
                'reden'   => 'KOR aanmelden door een fiscale eenheid is niet mogelijk; '
                    .'de eenheid zelf moet aanmelden, niet een individuele deelnemer.',
            ];
        }

        $fullExempt = (bool) ($branche['art11Vrijstelling'] ?? false);
        if ($fullExempt === true) {
            return [
                'verdict' => 'BLOCK',
                'reden'   => 'Onderneming valt volledig onder art. 11 OB; KOR levert geen voordeel en blokkeert voorbelasting-aftrek.',
            ];
        }

        $isMixed = (bool) ($branche['vrijgesteldEnBelast'] ?? false);
        if ($isMixed === true) {
            return [
                'verdict' => 'WARN',
                'reden'   => 'Mixed-use vrijgesteld + belast: effective KOR-drempel wordt berekend over alleen het belaste deel.',
            ];
        }

        $isIntra = (bool) ($branche['intracommunautair'] ?? false);
        if ($isIntra === true) {
            return [
                'verdict' => 'WARN',
                'reden'   => 'Bedrijf doet structureel intracommunautaire leveringen; overweeg OSS-regime als alternatief.',
            ];
        }

        return ['verdict' => 'OK', 'reden' => 'Geen branche-specifieke contra-indicaties; KOR is geschikt.'];

    }//end brancheCompatibility()

    /**
     * Compute the voorraad-correctie suppletie for a Regulier -> KOR transition (REQ-KOR-011a).
     *
     * On Regulier -> KOR aanmelding, voorbelasting-aftrek that was claimed on
     * investeringsgoederen still held at ingangsDatum must be partially returned
     * per herzieningsregels: corrected = original * (remainingMonths / totalMonths).
     * Equipment lifetime defaults to 60 months, real estate to 120 months. The
     * suppletie aangifte sums the corrections per asset. Arithmetic in cents.
     *
     * @param array<int,array<string,mixed>> $assets Asset records with vatCents + remainingMonths + totalMonths.
     *
     * @return int Total voorraad-correctie suppletie in cents.
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function voorraadCorrectieCents(array $assets): int
    {
        $total = 0;
        foreach ($assets as $asset) {
            $vatCents        = (int) ($asset['vatCents'] ?? 0);
            $remainingMonths = (int) ($asset['remainingMonths'] ?? 0);
            $totalMonths     = (int) ($asset['totalMonths'] ?? 0);
            $total          += $this->herzieningRecoveryCents(
                vatCents: $vatCents,
                remainingMonths: $remainingMonths,
                totalMonths: $totalMonths
            );
        }

        return $total;

    }//end voorraadCorrectieCents()

    /**
     * Compute proportional voorbelasting recovery per herzieningsregels (REQ-KOR-006, REQ-KOR-011).
     *
     * On revocatie, voorbelasting on an asset purchased during KOR (where no aftrek
     * was claimed) can be reclaimed proportional to the asset's remaining useful life:
     * recovery = btwBedrag * (remainingMonths / totalMonths). Arithmetic in cents.
     *
     * @param int $vatCents        Original voorbelasting on the asset, in cents.
     * @param int $remainingMonths Remaining useful-life months at revocatie.
     * @param int $totalMonths     Total useful-life months (60 for equipment, 120 for real estate).
     *
     * @return int Recoverable voorbelasting in cents (clamped to 0..vatCents).
     *
     * @spec openspec/specs/bookkeeping-kor-kleine-ondernemersregeling/spec.md
     */
    public function herzieningRecoveryCents(int $vatCents, int $remainingMonths, int $totalMonths): int
    {
        if ($totalMonths <= 0) {
            return 0;
        }

        $remaining = max(0, min($remainingMonths, $totalMonths));
        $recovery  = (int) round(($vatCents * $remaining) / $totalMonths);
        return max(0, min($recovery, $vatCents));

    }//end herzieningRecoveryCents()
}//end class
