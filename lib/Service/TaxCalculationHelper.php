<?php

/**
 * Tax Calculation Helper
 *
 * Pure-logic, side-effect-free arithmetic helper for the deferred-tax engine
 * (REQ-DT-001 through REQ-DT-010). Holds every tax arithmetic rule so each is
 * unit-testable in isolation; TaxCalculationService wires this to live
 * OpenRegister data.
 *
 * All money arithmetic is performed in integer euro cents to avoid IEEE-754
 * drift, mirroring TrialBalanceCalculator and PayrollCalculator. Tax rates are
 * expressed in basis points (1/10000): 2580 = 25.80%, 2700 = 27.00%.
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
 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free deferred-tax arithmetic.
 *
 * No OpenRegister dependency: every method takes plain arrays/scalars and
 * returns plain arrays/scalars so the logic is unit-testable in isolation.
 * TaxCalculationService wires this helper to live GL + Account data.
 *
 * Constants represent the 2026 NL Vpb brackets per Belastingplan 2026:
 * - First EUR 200,000 taxed at 19.00% (1900 bps)
 * - Remainder taxed at 25.80% (2580 bps)
 * - Loss-compensation 50% cap threshold: EUR 1,000,000 (100,000,000 cents)
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
 */
class TaxCalculationHelper {

	/**
	 * NL Vpb 2026 lower bracket ceiling in euro cents (EUR 200,000).
	 *
	 * @var int
	 */
	public const NL_VPB_LOWER_BRACKET_CENTS = 20000000;

	/**
	 * NL Vpb 2026 lower bracket rate in basis points (19.00%).
	 *
	 * @var int
	 */
	public const NL_VPB_LOWER_RATE_BPS = 1900;

	/**
	 * NL Vpb 2026 upper bracket rate in basis points (25.80%).
	 *
	 * @var int
	 */
	public const NL_VPB_UPPER_RATE_BPS = 2580;

	/**
	 * Loss-compensation 50%-cap threshold in euro cents (EUR 1,000,000 per Wet Vpb art. 20).
	 *
	 * @var int
	 */
	public const LOSS_CAP_THRESHOLD_CENTS = 100000000;

