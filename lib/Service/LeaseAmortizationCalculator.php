<?php

/**
 * Lease Amortization Calculator
 *
 * Pure-logic IFRS 16 amortization engine (REQ-LA-001..REQ-LA-006). Holds the
 * side-effect-free arithmetic that the lease accounting services apply after
 * fetching lease-contract data via the OpenRegister ObjectService: the present
 * value of the unavoidable payment stream, the opening Right-of-Use asset and
 * lease liability at commencement, the period-by-period interest/principal split
 * of the lease liability, and the straight-line depreciation of the RoU asset.
 *
 * All money arithmetic is performed in integer cents to avoid IEEE-754 drift,
 * mirroring TrialBalanceCalculator and BalanceGuard. The class has no
 * OpenRegister dependency so the IFRS 16 maths is unit-testable in isolation
 * against the IASB illustrative examples.
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
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free IFRS 16 lease amortization arithmetic helper.
 *
 * Every method takes plain scalars/arrays and returns plain arrays/scalars so
 * the logic is unit-testable in isolation. LeasePaymentScheduleService and
 * LeaseRecognitionService wire this helper to live lease-contract data fetched
 * from the OpenRegister ObjectService.
 *
 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
 */
class LeaseAmortizationCalculator {
	/**
	 * Number of payment periods in a year, keyed by payment frequency.
	 *
	 * @var array<string,int>
	 */
	private const PERIODS_PER_YEAR = [
		'monthly' => 12,
		'quarterly' => 4,
		'annual' => 1,
	];

