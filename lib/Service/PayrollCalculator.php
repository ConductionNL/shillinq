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
class PayrollCalculator {

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
		'WEEK' => 52,
		'4_WEEKS' => 13,
		'MONTH' => 12,
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
	public function toCents(mixed $amount): int {
		return (int)round(((float)($amount ?? 0)) * 100);
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
	public function fromCents(int $cents): float {
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
	public function totaalBruto(array $componenten): float {
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
	 * @param float $kilometers Reimbursed kilometres.
	 * @param float $ratePerKm Employer's per-km rate.
	 *
	 * @return float Tax-free reimbursement amount.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function belastingvrijeKilometervergoeding(float $kilometers, float $ratePerKm): float {
		$rate = min($ratePerKm, self::KILOMETERVERGOEDING_MAX_2026);
		if ($rate < 0.0) {
			$rate = 0.0;
		}

		return $this->fromCents(cents: $this->toCents(amount: ($kilometers * $rate)));
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
	public function thuiswerkvergoeding(float $thuiswerkdagen): float {
		if ($thuiswerkdagen <= 0.0) {
			return 0.0;
		}

		return $this->fromCents(cents: $this->toCents(amount: ($thuiswerkdagen * self::THUISWERKVERGOEDING_PER_DAG_2026)));
	}//end thuiswerkvergoeding()

	/**
	 * Compute the 30%-ruling tax-free portion of gross (REQ-PAY-015).
	 *
	 * @param float $grossPay Gross wage subject to the ruling.
	 * @param bool $applies Whether the employee has the 30%-ruling.
	 *
	 * @return float Tax-free portion (0 when the ruling does not apply).
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function expat30PctVrijstelling(float $grossPay, bool $applies): float {
		if ($applies === false || $grossPay <= 0.0) {
			return 0.0;
		}

		return $this->fromCents(cents: $this->toCents(amount: ($grossPay * self::EXPAT_30PCT_FRACTIE)));
	}//end expat30PctVrijstelling()

	/**
	 * Derive the taxable wage (fiscaal loon) from gross minus tax-free parts (REQ-PAY-001).
	 *
	 * @param float $totalGross Total gross.
	 * @param float $belastingvrijTotaal Sum of tax-free allowances/exemptions.
	 *
	 * @return float Taxable wage, floored at zero.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function fiscaalLoon(float $totalGross, float $belastingvrijTotaal): float {
		$cents = ($this->toCents(amount: $totalGross) - $this->toCents(amount: $belastingvrijTotaal));
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
	 * @param float $fiscalPay Taxable wage for the period.
	 * @param array<int,array<string,mixed>> $tableRules Bracket rows from LoonheffingTabel2026.
	 *
	 * @return float Wage tax for the period.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function loonheffingUitTabel(float $fiscalPay, array $tableRules): float {
		if ($fiscalPay <= 0.0 || $tableRules === []) {
			return 0.0;
		}

		$rule = $this->vindBracket(amount: $fiscalPay, tableRules: $tableRules);
		if ($rule === null) {
			return 0.0;
		}

		$from = (float)($rule['from'] ?? 0);
		$percentage = (float)($rule['percentage'] ?? 0);
		$vasteHeffing = (float)($rule['vasteHeffing'] ?? 0);
		$discount = (float)($rule['korting'] ?? 0);

		$variabel = (int)round(($percentage * ($fiscalPay - $from)) * 100);
		$lhCents = ($this->toCents(amount: $vasteHeffing) + $variabel - $this->toCents(amount: $discount));

		return $this->fromCents(cents: max(0, $lhCents));
	}//end loonheffingUitTabel()

	/**
	 * Find the bracket a wage falls in.
	 *
	 * @param float $amount Wage.
	 * @param array<int,array<string,mixed>> $tableRules Bracket rows.
	 *
	 * @return array<string,mixed>|null The matching bracket or null.
	 */
	private function vindBracket(float $amount, array $tableRules): ?array {
		foreach ($tableRules as $rule) {
			$from = (float)($rule['from'] ?? 0);
			$tot = ($rule['tot'] ?? null);
			if ($amount < $from) {
				continue;
			}

			if ($tot === null || $amount <= (float)$tot) {
				return $rule;
			}
		}

		return null;
	}//end vindBracket()

	/**
	 * Pro-rate an annual cap down to one pay period (REQ-PAY-003, REQ-PAY-014).
	 *
	 * @param float $yearAmount Annual cap amount.
	 * @param string $periodType WEEK|4WEKEN|MAAND.
	 *
	 * @return float Per-period cap.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function periodeMaximum(float $yearAmount, string $periodType): float {
		$delers = self::PERIODES_PER_JAAR[$periodType] ?? 12;
		return $this->fromCents(cents: (int)round(($this->toCents(amount: $yearAmount) / $delers)));
	}//end periodeMaximum()

	/**
	 * Compute the employer-share SV premiums for a payslip (REQ-PAY-003).
	 *
	 * The premium wage is capped at the per-period maximum (annual cap pro-rated).
	 * AWF tariff is selected by the employer bucket; AOF by employer size; WHK by
	 * the sector rate the caller passes in. Returns the per-component breakdown
	 * plus totaal_werkgever, all in euros.
	 *
	 * @param float $contributionPaySV Uncapped SV wage.
	 * @param string $periodType WEEK|4WEKEN|MAAND.
	 * @param string $awfRate LAAG|HOOG.
	 * @param bool $kleineWerkgever Whether the AOF-small rate applies.
	 * @param float $whkRate Sector WHK rate (fraction).
	 * @param float $wkoRate Childcare (WKO) rate (fraction).
	 *
	 * @return array{premieloon_gemaximeerd:float,awf:float,aof_basis:float,whk:float,wko:float,totaal_werkgever:float}
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function employerSocialInsurancePremiums(
		float $contributionPaySV,
		string $periodType,
		string $awfRate,
		bool $kleineWerkgever,
		float $whkRate,
		float $wkoRate,
	): array {
		$maxPeriod = $this->periodeMaximum(yearAmount: self::MAX_PREMIELOON_SV_JAAR_2026, periodType: $periodType);
		$basis = min($contributionPaySV, $maxPeriod);
		if ($basis < 0.0) {
			$basis = 0.0;
		}

		$awfPct = self::AWF_LAAG_2026;
		if ($awfRate === 'HIGH') {
			$awfPct = self::AWF_HOOG_2026;
		}

		$aofPct = self::AOF_GROOT_2026;
		if ($kleineWerkgever === true) {
			$aofPct = self::AOF_KLEIN_2026;
		}

		$awf = $this->pct(amount: $basis, fractie: $awfPct);
		$aof = $this->pct(amount: $basis, fractie: $aofPct);
		$whk = $this->pct(amount: $basis, fractie: max(0.0, $whkRate));
		$wko = $this->pct(amount: $basis, fractie: max(0.0, $wkoRate));

		$totalCents = ($this->toCents(amount: $awf) + $this->toCents(amount: $aof) + $this->toCents(amount: $whk) + $this->toCents(amount: $wko));

		return [
			'premieloon_gemaximeerd' => $basis,
			'awf' => $awf,
			'aof_basis' => $aof,
			'whk' => $whk,
			'wko' => $wko,
			'totaal_werkgever' => $this->fromCents(cents: $totalCents),
		];

	}//end employerSocialInsurancePremiums()

	/**
	 * Compute the employer ZVW contribution (REQ-PAY-004).
	 *
	 * ZVW is an employer-only contribution capped at the per-period ZVW maximum;
	 * the employee is not withheld in this engine's scope (design.md D7).
	 *
	 * @param float $contributionPaySV Uncapped SV wage.
	 * @param string $periodType WEEK|4WEKEN|MAAND.
	 * @param string $zvwRate LAAG|HOOG.
	 *
	 * @return array{basis:float,rate:float,afgedragen_wg:float}
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function zvwWerkgever(float $contributionPaySV, string $periodType, string $zvwRate): array {
		$maxPeriod = $this->periodeMaximum(yearAmount: self::MAX_ZVW_PREMIELOON_JAAR_2026, periodType: $periodType);
		$basis = min(max(0.0, $contributionPaySV), $maxPeriod);
		$rate = self::ZVW_TARIEF_LAAG_2026;
		if ($zvwRate === 'HIGH') {
			$rate = self::ZVW_TARIEF_HOOG_2026;
		}

		return [
			'basis' => $basis,
			'rate' => $rate,
			'afgedragen_wg' => $this->pct(amount: $basis, fractie: $rate),
		];

	}//end zvwWerkgever()

	/**
	 * Compute the pension employer/employee shares (REQ-PAY-001, design.md D6).
	 *
	 * @param float $basis Pension base (gross or pensioengevend loon).
	 * @param float $pctWerkgever Employer share fraction.
	 * @param float $pctEmployee Employee share fraction.
	 *
	 * @return array{premie_wg_aandeel:float,premie_wn_aandeel:float}
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function pensioen(float $basis, float $pctWerkgever, float $pctEmployee): array {
		$base = max(0.0, $basis);
		return [
			'premie_wg_aandeel' => $this->pct(amount: $base, fractie: max(0.0, $pctWerkgever)),
			'premie_wn_aandeel' => $this->pct(amount: $base, fractie: max(0.0, $pctEmployee)),
		];

	}//end pensioen()

	/**
	 * Compute the holiday-allowance accrual for one period (REQ-PAY-005).
	 *
	 * 8% (or the employee's percentage, floored at the WML minimum) of gross is
	 * reserved each period; uitbetaling is a separate gross component the caller
	 * adds in the payout month (design.md D4).
	 *
	 * @param float $totalGross Gross for the period.
	 * @param float $pct Holiday-allowance percentage.
	 *
	 * @return float Period accrual.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function vakantiegeldOpbouw(float $totalGross, float $pct): float {
		$effectief = max($pct, self::VAKANTIEGELD_MIN_PCT);
		if ($totalGross <= 0.0) {
			return 0.0;
		}

		return $this->pct(amount: $totalGross, fractie: $effectief);
	}//end vakantiegeldOpbouw()

	/**
	 * Pro-rate gross when an employee starts/ends mid-period (REQ-PAY-014).
	 *
	 * @param float $volledigGross Full-period gross.
	 * @param int $gewerkteDays Worked days in the period.
	 * @param int $periodDays Total working days in the period.
	 *
	 * @return float Pro-rated gross.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function proRataBruto(float $volledigGross, int $gewerkteDays, int $periodDays): float {
		if ($periodDays <= 0 || $gewerkteDays >= $periodDays) {
			return $this->fromCents(cents: $this->toCents(amount: $volledigGross));
		}

		if ($gewerkteDays <= 0) {
			return 0.0;
		}

		$cents = (int)round(($this->toCents(amount: $volledigGross) * $gewerkteDays) / $periodDays);
		return $this->fromCents(cents: $cents);
	}//end proRataBruto()

	/**
	 * Compute the net amount paid out (REQ-PAY-001).
	 *
	 * Net = taxable wage - wage tax - employee SV withholding - employee pension
	 * share + tax-free allowances (which are paid out but never taxed).
	 *
	 * @param float $fiscalPay Taxable wage.
	 * @param float $payrollTax Wage tax withheld.
	 * @param float $inhoudingSVEmployee Employee SV withholding.
	 * @param float $pensioenEmployee Employee pension withholding.
	 * @param float $belastingvrijTotaal Tax-free allowances paid out.
	 *
	 * @return float Net paid.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function nettoBetaald(
		float $fiscalPay,
		float $payrollTax,
		float $inhoudingSVEmployee,
		float $pensioenEmployee,
		float $belastingvrijTotaal,
	): float {
		$cents = $this->toCents(amount: $fiscalPay);
		$cents -= $this->toCents(amount: $payrollTax);
		$cents -= $this->toCents(amount: $inhoudingSVEmployee);
		$cents -= $this->toCents(amount: $pensioenEmployee);
		$cents += $this->toCents(amount: $belastingvrijTotaal);

		return $this->fromCents(cents: $cents);
	}//end nettoBetaald()

	/**
	 * Check the DGA gebruikelijk-loon rule and return a warning, never a block (REQ-PAY-009).
	 *
	 * @param bool $isDga Whether the employee is a DGA.
	 * @param float $annualPayGross Annual gross wage.
	 * @param string|null $exception Recorded justification, if any.
	 *
	 * @return array{onderNorm:bool,norm:float,waarschuwing:string|null}
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function dgaGebruikelijkLoonCheck(bool $isDga, float $annualPayGross, ?string $exception): array {
		$norm = self::DGA_GEBRUIKELIJK_LOON_NORM_2026;
		if ($isDga === false) {
			return ['onderNorm' => false, 'norm' => $norm, 'waarschuwing' => null];
		}

		$underNorm = ($annualPayGross < $norm);
		$waarschuwing = null;
		if ($underNorm === true && ($exception === null || trim($exception) === '')) {
			$waarschuwing = sprintf('DGA-loon onder gebruikelijk-loonnorm 2026 (EUR %s)', number_format($norm, 0, ',', '.'));
		}

		return ['onderNorm' => $underNorm, 'norm' => $norm, 'waarschuwing' => $waarschuwing];
	}//end dgaGebruikelijkLoonCheck()

	/**
	 * Multiply an amount by a fraction, in cents, rounded to two decimals.
	 *
	 * @param float $amount Base amount.
	 * @param float $fractie Fraction (e.g. 0.0532).
	 *
	 * @return float Result.
	 */
	private function pct(float $amount, float $fractie): float {
		return $this->fromCents(cents: (int)round(($amount * $fractie) * 100));
	}//end pct()
}//end class
