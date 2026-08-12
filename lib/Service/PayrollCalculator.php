<?php

/**
 * Payroll Calculator
 *
 * Pure-logic, side-effect-free helper for the NL loonadministratie engine
 * (REQ-PAY-001 bruto->netto, REQ-PAY-003 premies SV, REQ-PAY-004 ZVW,
 * REQ-PAY-005 vakantietoeslag, REQ-PAY-007 kilometervergoeding, REQ-PAY-008
 * thuiswerkvergoeding, REQ-PAY-009 DGA gebruikelijk loon, REQ-PAY-014 pro-rata
 * mutaties, REQ-PAY-015 30%-regeling). Holds every arithmetic rule so it is unit
 * testable in isolation; PayrollService wires it to live OpenRegister data.
 *
 * All money arithmetic is performed in integer cents to avoid IEEE-754 drift,
 * mirroring TrialBalanceCalculator / BalanceGuard, and rounded back to two
 * decimals only at the boundary. No BSN or special-category data is logged here.
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
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free Dutch payroll arithmetic.
 *
 * Every method takes plain arrays/scalars and returns plain arrays/scalars; no
 * OpenRegister, no IO. The 2026 statutory constants (premium caps, tax-free
 * allowance rates, DGA norm) are class constants so a yearly update is a single
 * edit, and the immutable LoonheffingTabel2026 brackets are passed in by the
 * caller (design.md D2 — never hardcoded per calculation).
 *
 * The public surface is deliberately one method per statutory rule (loonheffing,
 * SV premies, ZVW, vakantietoeslag, DGA, toelagen, pro-rata, netto) so each is
 * independently unit-testable against its REQ-PAY acceptance criteria; the count
 * reflects the breadth of the Dutch payroll domain rather than low cohesion.
 *
 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 */
class PayrollCalculator
{

    /**
     * Maximum SV premium wage per year (2026), in euros (REQ-PAY-003).
     */
    public const MAX_PREMIELOON_SV_JAAR_2026 = 74480.0;

    /**
     * Maximum ZVW contribution wage per year (2026), in euros (REQ-PAY-004).
     */
    public const MAX_ZVW_PREMIELOON_JAAR_2026 = 71628.0;

    /**
     * ZVW low employer tariff (2026) (REQ-PAY-004).
     */
    public const ZVW_TARIEF_LAAG_2026 = 0.0532;

    /**
     * ZVW high employer tariff (2026) (REQ-PAY-004).
     */
    public const ZVW_TARIEF_HOOG_2026 = 0.0657;

    /**
     * AWF low premium (2026) (REQ-PAY-003).
     */
    public const AWF_LAAG_2026 = 0.0264;

    /**
     * AWF high premium (2026) (REQ-PAY-003).
     */
    public const AWF_HOOG_2026 = 0.0355;

    /**
     * AOF small-employer basis premium (2026) (REQ-PAY-003).
     */
    public const AOF_KLEIN_2026 = 0.0538;

    /**
     * AOF large-employer basis premium (2026) (REQ-PAY-003).
     */
    public const AOF_GROOT_2026 = 0.0650;

    /**
     * Tax-free kilometre allowance cap per km (2026) (REQ-PAY-007).
     */
    public const KILOMETERVERGOEDING_MAX_2026 = 0.23;

    /**
     * Tax-free home-office allowance per day (2026) (REQ-PAY-008).
     */
    public const THUISWERKVERGOEDING_PER_DAG_2026 = 2.40;

    /**
     * 30%-ruling exempt fraction (REQ-PAY-015).
     */
    public const EXPAT_30PCT_FRACTIE = 0.30;

    /**
     * DGA gebruikelijk-loon norm for 2026, in euros (REQ-PAY-009).
     */
    public const DGA_GEBRUIKELIJK_LOON_NORM_2026 = 56000.0;

    /**
     * Statutory minimum holiday-allowance percentage (WML) (REQ-PAY-005).
     */
    public const VAKANTIEGELD_MIN_PCT = 0.08;