	/**
	 * Convert a money amount to integer cents.
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100);
	}//end toCents()

	/**
	 * Convert integer cents back to a float money amount.
	 *
	 * @param int $cents Whole cents.
	 *
	 * @return float Money amount (two decimals).
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function fromCents(int $cents): float {
		return round($cents / 100, 2);
	}//end fromCents()

	/**
	 * Resolve the number of payment periods per year for a frequency.
	 *
	 * Falls back to monthly (12) for irregular / unknown frequencies so the
	 * caller always gets a deterministic per-period rate (REQ-LA-002).
	 *
	 * @param string $paymentFrequency monthly|quarterly|annual|irregular.
	 *
	 * @return int Periods per year (1, 4 or 12).
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function periodsPerYear(string $paymentFrequency): int {
		return (self::PERIODS_PER_YEAR[$paymentFrequency] ?? 12);
	}//end periodsPerYear()

	/**
	 * The periodic IBR derived from the annual IBR and the payment frequency.
	 *
	 * IFRS 16 uses the rate implicit in the lease or the lessee's incremental
	 * borrowing rate; the spec (REQ-LA-002) prescribes a simple per-period
	 * proration (annual / periods-per-year), matching the worked example.
	 *
	 * @param float $annualIbrPercent Annual IBR as a percentage (e.g. 4.0).
	 * @param string $paymentFrequency monthly|quarterly|annual|irregular.
	 *
	 * @return float Periodic rate as a fraction (e.g. 0.003333 for 4% monthly).
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function periodicRate(float $annualIbrPercent, string $paymentFrequency): float {
		$periods = $this->periodsPerYear(paymentFrequency: $paymentFrequency);
		if ($periods <= 0) {
			return 0.0;
		}

		return ($annualIbrPercent / 100.0) / $periods;
	}//end periodicRate()

	/**
	 * Present value of an ordinary annuity (payments in arrears).
	 *
	 * PV = pmt × (1 − (1 + r)^-n) / r, with the r → 0 limit being pmt × n.
	 * For payments in advance (annuity-due) the result is multiplied by (1 + r).
	 *
	 * @param float $paymentAmount Contractual payment per period.
	 * @param int $periods Number of payment periods (n).
	 * @param float $periodicRate Periodic discount rate as a fraction (r).
	 * @param string $paymentTiming in-advance|in-arrears (REQ-LC-002).
	 *
	 * @return float Present value of the payment stream.
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function presentValue(
		float $paymentAmount,
		int $periods,
		float $periodicRate,
		string $paymentTiming = 'in-arrears',
	): float {
		if ($periods <= 0 || $paymentAmount === 0.0) {
			return 0.0;
		}

		$presentValue = $paymentAmount * $periods;
		if ($periodicRate !== 0.0) {
			$presentValue = $paymentAmount * ((1 - ((1 + $periodicRate) ** (-1 * $periods))) / $periodicRate);
		}

		if ($paymentTiming === 'in-advance') {
			$presentValue *= (1 + $periodicRate);
		}

		return round($presentValue, 2);
	}//end presentValue()

	/**
	 * Present value of a single future amount (used for restoration obligations).
	 *
	 * PV = amount / (1 + annual-rate)^years (REQ-LA-005).
	 *
	 * @param float $amount Future (undiscounted) amount.
	 * @param float $annualRate Annual discount rate as a fraction (e.g. 0.045).
	 * @param float $years Number of years until the obligation falls due.
	 *
	 * @return float Present value of the obligation.
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function discountToPresent(float $amount, float $annualRate, float $years): float {
		if ($amount === 0.0) {
			return 0.0;
		}

		if ($annualRate <= 0.0 || $years <= 0.0) {
			return round($amount, 2);
		}

		return round($amount / ((1 + $annualRate) ** $years), 2);
	}//end discountToPresent()

	/**
	 * Compute the opening lease liability and RoU asset at commencement (REQ-LA-001).
	 *
	 * Opening Lease Liability = PV of unavoidable payments.
	 * Opening RoU Asset       = PV + initial-direct-costs + restoration-obligation-PV
	 *                            − lease-incentives-received − prepaid-rent + accrued-rent
	 * (REQ-LA-001, REQ-LA-005, REQ-LA-006).
	 *
	 * @param array<string,mixed> $lease A lease-contract array (decimal money fields).
	 *
	 * @return array{liability:float, rouAsset:float, restorationPv:float, periods:int}
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function openingBalances(array $lease): array {
		$periods = $this->scheduleLength(lease: $lease);
		$rate = $this->periodicRate(
			annualIbrPercent: (float)($lease['ibrPercent'] ?? 0),
			paymentFrequency: (string)($lease['paymentFrequency'] ?? 'monthly')
		);

		$liability = $this->presentValue(
			paymentAmount: (float)($lease['basePaymentAmount'] ?? 0),
			periods: $periods,
			periodicRate: $rate,
			paymentTiming: (string)($lease['paymentTiming'] ?? 'in-arrears')
		);

		$restorationPv = 0.0;
		$restoration = ($lease['restorationObligation'] ?? null);
		if (is_array($restoration) === true) {
			$restorationPv = $this->discountToPresent(
				amount: (float)($restoration['estimatedCost'] ?? 0),
				annualRate: (float)($restoration['discountRate'] ?? 0),
				years: ($periods / (float)$this->periodsPerYear(paymentFrequency: (string)($lease['paymentFrequency'] ?? 'monthly')))
			);
		}

		$rouCents = $this->toCents(amount: $liability);
		$rouCents += $this->toCents(amount: ($lease['initialDirectCosts'] ?? 0));
		$rouCents += $this->toCents(amount: $restorationPv);
		$rouCents -= $this->toCents(amount: ($lease['leaseIncentivesReceived'] ?? 0));
		$rouCents -= $this->toCents(amount: ($lease['prepaidRentBalance'] ?? 0));
		$rouCents += $this->toCents(amount: ($lease['accruedRentBalance'] ?? 0));

		return [
			'liability' => $liability,
			'rouAsset' => $this->fromCents(cents: $rouCents),
			'restorationPv' => $restorationPv,
			'periods' => $periods,
		];

	}//end openingBalances()

	/**
	 * Number of payment periods in the schedule, derived from the non-cancellable
	 * term plus any extension marked "reasonably certain" (REQ-LA-002, REQ-LC-002).
	 *
	 * @param array<string,mixed> $lease A lease-contract array.
	 *
	 * @return int Count of payment periods.
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function scheduleLength(array $lease): int {
		$months = (int)($lease['nonCancellableTermMonths'] ?? 0);
		$frequency = (string)($lease['paymentFrequency'] ?? 'monthly');

		foreach (($lease['extensionOptions'] ?? []) as $option) {
			if (($option['exerciseLikelihood'] ?? '') === 'reasonably-certain') {
				$months += (int)($option['months'] ?? 0);
			}
		}

		if ($months <= 0) {
			return 0;
		}

		return (int)ceil($months / (12 / $this->periodsPerYear(paymentFrequency: $frequency)));
	}//end scheduleLength()

	/**
	 * Build the full amortization schedule for a lease (REQ-LA-002).
	 *
	 * Produces one row per payment period carrying the opening/closing liability,
	 * the interest accrual, the principal portion of the payment, and the
	 * straight-line depreciation of the RoU asset. All intermediate arithmetic is
	 * in cents; the final amounts are returned as two-decimal floats. The closing
	 * liability of the final period is forced to exactly zero to absorb rounding.
	 *
	 * @param array<string,mixed> $lease A lease-contract array.
	 *
	 * @return array<int,array<string,float|int>> Amortization rows (period-sequence 1..N).
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function buildSchedule(array $lease): array {
		$opening = $this->openingBalances(lease: $lease);
		$periods = $opening['periods'];
		if ($periods <= 0) {
			return [];
		}

		$rate = $this->periodicRate(
			annualIbrPercent: (float)($lease['ibrPercent'] ?? 0),
			paymentFrequency: (string)($lease['paymentFrequency'] ?? 'monthly')
		);
		$paymentCents = $this->toCents(amount: ($lease['basePaymentAmount'] ?? 0));
		$liabilityCents = $this->toCents(amount: $opening['liability']);
		$rouCents = $this->toCents(amount: $opening['rouAsset']);
		$depreciationPer = $this->straightLinePerPeriodCents(totalCents: $rouCents, periods: $periods);

		$rows = [];
		for ($sequence = 1; $sequence <= $periods; $sequence++) {
			$openingLiability = $liabilityCents;
			$interestCents = (int)round($openingLiability * $rate);

			// The non-final periods apply the level payment; the final payment fully
			// extinguishes the liability (absorbing accumulated rounding).
			$paymentTotal = $paymentCents;
			$principalCents = ($paymentTotal - $interestCents);
			if ($sequence === $periods) {
				$principalCents = $openingLiability;
				$paymentTotal = ($interestCents + $principalCents);
			}

			// Closing liability = opening − principal repaid. The interest accrual
			// is funded by the payment (payment = interest + principal), so it does
			// not increase the closing balance; only the principal reduces it.
			$closingLiability = ($openingLiability - $principalCents);

			$openingRou = $rouCents;
			$depreciationCents = $depreciationPer;
			// The final period absorbs RoU rounding to land exactly on zero.
			if ($sequence === $periods || $depreciationCents > $openingRou) {
				$depreciationCents = $openingRou;
			}

			$closingRou = ($openingRou - $depreciationCents);

			$rows[] = [
				'periodSequence' => $sequence,
				'openingLeaseLiability' => $this->fromCents(cents: $openingLiability),
				'interestAccrued' => $this->fromCents(cents: $interestCents),
				'paymentAppliedTotal' => $this->fromCents(cents: $paymentTotal),
				'paymentInterestPortion' => $this->fromCents(cents: $interestCents),
				'paymentPrincipalPortion' => $this->fromCents(cents: $principalCents),
				'closingLeaseLiability' => $this->fromCents(cents: $closingLiability),
				'openingRouAsset' => $this->fromCents(cents: $openingRou),
				'depreciationCharge' => $this->fromCents(cents: $depreciationCents),
				'closingRouAsset' => $this->fromCents(cents: $closingRou),
			];

			$liabilityCents = $closingLiability;
			$rouCents = $closingRou;
		}//end for

		return $rows;
	}//end buildSchedule()

	/**
	 * Straight-line per-period charge in cents (REQ-LA-002 depreciation column).
	 *
	 * @param int $totalCents Amount to spread (RoU asset opening value).
	 * @param int $periods Number of periods to spread it over.
	 *
	 * @return int Per-period charge in cents (floored; the final period absorbs the remainder).
	 *
	 * @spec openspec/specs/bookkeeping-lease-accounting/spec.md
	 */
	public function straightLinePerPeriodCents(int $totalCents, int $periods): int {
		if ($periods <= 0) {
			return 0;
		}

		return intdiv($totalCents, $periods);
	}//end straightLinePerPeriodCents()
}//end class
