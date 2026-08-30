<?php

/**
 * Revenue Recognition Calculator
 *
 * Pure-logic helper for the IFRS 15 five-step model (REQ-IFRS15-002..009). Holds
 * the side-effect-free arithmetic that RevenueCutoffService and
 * VariableConsiderationReestimationService apply after fetching Contract /
 * PerformanceObligation / TransactionPrice / RevenueRecognitionEvent data via the
 * OpenRegister ObjectService API: the relative-SSP and residual price allocation
 * (step 4), the cost-to-cost percentage-of-completion (step 5 input method), the
 * variable-consideration constraint (IFRS 15.56), the contract asset / contract
 * liability split (IFRS 15.116-119), and the revenue-waterfall remaining amount
 * (IFRS 15.120). All money arithmetic is performed in integer cents to avoid
 * IEEE-754 equality drift, mirroring TrialBalanceCalculator / BalanceGuard.
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
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free IFRS 15 revenue arithmetic helper.
 *
 * No OpenRegister dependency: every method takes plain arrays/scalars and returns
 * plain arrays/scalars so the logic is unit-testable in isolation. RevenueCutoffService
 * wires this helper to live Contract / PO / event data.
 *
 * Each public method is a distinct IFRS 15 operation (constraint, allocation,
 * percentage-of-completion, asset/liability split, modification classification),
 * so the class deliberately exposes more than ten — TooManyPublicMethods is
 * suppressed rather than collapsing semantically separate steps. The
 * spec-canonical field names (revisedTotalEstimatedCost, transactionPriceAllocated)
 * are kept verbatim for traceability, so LongVariable is suppressed too.
 *
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class RevenueRecognitionCalculator {
	/**
	 * Convert a money amount to integer cents (precision rule).
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100);
	}//end toCents()

	/**
	 * Convert integer cents back to a float money amount.
	 *
	 * @param int $cents Amount in whole cents.
	 *
	 * @return float Money amount.
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

	/**
	 * Constrain a variable-consideration estimate per IFRS 15.56 (REQ-IFRS15-003).
	 *
	 * The amount entering the transaction price is limited to the amount highly
	 * probable not to reverse. When a constraint amount is supplied the lower of
	 * the estimate and the constraint is used; otherwise the full estimate is used.
	 *
	 * @param float $estimate Unconstrained variable-consideration estimate.
	 * @param float|null $constraint Constraint limit (highly probable not to reverse).
	 *
	 * @return float The constrained amount (in money).
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function constrainedVariable(float $estimate, ?float $constraint): float {
		$estimateCents = $this->toCents(amount: $estimate);
		if ($constraint === null) {
			return $this->fromCents(cents: $estimateCents);
		}

		$constraintCents = $this->toCents(amount: $constraint);
		return $this->fromCents(cents: min($estimateCents, $constraintCents));
	}//end constrainedVariable()

	/**
	 * Compute the total transaction price per IFRS 15 (REQ-IFRS15-002).
	 *
	 * Total = fixed + min(variable, constraint) + significantFinancingComponent
	 * + nonCashConsideration - considerationPayableToCustomer. All arithmetic in
	 * integer cents.
	 *
	 * @param array<string,mixed> $price A TransactionPrice record.
	 *
	 * @return float The total transaction price (in money).
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function totalTransactionPrice(array $price): float {
		$fixed = $this->toCents(amount: ($price['fixedConsideration'] ?? 0));
		$variableEst = (float)($price['variableConsideration'] ?? 0);
		$constraint = $price['constraintAmount'];
		if ($constraint !== null) {
			$constraint = (float)$constraint;
		}

		$variable = $this->toCents(amount: $this->constrainedVariable(estimate: $variableEst, constraint: $constraint));
		$financing = $this->toCents(amount: ($price['significantFinancingComponent'] ?? 0));
		$nonCash = $this->toCents(amount: ($price['nonCashConsideration'] ?? 0));
		$payable = $this->toCents(amount: ($price['considerationPayableToCustomer'] ?? 0));

		return $this->fromCents(cents: (($fixed + $variable + $financing + $nonCash) - $payable));
	}//end totalTransactionPrice()

	/**
	 * Allocate a transaction price across POs by relative SSP (REQ-IFRS15-004).
	 *
	 * Each PO receives round(SSP[i] / sum(SSP) * totalPrice). Allocation is computed
	 * in integer cents; any rounding remainder is assigned to the largest-SSP PO so
	 * the allocations tie back exactly to the total price (IFRS 15.74).
	 *
	 * @param array<int,array{poId:string,ssp:float}> $pos POs with their SSPs.
	 * @param float $totalPrice Total transaction price to allocate.
	 *
	 * @return array<string,float> poId => allocated amount (in money).
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function allocateRelativeSsp(array $pos, float $totalPrice): array {
		$totalCents = $this->toCents(amount: $totalPrice);
		$sspCents = [];
		$sumSsp = 0;
		foreach ($pos as $po) {
			$cents = $this->toCents(amount: $po['ssp']);
			$sspCents[$po['poId']] = $cents;
			$sumSsp += $cents;
		}

		if ($sumSsp === 0) {
			$zero = [];
			foreach ($pos as $po) {
				$zero[$po['poId']] = 0.0;
			}

			return $zero;
		}

		$allocated = [];
		$assigned = 0;
		$largestPo = '';
		$largestSsp = -1;
		foreach ($sspCents as $poId => $cents) {
			$share = (int)round($cents / $sumSsp * $totalCents);
			$allocated[$poId] = $share;
			$assigned += $share;
			if ($cents > $largestSsp) {
				$largestSsp = $cents;
				$largestPo = $poId;
			}
		}

		// Assign the rounding remainder to the largest-SSP PO so the sum ties back.
		if ($largestPo !== '') {
			$allocated[$largestPo] += ($totalCents - $assigned);
		}

		$result = [];
		foreach ($allocated as $poId => $cents) {
			$result[$poId] = $this->fromCents(cents: $cents);
		}

		return $result;
	}//end allocateRelativeSsp()

	/**
	 * Allocate by the residual method when one PO's SSP is uncertain (REQ-IFRS15-004).
	 *
	 * POs with a reliable SSP are allocated at their SSP (IFRS 15.79); the residual
	 * (totalPrice - sum of reliable SSPs) is assigned to the single uncertain PO.
	 *
	 * @param array<int,array{poId:string,ssp:float}> $reliablePos POs with reliable SSPs.
	 * @param string $residualPo poId of the uncertain PO.
	 * @param float $totalPrice Total transaction price.
	 *
	 * @return array<string,float> poId => allocated amount (in money).
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function allocateResidual(array $reliablePos, string $residualPo, float $totalPrice): array {
		$totalCents = $this->toCents(amount: $totalPrice);
		$assigned = 0;
		$result = [];
		foreach ($reliablePos as $po) {
			$cents = $this->toCents(amount: $po['ssp']);
			$result[$po['poId']] = $this->fromCents(cents: $cents);
			$assigned += $cents;
		}

		$result[$residualPo] = $this->fromCents(cents: ($totalCents - $assigned));

		return $result;
	}//end allocateResidual()

	/**
	 * Compute the cost-to-cost percentage of completion (REQ-IFRS15-005).
	 *
	 * % complete = actualCostToDate / revisedTotalEstimatedCost * 100, clamped to
	 * [0, 100]. Returns 0.0 when the revised estimate is zero (avoids divide-by-zero).
	 *
	 * @param float $actualCostToDate Cumulative actual cost incurred.
	 * @param float $revisedTotalEstimatedCost Revised estimate-at-completion total cost.
	 *
	 * @return float Percentage of completion (0-100).
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function percentageComplete(float $actualCostToDate, float $revisedTotalEstimatedCost): float {
		$estimateCents = $this->toCents(amount: $revisedTotalEstimatedCost);
		if ($estimateCents <= 0) {
			return 0.0;
		}

		$actualCents = $this->toCents(amount: $actualCostToDate);
		$pct = ($actualCents / $estimateCents) * 100;
		if ($pct < 0) {
			return 0.0;
		}

		if ($pct > 100) {
			return 100.0;
		}

		return round($pct, 2);
	}//end percentageComplete()

	/**
	 * Cumulative revenue recognised for a cost-to-cost PO (REQ-IFRS15-005).
	 *
	 * Cumulative = round(percentComplete/100 * allocatedPrice). The period revenue
	 * to post is this cumulative less the prior-period cumulative (delta).
	 *
	 * @param float $percentComplete Percentage of completion (0-100).
	 * @param float $allocatedPrice Transaction price allocated to the PO.
	 *
	 * @return float Cumulative recognised revenue (in money).
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function cumulativeFromPercentage(float $percentComplete, float $allocatedPrice): float {
		$allocatedCents = $this->toCents(amount: $allocatedPrice);
		$cumulative = (int)round(($percentComplete / 100) * $allocatedCents);

		return $this->fromCents(cents: $cumulative);
	}//end cumulativeFromPercentage()

	/**
	 * Revised gross margin for an input-method PO (REQ-IFRS15-005, REQ-IFRS15-009).
	 *
	 * Margin = (allocatedPrice - revisedTotalEstimatedCost) / allocatedPrice. A
	 * negative margin signals an onerous contract and triggers an impairment test.
	 * Returns 0.0 when the allocated price is zero.
	 *
	 * @param float $allocatedPrice Transaction price allocated to the PO.
	 * @param float $revisedTotalEstimatedCost Revised estimate-at-completion total cost.
	 *
	 * @return float Gross margin as a fraction (e.g. 0.10 for 10%).
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function revisedMargin(float $allocatedPrice, float $revisedTotalEstimatedCost): float {
		$priceCents = $this->toCents(amount: $allocatedPrice);
		if ($priceCents === 0) {
			return 0.0;
		}

		$costCents = $this->toCents(amount: $revisedTotalEstimatedCost);

		return round((($priceCents - $costCents) / $priceCents), 4);
	}//end revisedMargin()

	/**
	 * Split a contract's cumulative recognised vs billed into asset/liability (REQ-IFRS15-007).
	 *
	 * Per IFRS 15.116-119: contract asset = max(0, recognised - billed) (right to
	 * consideration); contract liability = max(0, billed - recognised) (deferred
	 * revenue). Exactly one of the two is non-zero for a given contract/period.
	 *
	 * @param float $cumulativeRecognised Cumulative recognised revenue.
	 * @param float $cumulativeBilled Cumulative billed/invoiced amount.
	 *
	 * @return array{asset:float,liability:float} The asset and liability balances (in money).
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function contractAssetLiability(float $cumulativeRecognised, float $cumulativeBilled): array {
		$recognised = $this->toCents(amount: $cumulativeRecognised);
		$billed = $this->toCents(amount: $cumulativeBilled);
		$net = ($recognised - $billed);

		if ($net > 0) {
			return [
				'asset' => $this->fromCents(cents: $net),
				'liability' => 0.0,
			];
		}

		return [
			'asset' => 0.0,
			'liability' => $this->fromCents(cents: (-1 * $net)),
		];

	}//end contractAssetLiability()

	/**
	 * Remaining transaction price for the revenue waterfall (REQ-IFRS15-008).
	 *
	 * Remaining = max(0, transactionPriceAllocated - cumulativeRecognised), the
	 * IFRS 15.120 remaining-performance-obligation amount.
	 *
	 * @param float $transactionPriceAllocated Total allocated transaction price.
	 * @param float $cumulativeRecognised Cumulative recognised revenue.
	 *
	 * @return float Remaining amount (in money).
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function remainingAmount(float $transactionPriceAllocated, float $cumulativeRecognised): float {
		$allocated = $this->toCents(amount: $transactionPriceAllocated);
		$recognised = $this->toCents(amount: $cumulativeRecognised);
		$remaining = ($allocated - $recognised);
		if ($remaining < 0) {
			return 0.0;
		}

		return $this->fromCents(cents: $remaining);
	}//end remainingAmount()

	/**
	 * Classify a contract modification per IFRS 15.18-21 (REQ-IFRS15-006).
	 *
	 * Auto-classification: a modification adding distinct goods/services priced at
	 * their stand-alone selling price is a `new-contract` (IFRS 15.20(a)); a
	 * price-only change with no new scope is `prospective`; otherwise (added scope
	 * not distinct from existing POs) it is `not-distinct-cumulative`.
	 *
	 * @param bool $addsDistinctScope Whether the modification adds distinct new scope.
	 * @param bool $pricedAtSsp Whether the added scope is priced at its SSP.
	 * @param bool $priceOnly Whether the modification only changes price (no scope).
	 *
	 * @return string One of new-contract | prospective | not-distinct-cumulative.
	 *
	 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-16
	 */
	public function classifyModification(bool $addsDistinctScope, bool $pricedAtSsp, bool $priceOnly): string {
		if ($addsDistinctScope === true && $pricedAtSsp === true) {
			return 'new-contract';
		}

		if ($priceOnly === true) {
			return 'prospective';
		}

		return 'not-distinct-cumulative';
	}//end classifyModification()
}//end class