    /**
     * Periods per year per pay-period type, used to pro-rate annual caps.
     */
    private const PERIODES_PER_JAAR = [
        'WEEK'   => 52,
        '4WEKEN' => 13,
        'MAAND'  => 12,
    ];

    /**
     * Convert a money amount to integer cents.
     *
     * @param mixed $amount Money amount (float|int|numeric-string|null).
     *
     * @return int Whole cents.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function toCents(mixed $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);

    }//end toCents()

    /**
     * Convert integer cents back to a 2-decimal money amount.
     *
     * @param int $cents Whole cents.
     *
     * @return float Money amount.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function fromCents(int $cents): float
    {
        return round(($cents / 100), 2);

    }//end fromCents()

    /**
     * Sum the gross line items into a total gross amount (REQ-PAY-001).
     *
     * Any non-numeric entry (e.g. a nested object) and the reserved totaal_bruto
     * key are ignored, so callers can pass the component map verbatim.
     *
     * @param array<string,mixed> $componenten Gross components map.
     *
     * @return float Total gross.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function totaalBruto(array $componenten): float
    {
        $cents = 0;
        foreach ($componenten as $key => $value) {
            if ($key === 'totaal_bruto' || is_numeric($value) === false) {
                continue;
            }

            $cents += $this->toCents(amount: $value);
        }

        return $this->fromCents(cents: $cents);

    }//end totaalBruto()

    /**
     * Cap a kilometre reimbursement at the tax-free rate (REQ-PAY-007).
     *
     * Returns the portion that stays tax-free (kilometers x min(rate, cap)). Any
     * excess over the cap is the caller's responsibility to treat as taxable.
     *
     * @param float $kilometers  Reimbursed kilometres.
     * @param float $tariefPerKm Employer's per-km rate.
     *
     * @return float Tax-free reimbursement amount.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function belastingvrijeKilometervergoeding(float $kilometers, float $tariefPerKm): float
    {
        $tarief = min($tariefPerKm, self::KILOMETERVERGOEDING_MAX_2026);
        if ($tarief < 0.0) {
            $tarief = 0.0;
        }

        return $this->fromCents(cents: $this->toCents(amount: ($kilometers * $tarief)));

    }//end belastingvrijeKilometervergoeding()

    /**
     * Compute the tax-free home-office allowance for a period (REQ-PAY-008).
     *
     * @param float $thuiswerkdagen Home-office days in the period.
     *
     * @return float Tax-free home-office allowance.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function thuiswerkvergoeding(float $thuiswerkdagen): float
    {
        if ($thuiswerkdagen <= 0.0) {
            return 0.0;
        }

        return $this->fromCents(cents: $this->toCents(amount: ($thuiswerkdagen * self::THUISWERKVERGOEDING_PER_DAG_2026)));

    }//end thuiswerkvergoeding()

    /**
     * Compute the 30%-ruling tax-free portion of gross (REQ-PAY-015).
     *
     * @param float $brutoLoon Gross wage subject to the ruling.
     * @param bool  $applies   Whether the employee has the 30%-ruling.
     *
     * @return float Tax-free portion (0 when the ruling does not apply).
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function expat30PctVrijstelling(float $brutoLoon, bool $applies): float
    {
        if ($applies === false || $brutoLoon <= 0.0) {
            return 0.0;
        }

        return $this->fromCents(cents: $this->toCents(amount: ($brutoLoon * self::EXPAT_30PCT_FRACTIE)));

    }//end expat30PctVrijstelling()

    /**
     * Derive the taxable wage (fiscaal loon) from gross minus tax-free parts (REQ-PAY-001).
     *
     * @param float $totaalBruto         Total gross.
     * @param float $belastingvrijTotaal Sum of tax-free allowances/exemptions.
     *
     * @return float Taxable wage, floored at zero.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function fiscaalLoon(float $totaalBruto, float $belastingvrijTotaal): float
    {
        $cents = ($this->toCents(amount: $totaalBruto) - $this->toCents(amount: $belastingvrijTotaal));
        return $this->fromCents(cents: max(0, $cents));

    }//end fiscaalLoon()

    /**
     * Look up the wage tax (loonheffing) for a taxable wage in a bracket table (REQ-PAY-001, REQ-PAY-002).
     *
     * Each bracket is {vanaf, tot, percentage, vasteHeffing, korting}; the wage
     * falls in the bracket where vanaf <= wage <= tot (tot null = open-ended).
     * LH = vasteHeffing + percentage x (wage - vanaf) - korting, floored at zero.
     * korting is only subtracted when the table is the met-korting variant
     * (already encoded in the bracket rows the caller supplies).
     *
     * @param float                          $fiscaalLoon Taxable wage for the period.
     * @param array<int,array<string,mixed>> $tabelRegels Bracket rows from LoonheffingTabel2026.
     *
     * @return float Wage tax for the period.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function loonheffingUitTabel(float $fiscaalLoon, array $tabelRegels): float
    {
        if ($fiscaalLoon <= 0.0 || $tabelRegels === []) {
            return 0.0;
        }

        $regel = $this->vindBracket(bedrag: $fiscaalLoon, tabelRegels: $tabelRegels);
        if ($regel === null) {
            return 0.0;
        }

        $vanaf        = (float) ($regel['from'] ?? 0);
        $percentage   = (float) ($regel['percentage'] ?? 0);
        $vasteHeffing = (float) ($regel['vasteHeffing'] ?? 0);
        $korting      = (float) ($regel['korting'] ?? 0);

        $variabel = (int) round(($percentage * ($fiscaalLoon - $vanaf)) * 100);
        $lhCents  = ($this->toCents(amount: $vasteHeffing) + $variabel - $this->toCents(amount: $korting));

        return $this->fromCents(cents: max(0, $lhCents));

    }//end loonheffingUitTabel()

    /**
     * Find the bracket a wage falls in.
     *
     * @param float                          $bedrag      Wage.
     * @param array<int,array<string,mixed>> $tabelRegels Bracket rows.
     *
     * @return array<string,mixed>|null The matching bracket or null.
     */
    private function vindBracket(float $bedrag, array $tabelRegels): ?array
    {
        foreach ($tabelRegels as $regel) {
            $vanaf = (float) ($regel['from'] ?? 0);
            $tot   = ($regel['tot'] ?? null);
            if ($bedrag < $vanaf) {
                continue;
            }

            if ($tot === null || $bedrag <= (float) $tot) {
                return $regel;
            }
        }

        return null;

    }//end vindBracket()

