<?php

/**
 * Depreciation Calculator
 *
 * Pure-logic helper that materialises the values declared as
 * `x-openregister-calculations` on the `FixedAsset` schema (REQ-FA-003,
 * REQ-FA-004). The calculator holds the side-effect-free arithmetic the
 * monthly-depreciation OR `ScheduledWorkflow` (REQ-FA-005) and the unit
 * tests need to compute `monthlyDepreciation`, `currentBookValue`,
 * `commercialBookValue`, and `fiscalBookValue` from a `FixedAsset`
 * record and a reference date.
 *
 * All money arithmetic is performed in integer cents to avoid IEEE-754
 * drift, mirroring `LeaseAmortizationCalculator`,
 * `TrialBalanceCalculator`, and `BalanceGuard`. The class has no
 * OpenRegister dependency so the depreciation maths is unit-testable in
 * isolation against the spec's worked examples.
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
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free FixedAsset depreciation arithmetic helper (REQ-FA-003).
 *
 * Every method takes plain scalars/arrays and returns plain arrays/scalars
 * so the logic is unit-testable in isolation. The monthly-depreciation
 * ScheduledWorkflow wires this helper to live FixedAsset records fetched
 * from the OpenRegister ObjectService; the lifecycle disposal action uses
 * it to read `currentBookValue` at transition time.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class DepreciationCalculator {
	/**
	 * Fallback Float Precision (Dutch accounting standard) per REQ-FA-005.
	 *
	 * Used when Nextcloud System Settings is unavailable.
	 *
	 * @var int
	 */
	public const FLOAT_PRECISION_FALLBACK = 2;

	/**
	 * Round a value to the requested Float Precision (REQ-FA-005).
	 *
	 * Honours the Nextcloud System Settings Float Precision value when
	 * supplied; falls back to 2 decimal places (Dutch accounting standard)
	 * when null. Centralises the rounding so every code path that quotes
	 * REQ-FA-005 in the spec uses identical arithmetic.
	 *
	 * @param float $value Value to round.
	 * @param int|null $floatPrecision Float Precision (decimal places) or null for fallback.
	 *
	 * @return float Value rounded to the configured precision.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function applyFloatPrecision(float $value, ?int $floatPrecision = null): float {
		$precision = ($floatPrecision ?? self::FLOAT_PRECISION_FALLBACK);
		if ($precision < 0) {
			$precision = self::FLOAT_PRECISION_FALLBACK;
		}

		return round($value, $precision);
	}//end applyFloatPrecision()

	/**
	 * Yearly depreciation amount for a DepreciationSchedule period (REQ-FA-007).
	 *
	 * Computes the per-fiscal-year depreciation amount from the asset's
	 * depreciationMethod, acquisitionCost / residualValue / usefulLife,
	 * and (for declining-balance) declineRate. The result is rounded to
	 * the supplied Float Precision per REQ-FA-005, falling back to 2
	 * decimals when null. Drives the yearly depreciation-expense GL
	 * posting materialised by the FixedAssets ScheduledWorkflow.
	 *
	 * Worked examples (REQ-FA-001 / REQ-FA-007 spec scenarios):
	 *  - €25,000 acquisition, 5y linear, 0 residual: 25000 / 5 = €5,000/yr
	 *  - €200,000 acquisition, 20y linear, €20,000 residual:
	 *      (200000-20000)/20 = €9,000/yr
	 *  - €5,000 acquisition, declining 0.40 in year 1: €5,000 × 0.40 = €2,000
	 *
	 * @param array<string,mixed> $asset FixedAsset record.
	 * @param string $referenceDate Reference date (Y-m-d) for declining-balance.
	 * @param int|null $floatPrecision Float Precision (REQ-FA-005).
	 *
	 * @return float Yearly depreciation amount (>= 0, rounded).
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function yearlyDepreciation(array $asset, string $referenceDate = '', ?int $floatPrecision = null): float {
		$method = (string)($asset['depreciationMethod'] ?? 'none');
		if ($method === 'none') {
			return $this->applyFloatPrecision(value: 0.0, floatPrecision: $floatPrecision);
		}

		$costCents = $this->toCents(amount: ($asset['acquisitionCost'] ?? ($asset['purchaseCost'] ?? 0)));
		$residualCents = $this->toCents(amount: ($asset['residualValue'] ?? 0));

		// Useful-life lookup honours both REQ-FA-002 aliases (usefulLifeYears
		// / usefulLifeMonths). When both are present they MUST agree to
		// within 1-month rounding per the schema alias contract; the months
		// form wins as the canonical storage shape.
		$lifeMonths = (int)($asset['usefulLifeMonths'] ?? 0);
		if ($lifeMonths <= 0) {
			$lifeYears = (int)($asset['usefulLifeYears'] ?? 0);
			if ($lifeYears > 0) {
				$lifeMonths = ($lifeYears * 12);
			}
		}

		if ($method === 'linear') {
			if ($lifeMonths <= 0) {
				return $this->applyFloatPrecision(value: 0.0, floatPrecision: $floatPrecision);
			}

			$depreciableCents = max(0, ($costCents - $residualCents));
			$yearlyCents = (int)round(($depreciableCents * 12) / $lifeMonths);
			$yearly = ($yearlyCents / 100);
			return $this->applyFloatPrecision(value: $yearly, floatPrecision: $floatPrecision);
		}

		if ($method === 'declining-balance' || $method === 'degressive') {
			$rate = (float)($asset['declineRate'] ?? ($asset['degressiveRate'] ?? 0));
			if ($rate <= 0.0) {
				return $this->applyFloatPrecision(value: 0.0, floatPrecision: $floatPrecision);
			}

			$bookValue = $this->currentBookValue(asset: $asset, referenceDate: $referenceDate);
			$bookValueCents = $this->toCents(amount: $bookValue);
			$yearlyCents = (int)round($bookValueCents * $rate);
			$yearly = ($yearlyCents / 100);
			return $this->applyFloatPrecision(value: $yearly, floatPrecision: $floatPrecision);
		}

		return $this->applyFloatPrecision(value: 0.0, floatPrecision: $floatPrecision);
	}//end yearlyDepreciation()

	/**
	 * Gain/loss on disposal at retirement (REQ-FA-008).
	 *
	 * Positive return = gain on disposal (salvage proceeds exceed net book
	 * value), to be credited to the gain-on-disposal P&L account. Negative
	 * return = loss on disposal (book value exceeds proceeds), to be
	 * debited to the loss-on-disposal P&L account. Zero return = clean
	 * retirement (no P&L impact beyond removing the asset and its
	 * accumulated depreciation from the balance sheet).
	 *
	 * @param array<string,mixed> $asset FixedAsset record (including any salvageProceeds).
	 * @param string $referenceDate Reference date for the book-value snapshot.
	 * @param int|null $floatPrecision Float Precision for the rounding (REQ-FA-005).
	 *
	 * @return float Gain (positive) or loss (negative) on disposal.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function gainOrLossOnDisposal(array $asset, string $referenceDate = '', ?int $floatPrecision = null): float {
		$proceeds = (float)($asset['salvageProceeds'] ?? ($asset['disposalProceeds'] ?? 0));
		$bookValue = $this->currentBookValue(asset: $asset, referenceDate: $referenceDate);

		return $this->applyFloatPrecision(value: ($proceeds - $bookValue), floatPrecision: $floatPrecision);
	}//end gainOrLossOnDisposal()

	/**
	 * Compute the proportional split allocations for an internal transfer (REQ-FA-006).
	 *
	 * For a `splitTransfer` action, returns a paired structure showing the
	 * original asset's reduced amounts and the split-off asset's new
	 * amounts, allocating purchaseCost and accumulatedDepreciation
	 * proportionally by the supplied splitPercentage. Both records remain
	 * on the same balance-sheet account; NO GL posting is required.
	 *
	 * @param array<string,mixed> $asset Original FixedAsset.
	 * @param float $splitPercentage Fraction (0..1) of the asset transferred (e.g. 0.30 for 30%).
	 * @param int|null $floatPrecision Float Precision (REQ-FA-005).
	 *
	 * @return array{
	 *     original:array{purchaseCost:float, residualValue:float},
	 *     split:array{purchaseCost:float, residualValue:float, transferSourceAssetRef:?string}
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function splitTransferAllocations(array $asset, float $splitPercentage, ?int $floatPrecision = null): array {
		$clamped = max(0.0, min(1.0, $splitPercentage));
		$cost = (float)($asset['acquisitionCost'] ?? ($asset['purchaseCost'] ?? 0));
		$residual = (float)($asset['residualValue'] ?? 0);

		$splitCost = $this->applyFloatPrecision(value: ($cost * $clamped), floatPrecision: $floatPrecision);
		$splitResidual = $this->applyFloatPrecision(value: ($residual * $clamped), floatPrecision: $floatPrecision);

		$originalCost = $this->applyFloatPrecision(value: ($cost - $splitCost), floatPrecision: $floatPrecision);
		$originalResidual = $this->applyFloatPrecision(value: ($residual - $splitResidual), floatPrecision: $floatPrecision);

		if (isset($asset['id']) === true) {
			$transferSourceAssetRef = (string)$asset['id'];
		} else {
			$transferSourceAssetRef = null;
		}

		return [
			'original' => [
				'purchaseCost' => $originalCost,
				'residualValue' => $originalResidual,
			],
			'split' => [
				'purchaseCost' => $splitCost,
				'residualValue' => $splitResidual,
				'transferSourceAssetRef' => $transferSourceAssetRef,
			],
		];

	}//end splitTransferAllocations()

	/**
	 * Convert a money amount to integer cents.
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
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
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function fromCents(int $cents): float {
		return round($cents / 100, 2);
	}//end fromCents()

	/**
	 * Number of whole months elapsed between two ISO-8601 dates.
	 *
	 * Returns 0 when `referenceDate` is on or before `acquisitionDate`.
	 * Counts complete calendar months from the acquisition date.
	 *
	 * @param string $acquisitionDate Asset acquisition date (Y-m-d).
	 * @param string $referenceDate Reference date (Y-m-d).
	 *
	 * @return int Whole months elapsed (>= 0).
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function monthsElapsed(string $acquisitionDate, string $referenceDate): int {
		$start = strtotime($acquisitionDate);
		$end = strtotime($referenceDate);
		if ($start === false || $end === false || $end <= $start) {
			return 0;
		}

		$startYear = (int)date('Y', $start);
		$startMonth = (int)date('m', $start);
		$startDay = (int)date('d', $start);
		$endYear = (int)date('Y', $end);
		$endMonth = (int)date('m', $end);
		$endDay = (int)date('d', $end);

		$months = (($endYear - $startYear) * 12) + ($endMonth - $startMonth);
		if ($endDay < $startDay) {
			$months -= 1;
		}

		return max(0, $months);
	}//end monthsElapsed()

	/**
	 * Monthly depreciation charge under the linear method (REQ-FA-003).
	 *
	 * Linear: `(acquisitionCost - residualValue) / usefulLifeMonths`.
	 * Degressive: `currentBookValue * degressiveRate / 12`.
	 * None: returns 0.
	 *
	 * The result is rounded to two decimals via the cents helper so the
	 * OR ScheduledWorkflow can post a clean GLTransaction line.
	 *
	 * @param array<string,mixed> $asset FixedAsset record.
	 * @param string $referenceDate Reference date (Y-m-d, used by degressive).
	 *
	 * @return float Monthly depreciation charge (>= 0, two decimals).
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function monthlyDepreciation(array $asset, string $referenceDate = ''): float {
		$method = (string)($asset['depreciationMethod'] ?? 'none');
		if ($method === 'none') {
			return 0.0;
		}

		$costCents = $this->toCents(amount: ($asset['acquisitionCost'] ?? 0));
		$residualCents = $this->toCents(amount: ($asset['residualValue'] ?? 0));
		$usefulLife = (int)($asset['usefulLifeMonths'] ?? 0);

		if ($method === 'linear') {
			if ($usefulLife <= 0) {
				return 0.0;
			}

			$depreciableCents = max(0, ($costCents - $residualCents));
			$monthlyCents = (int)round($depreciableCents / $usefulLife);
			return $this->fromCents(cents: $monthlyCents);
		}

		if ($method === 'degressive') {
			$rate = (float)($asset['degressiveRate'] ?? 0);
			if ($rate <= 0.0) {
				return 0.0;
			}

			$bookValue = $this->currentBookValue(asset: $asset, referenceDate: $referenceDate);
			$bookValueCents = $this->toCents(amount: $bookValue);
			$monthlyCents = (int)round(($bookValueCents * $rate) / 12);
			return $this->fromCents(cents: $monthlyCents);
		}

		// Units-of-production and any other method fall through to 0 by
		// default; explicit unit-production posting is operator-driven and
		// not part of this declarative scheduled flow.
		return 0.0;
	}//end monthlyDepreciation()

	/**
	 * Current net book value at `referenceDate` (REQ-FA-003).
	 *
	 * For linear:
	 *   bookValue = max(residualValue, acquisitionCost − (depreciable / life) × monthsElapsed).
	 * For none:
	 *   bookValue = acquisitionCost (indefinitely).
	 * For degressive:
	 *   bookValue = max(residualValue, acquisitionCost × (1 − rate × yearsElapsed)).
	 *
	 * Both streams float on integer-cent arithmetic to keep the post-able
	 * value stable.
	 *
	 * @param array<string,mixed> $asset FixedAsset record.
	 * @param string $referenceDate Reference date (Y-m-d).
	 *
	 * @return float Current book value (>= residualValue, two decimals).
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function currentBookValue(array $asset, string $referenceDate = ''): float {
		$costCents = $this->toCents(amount: ($asset['acquisitionCost'] ?? 0));
		$method = (string)($asset['depreciationMethod'] ?? 'none');
		if ($method === 'none') {
			return $this->fromCents(cents: $costCents);
		}

		if ($referenceDate === '') {
			$referenceDate = date('Y-m-d');
		}

		$residualCents = $this->toCents(amount: ($asset['residualValue'] ?? 0));
		$months = $this->monthsElapsed(
			acquisitionDate: (string)($asset['acquisitionDate'] ?? $referenceDate),
			referenceDate: $referenceDate
		);

		if ($method === 'linear') {
			$usefulLife = (int)($asset['usefulLifeMonths'] ?? 0);
			if ($usefulLife <= 0) {
				return $this->fromCents(cents: $costCents);
			}

			$depreciableCents = max(0, ($costCents - $residualCents));
			$perMonthCents = (int)round($depreciableCents / $usefulLife);
			$accumCents = ($perMonthCents * $months);
			$bookCents = max($residualCents, ($costCents - $accumCents));
			return $this->fromCents(cents: $bookCents);
		}

		if ($method === 'degressive') {
			$rate = (float)($asset['degressiveRate'] ?? 0);
			if ($rate <= 0.0) {
				return $this->fromCents(cents: $costCents);
			}

			$factor = max(0.0, (1.0 - ($rate * ($months / 12.0))));
			$bookCents = (int)round($costCents * $factor);
			$bookCents = max($residualCents, $bookCents);
			return $this->fromCents(cents: $bookCents);
		}

		return $this->fromCents(cents: $costCents);
	}//end currentBookValue()

	/**
	 * Book value under the commercial stream (REQ-FA-004).
	 *
	 * When `commercialRate` is null, falls back to `currentBookValue`
	 * (a single-stream asset). Otherwise applies the commercial rate as
	 * `acquisitionCost × (1 − commercialRate × yearsElapsed)`, floored
	 * at zero, mirroring the calculation expression on the schema.
	 *
	 * @param array<string,mixed> $asset FixedAsset record.
	 * @param string $referenceDate Reference date (Y-m-d).
	 *
	 * @return float Commercial-stream book value (>= 0, two decimals).
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function commercialBookValue(array $asset, string $referenceDate = ''): float {
		$rate = ($asset['commercialRate'] ?? null);
		if ($rate === null) {
			return $this->currentBookValue(asset: $asset, referenceDate: $referenceDate);
		}

		return $this->streamBookValue(
			asset: $asset,
			referenceDate: $referenceDate,
			annualRate: (float)$rate
		);

	}//end commercialBookValue()

	/**
	 * Book value under the fiscal stream (REQ-FA-004).
	 *
	 * When `fiscalRate` is null, falls back to `currentBookValue`. Otherwise
	 * applies the fiscal rate as `acquisitionCost × (1 − fiscalRate × yearsElapsed)`,
	 * floored at zero (mirrors the schema's calculation expression).
	 *
	 * @param array<string,mixed> $asset FixedAsset record.
	 * @param string $referenceDate Reference date (Y-m-d).
	 *
	 * @return float Fiscal-stream book value (>= 0, two decimals).
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function fiscalBookValue(array $asset, string $referenceDate = ''): float {
		$rate = ($asset['fiscalRate'] ?? null);
		if ($rate === null) {
			return $this->currentBookValue(asset: $asset, referenceDate: $referenceDate);
		}

		return $this->streamBookValue(
			asset: $asset,
			referenceDate: $referenceDate,
			annualRate: (float)$rate
		);

	}//end fiscalBookValue()

	/**
	 * Read all four derived fields at once (REQ-FA-003, REQ-FA-004).
	 *
	 * Convenience aggregator the OR ScheduledWorkflow uses to post both
	 * streams at the same tick. Returns floats (two decimals) keyed by the
	 * `x-openregister-calculations` field names.
	 *
	 * @param array<string,mixed> $asset FixedAsset record.
	 * @param string $referenceDate Reference date (Y-m-d).
	 *
	 * @return array{monthlyDepreciation:float, currentBookValue:float, commercialBookValue:float, fiscalBookValue:float}
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function derivedFields(array $asset, string $referenceDate = ''): array {
		return [
			'monthlyDepreciation' => $this->monthlyDepreciation(asset: $asset, referenceDate: $referenceDate),
			'currentBookValue' => $this->currentBookValue(asset: $asset, referenceDate: $referenceDate),
			'commercialBookValue' => $this->commercialBookValue(asset: $asset, referenceDate: $referenceDate),
			'fiscalBookValue' => $this->fiscalBookValue(asset: $asset, referenceDate: $referenceDate),
		];

	}//end derivedFields()

	/**
	 * Apply a parallel-stream depreciation rate against the acquisition cost.
	 *
	 * Helper shared by `commercialBookValue` and `fiscalBookValue`. The
	 * shared formula is `acquisitionCost × (1 − annualRate × yearsElapsed)`,
	 * floored at zero (REQ-FA-004).
	 *
	 * @param array<string,mixed> $asset FixedAsset record.
	 * @param string $referenceDate Reference date (Y-m-d).
	 * @param float $annualRate Annual rate as a fraction (e.g. 0.20).
	 *
	 * @return float Stream book value (>= 0, two decimals).
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	private function streamBookValue(array $asset, string $referenceDate, float $annualRate): float {
		$costCents = $this->toCents(amount: ($asset['acquisitionCost'] ?? 0));
		if ($referenceDate === '') {
			$referenceDate = date('Y-m-d');
		}

		$months = $this->monthsElapsed(
			acquisitionDate: (string)($asset['acquisitionDate'] ?? $referenceDate),
			referenceDate: $referenceDate
		);
		$factor = max(0.0, (1.0 - ($annualRate * ($months / 12.0))));
		$valueCents = (int)round($costCents * $factor);
		return $this->fromCents(cents: max(0, $valueCents));
	}//end streamBookValue()
}//end class