	/**
	 * Convert a money amount to integer euro cents.
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole euro cents.
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100);
	}//end toCents()

	/**
	 * Convert integer euro cents back to a float money amount.
	 *
	 * @param int $cents Amount in whole euro cents.
	 *
	 * @return float Money amount in euros.
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

	/**
	 * Compute the deferred-tax balance in cents for a temporary difference.
	 *
	 * Formula: deferredTaxBalance = round(differenceInCents x taxRateBps / 10000)
	 *
	 * Positive result = DTL (taxable difference); negative result = DTA (deductible).
	 *
	 * @param int $differenceInCents Temporary difference in euro cents (positive = taxable).
	 * @param int $taxRateBps Tax rate in basis points (1/10000). E.g. 2580 = 25.80%.
	 *
	 * @return int Deferred-tax balance in euro cents.
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function computeDeferredTaxCents(int $differenceInCents, int $taxRateBps): int {
		return (int)round($differenceInCents * $taxRateBps / 10000);
	}//end computeDeferredTaxCents()

	/**
	 * Compute the rate-change adjustment in cents when a new rate is enacted (REQ-DT-005).
	 *
	 * Formula: adjustment = round(differenceInCents x (newRateBps - oldRateBps) / 10000)
	 *
	 * Positive result means the DTA/DTL increases; negative means it decreases.
	 *
	 * @param int $differenceInCents Temporary difference in euro cents.
	 * @param int $oldRateBps Previous tax rate in basis points.
	 * @param int $newRateBps Newly enacted tax rate in basis points.
	 *
	 * @return int Rate-change adjustment in euro cents.
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function computeRateChangeAdjustmentCents(int $differenceInCents, int $oldRateBps, int $newRateBps): int {
		return (int)round($differenceInCents * ($newRateBps - $oldRateBps) / 10000);
	}//end computeRateChangeAdjustmentCents()

	/**
	 * Compute utilisable loss amount for a given profit under the applicable regime (REQ-DT-003).
	 *
	 * Wet Vpb art. 20 / 20a loss-compensation regimes:
	 * - pre-2019-6year  : 100% utilisation, no annual cap (expiry enforced by caller).
	 * - 2019-2021-transition: hybrid; applies the 50% cap (same as 2022+) per overgangsregels.
	 * - 2022-onwards    : first EUR 1M (threshold) 100%; amounts above threshold 50% (art. 20).
	 *
	 * @param int $remainingLossCents Open carry-forward balance in euro cents (positive).
	 * @param int $availableProfitCents Available taxable profit to offset in euro cents (positive).
	 * @param string $regime One of pre-2019-6year | 2019-2021-transition | 2022-onwards.
	 *
	 * @return int Utilisable loss amount in euro cents (the amount that reduces taxable income).
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function computeUtilisableLoss(int $remainingLossCents, int $availableProfitCents, string $regime): int {
		if ($remainingLossCents <= 0 || $availableProfitCents <= 0) {
			return 0;
		}

		if ($regime === 'pre-2019-6year') {
			// Pre-2019: 100% utilisation, no annual cap.
			return min($remainingLossCents, $availableProfitCents);
		}

		// 2022-onwards and 2019-2021-transition: EUR 1M threshold + 50% cap above.
		return $this->applyFiftyCentCapLoss(
			remainingLossCents: $remainingLossCents,
			availableProfitCents: $availableProfitCents
		);

	}//end computeUtilisableLoss()

	/**
	 * Apply the Wet Vpb 2022+ 50%-cap loss utilisation (REQ-DT-003 scenario: 2022+ regime).
	 *
	 * First EUR 1M of profit: losses offset 100%.
	 * Above EUR 1M: at most 50% of the excess profit may be offset by losses.
	 * Total utilisable = min(remainingLoss, 100%_portion + 50%_portion).
	 *
	 * Example from REQ-DT-003 scenario:
	 *   profit = EUR 1,800,000; loss = EUR 3,200,000
	 *   First EUR 1M → 100% offset = EUR 1,000,000
	 *   Remaining profit = EUR 800,000; 50% cap = EUR 400,000
	 *   Total utilisable = EUR 1,400,000; taxable income = EUR 400,000
	 *
	 * @param int $remainingLossCents Remaining carry-forward in euro cents (positive).
	 * @param int $availableProfitCents Available taxable profit in euro cents (positive).
	 *
	 * @return int Utilisable loss in euro cents.
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function applyFiftyCentCapLoss(int $remainingLossCents, int $availableProfitCents): int {
		if ($availableProfitCents <= self::LOSS_CAP_THRESHOLD_CENTS) {
			// Profit is at or below EUR 1M — full 100% offset, capped by remaining loss.
			return min($remainingLossCents, $availableProfitCents);
		}

		// Profit above EUR 1M: first EUR 1M at 100%, excess at 50%.
		$excessProfit = ($availableProfitCents - self::LOSS_CAP_THRESHOLD_CENTS);
		$maxUtilisable = self::LOSS_CAP_THRESHOLD_CENTS + (int)round($excessProfit * 0.5);
		return min($remainingLossCents, $maxUtilisable);
	}//end applyFiftyCentCapLoss()

	/**
	 * Compute the NL Vpb tax for a given taxable income using the 2026 brackets (REQ-DT-003).
	 *
	 * Bracket 1: first EUR 200,000 at 19.00%
	 * Bracket 2: remainder at 25.80%
	 *
	 * Amounts are in euro cents. Result is in euro cents.
	 *
	 * @param int $taxableIncomeCents Taxable income in euro cents (positive).
	 *
	 * @return int NL Vpb tax payable in euro cents.
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function computeNlVpbTaxCents(int $taxableIncomeCents): int {
		if ($taxableIncomeCents <= 0) {
			return 0;
		}

		$lowerCeiling = self::NL_VPB_LOWER_BRACKET_CENTS;
		$lowerRate = self::NL_VPB_LOWER_RATE_BPS;
		$upperRate = self::NL_VPB_UPPER_RATE_BPS;

		if ($taxableIncomeCents <= $lowerCeiling) {
			return (int)round($taxableIncomeCents * $lowerRate / 10000);
		}

		$lowerTax = (int)round($lowerCeiling * $lowerRate / 10000);
		$upperTax = (int)round(($taxableIncomeCents - $lowerCeiling) * $upperRate / 10000);
		return ($lowerTax + $upperTax);
	}//end computeNlVpbTaxCents()

	/**
	 * Compute the ETR (effective tax rate) in basis points (REQ-DT-006).
	 *
	 * Formula: effectiveTaxRate = round(effectiveTaxExpenseCents / profitBeforeTaxCents x 10000).
	 * Returns 0 when profitBeforeTaxCents is 0 to avoid division by zero.
	 *
	 * @param int $taxExpenseCents Effective income-tax expense in euro cents.
	 * @param int $profitCents Profit before income tax in euro cents.
	 *
	 * @return int ETR in basis points.
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function computeEffectiveTaxRateBps(int $taxExpenseCents, int $profitCents): int {
		if ($profitCents === 0) {
			return 0;
		}

		return (int)round($taxExpenseCents * 10000 / $profitCents);
	}//end computeEffectiveTaxRateBps()

	/**
	 * Sum the tax effects from a reconciliation items array (REQ-DT-006).
	 *
	 * @param array<int,array<string,mixed>> $reconciliationItems Array of items with 'taxEffect' key.
	 *
	 * @return int Sum of all taxEffect values in euro cents.
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function sumReconciliationItems(array $reconciliationItems): int {
		$total = 0;
		foreach ($reconciliationItems as $item) {
			$total += (int)($item['taxEffect'] ?? 0);
		}

		return $total;
	}//end sumReconciliationItems()

	/**
	 * Compute the DeferredTaxMovement closing balance per REQ-DT-009.
	 *
	 * Formula: closingBalance = openingBalance + originatedInPeriod + reversedInPeriod
	 * + rateChangeAdjustment + acquiredViaBusinessCombination
	 * + translationAdjustment + recognisedInOCI
	 *
	 * All amounts in euro cents.
	 *
	 * @param int $openingBalance Opening deferred-tax balance in cents.
	 * @param int $originatedInPeriod New positions in cents.
	 * @param int $reversedInPeriod Reversals in cents (typically negative).
	 * @param int $rateChangeAdjustment Rate-change effect in cents.
	 * @param int $acquiredViaBusinessCombination M&A positions in cents.
	 * @param int $translationAdjustment FX OCI effect in cents.
	 * @param int $recognisedInOCI Other OCI deferred-tax in cents.
	 *
	 * @return int Closing deferred-tax balance in euro cents.
	 *
	 * @SuppressWarnings(PHPMD.LongVariable)
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function computeMovementClosingBalance(
		int $openingBalance,
		int $originatedInPeriod,
		int $reversedInPeriod,
		int $rateChangeAdjustment,
		int $acquiredViaBusinessCombination,
		int $translationAdjustment,
		int $recognisedInOCI,
	): int {
		$sum = $openingBalance + $originatedInPeriod + $reversedInPeriod;
		$sum += $rateChangeAdjustment + $acquiredViaBusinessCombination;
		$sum += $translationAdjustment + $recognisedInOCI;
		return $sum;
	}//end computeMovementClosingBalance()

	/**
	 * Compute recognisedInPL: the P&L income-statement effect of the movement (REQ-DT-009).
	 *
	 * Formula: recognisedInPL = originatedInPeriod + reversedInPeriod + rateChangeAdjustment
	 * + acquiredViaBusinessCombination.
	 *
	 * TranslationAdjustment and recognisedInOCI flow through OCI, not P&L.
	 *
	 * @param int $originatedInPeriod New positions in cents.
	 * @param int $reversedInPeriod Reversals in cents.
	 * @param int $rateChangeAdjustment Rate-change effect in cents.
	 * @param int $acquiredViaBusinessCombination M&A positions in cents.
	 *
	 * @return int P&L deferred-tax effect in euro cents.
	 *
	 * @SuppressWarnings(PHPMD.LongVariable)
	 *
	 * @spec openspec/specs/bookkeeping-deferred-tax/spec.md
	 */
	public function computeMovementRecognisedInPL(
		int $originatedInPeriod,
		int $reversedInPeriod,
		int $rateChangeAdjustment,
		int $acquiredViaBusinessCombination,
	): int {
		return ($originatedInPeriod + $reversedInPeriod + $rateChangeAdjustment + $acquiredViaBusinessCombination);
	}//end computeMovementRecognisedInPL()
}//end class