    /**
     * Pro-rate an annual cap down to one pay period (REQ-PAY-003, REQ-PAY-014).
     *
     * @param float  $jaarBedrag  Annual cap amount.
     * @param string $periodeType WEEK|4WEKEN|MAAND.
     *
     * @return float Per-period cap.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function periodeMaximum(float $jaarBedrag, string $periodeType): float
    {
        $delers = self::PERIODES_PER_JAAR[$periodeType] ?? 12;
        return $this->fromCents(cents: (int) round(($this->toCents(amount: $jaarBedrag) / $delers)));

    }//end periodeMaximum()

    /**
     * Compute the employer-share SV premiums for a payslip (REQ-PAY-003).
     *
     * The premium wage is capped at the per-period maximum (annual cap pro-rated).
     * AWF tariff is selected by the employer bucket; AOF by employer size; WHK by
     * the sector rate the caller passes in. Returns the per-component breakdown
     * plus totaal_werkgever, all in euros.
     *
     * @param float  $premieloonSV    Uncapped SV wage.
     * @param string $periodeType     WEEK|4WEKEN|MAAND.
     * @param string $awfTarief       LAAG|HOOG.
     * @param bool   $kleineWerkgever Whether the AOF-small rate applies.
     * @param float  $whkTarief       Sector WHK rate (fraction).
     * @param float  $wkoTarief       Childcare (WKO) rate (fraction).
     *
     * @return array{premieloon_gemaximeerd:float,awf:float,aof_basis:float,whk:float,wko:float,totaal_werkgever:float}
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function premiesSVWerkgever(
        float $premieloonSV,
        string $periodeType,
        string $awfTarief,
        bool $kleineWerkgever,
        float $whkTarief,
        float $wkoTarief
    ): array {
        $maxPeriode = $this->periodeMaximum(jaarBedrag: self::MAX_PREMIELOON_SV_JAAR_2026, periodeType: $periodeType);
        $grondslag  = min($premieloonSV, $maxPeriode);
        if ($grondslag < 0.0) {
            $grondslag = 0.0;
        }

        $awfPct = self::AWF_LAAG_2026;
        if ($awfTarief === 'HOOG') {
            $awfPct = self::AWF_HOOG_2026;
        }

        $aofPct = self::AOF_GROOT_2026;
        if ($kleineWerkgever === true) {
            $aofPct = self::AOF_KLEIN_2026;
        }

        $awf = $this->pct(bedrag: $grondslag, fractie: $awfPct);
        $aof = $this->pct(bedrag: $grondslag, fractie: $aofPct);
        $whk = $this->pct(bedrag: $grondslag, fractie: max(0.0, $whkTarief));
        $wko = $this->pct(bedrag: $grondslag, fractie: max(0.0, $wkoTarief));

        $totaalCents = ($this->toCents(amount: $awf) + $this->toCents(amount: $aof) + $this->toCents(amount: $whk) + $this->toCents(amount: $wko));

        return [
            'premieloon_gemaximeerd' => $grondslag,
            'awf'                    => $awf,
            'aof_basis'              => $aof,
            'whk'                    => $whk,
            'wko'                    => $wko,
            'totaal_werkgever'       => $this->fromCents(cents: $totaalCents),
        ];

    }//end premiesSVWerkgever()

    /**
     * Compute the employer ZVW contribution (REQ-PAY-004).
     *
     * ZVW is an employer-only contribution capped at the per-period ZVW maximum;
     * the employee is not withheld in this engine's scope (design.md D7).
     *
     * @param float  $premieloonSV Uncapped SV wage.
     * @param string $periodeType  WEEK|4WEKEN|MAAND.
     * @param string $zvwTarief    LAAG|HOOG.
     *
     * @return array{grondslag:float,tarief:float,afgedragen_wg:float}
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function zvwWerkgever(float $premieloonSV, string $periodeType, string $zvwTarief): array
    {
        $maxPeriode = $this->periodeMaximum(jaarBedrag: self::MAX_ZVW_PREMIELOON_JAAR_2026, periodeType: $periodeType);
        $grondslag  = min(max(0.0, $premieloonSV), $maxPeriode);
        $tarief     = self::ZVW_TARIEF_LAAG_2026;
        if ($zvwTarief === 'HOOG') {
            $tarief = self::ZVW_TARIEF_HOOG_2026;
        }

        return [
            'basis'     => $grondslag,
            'rate'        => $tarief,
            'afgedragen_wg' => $this->pct(bedrag: $grondslag, fractie: $tarief),
        ];

    }//end zvwWerkgever()

    /**
     * Compute the pension employer/employee shares (REQ-PAY-001, design.md D6).
     *
     * @param float $grondslag    Pension base (gross or pensioengevend loon).
     * @param float $pctWerkgever Employer share fraction.
     * @param float $pctWerknemer Employee share fraction.
     *
     * @return array{premie_wg_aandeel:float,premie_wn_aandeel:float}
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function pensioen(float $grondslag, float $pctWerkgever, float $pctWerknemer): array
    {
        $base = max(0.0, $grondslag);
        return [
            'premie_wg_aandeel' => $this->pct(bedrag: $base, fractie: max(0.0, $pctWerkgever)),
            'premie_wn_aandeel' => $this->pct(bedrag: $base, fractie: max(0.0, $pctWerknemer)),
        ];

    }//end pensioen()

    /**
     * Compute the holiday-allowance accrual for one period (REQ-PAY-005).
     *
     * 8% (or the employee's percentage, floored at the WML minimum) of gross is
     * reserved each period; uitbetaling is a separate gross component the caller
     * adds in the payout month (design.md D4).
     *
     * @param float $totaalBruto Gross for the period.
     * @param float $pct         Holiday-allowance percentage.
     *
     * @return float Period accrual.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function vakantiegeldOpbouw(float $totaalBruto, float $pct): float
    {
        $effectief = max($pct, self::VAKANTIEGELD_MIN_PCT);
        if ($totaalBruto <= 0.0) {
            return 0.0;
        }

        return $this->pct(bedrag: $totaalBruto, fractie: $effectief);

    }//end vakantiegeldOpbouw()

    /**
     * Pro-rate gross when an employee starts/ends mid-period (REQ-PAY-014).
     *
     * @param float $volledigBruto Full-period gross.
     * @param int   $gewerkteDagen Worked days in the period.
     * @param int   $periodeDagen  Total working days in the period.
     *
     * @return float Pro-rated gross.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function proRataBruto(float $volledigBruto, int $gewerkteDagen, int $periodeDagen): float
    {
        if ($periodeDagen <= 0 || $gewerkteDagen >= $periodeDagen) {
            return $this->fromCents(cents: $this->toCents(amount: $volledigBruto));
        }

        if ($gewerkteDagen <= 0) {
            return 0.0;
        }

        $cents = (int) round(($this->toCents(amount: $volledigBruto) * $gewerkteDagen) / $periodeDagen);
        return $this->fromCents(cents: $cents);

    }//end proRataBruto()

    /**
     * Compute the net amount paid out (REQ-PAY-001).
     *
     * Net = taxable wage - wage tax - employee SV withholding - employee pension
     * share + tax-free allowances (which are paid out but never taxed).
     *
     * @param float $fiscaalLoon          Taxable wage.
     * @param float $loonheffing          Wage tax withheld.
     * @param float $inhoudingSVWerknemer Employee SV withholding.
     * @param float $pensioenWerknemer    Employee pension withholding.
     * @param float $belastingvrijTotaal  Tax-free allowances paid out.
     *
     * @return float Net paid.
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function nettoBetaald(
        float $fiscaalLoon,
        float $loonheffing,
        float $inhoudingSVWerknemer,
        float $pensioenWerknemer,
        float $belastingvrijTotaal
    ): float {
        $cents  = $this->toCents(amount: $fiscaalLoon);
        $cents -= $this->toCents(amount: $loonheffing);
        $cents -= $this->toCents(amount: $inhoudingSVWerknemer);
        $cents -= $this->toCents(amount: $pensioenWerknemer);
        $cents += $this->toCents(amount: $belastingvrijTotaal);

        return $this->fromCents(cents: $cents);

    }//end nettoBetaald()

    /**
     * Check the DGA gebruikelijk-loon rule and return a warning, never a block (REQ-PAY-009).
     *
     * @param bool        $isDga         Whether the employee is a DGA.
     * @param float       $jaarloonBruto Annual gross wage.
     * @param string|null $uitzondering  Recorded justification, if any.
     *
     * @return array{onderNorm:bool,norm:float,waarschuwing:string|null}
     *
     * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
     */
    public function dgaGebruikelijkLoonCheck(bool $isDga, float $jaarloonBruto, ?string $uitzondering): array
    {
        $norm = self::DGA_GEBRUIKELIJK_LOON_NORM_2026;
        if ($isDga === false) {
            return ['onderNorm' => false, 'norm' => $norm, 'waarschuwing' => null];
        }

        $onderNorm    = ($jaarloonBruto < $norm);
        $waarschuwing = null;
        if ($onderNorm === true && ($uitzondering === null || trim($uitzondering) === '')) {
            $waarschuwing = sprintf('DGA-loon onder gebruikelijk-loonnorm 2026 (EUR %s)', number_format($norm, 0, ',', '.'));
        }

        return ['onderNorm' => $onderNorm, 'norm' => $norm, 'waarschuwing' => $waarschuwing];

    }//end dgaGebruikelijkLoonCheck()

    /**
     * Multiply an amount by a fraction, in cents, rounded to two decimals.
     *
     * @param float $bedrag  Base amount.
     * @param float $fractie Fraction (e.g. 0.0532).
     *
     * @return float Result.
     */
    private function pct(float $bedrag, float $fractie): float
    {
        return $this->fromCents(cents: (int) round(($bedrag * $fractie) * 100));

    }//end pct()
}//end class
