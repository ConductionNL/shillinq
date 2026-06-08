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
 * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
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
 * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class DepreciationCalculator
{
    /**
     * Convert a money amount to integer cents.
     *
     * @param mixed $amount Money amount (float|int|numeric-string|null).
     *
     * @return int Amount in whole cents.
     *
     * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
     */
    public function toCents(mixed $amount): int
    {
        return (int) round((float) ($amount ?? 0) * 100);

    }//end toCents()

    /**
     * Convert integer cents back to a float money amount.
     *
     * @param int $cents Whole cents.
     *
     * @return float Money amount (two decimals).
     *
     * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
     */
    public function fromCents(int $cents): float
    {
        return round($cents / 100, 2);

    }//end fromCents()

    /**
     * Number of whole months elapsed between two ISO-8601 dates.
     *
     * Returns 0 when `referenceDate` is on or before `acquisitionDate`.
     * Counts complete calendar months from the acquisition date.
     *
     * @param string $acquisitionDate Asset acquisition date (Y-m-d).
     * @param string $referenceDate   Reference date (Y-m-d).
     *
     * @return int Whole months elapsed (>= 0).
     *
     * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
     */
    public function monthsElapsed(string $acquisitionDate, string $referenceDate): int
    {
        $start = strtotime($acquisitionDate);
        $end   = strtotime($referenceDate);
        if ($start === false || $end === false || $end <= $start) {
            return 0;
        }

        $startYear  = (int) date('Y', $start);
        $startMonth = (int) date('m', $start);
        $startDay   = (int) date('d', $start);
        $endYear    = (int) date('Y', $end);
        $endMonth   = (int) date('m', $end);
        $endDay     = (int) date('d', $end);

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
     * @param array<string,mixed> $asset         FixedAsset record.
     * @param string              $referenceDate Reference date (Y-m-d, used by degressive).
     *
     * @return float Monthly depreciation charge (>= 0, two decimals).
     *
     * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
     */
    public function monthlyDepreciation(array $asset, string $referenceDate=''): float
    {
        $method = (string) ($asset['depreciationMethod'] ?? 'none');
        if ($method === 'none') {
            return 0.0;
        }

        $costCents     = $this->toCents(amount: ($asset['acquisitionCost'] ?? 0));
        $residualCents = $this->toCents(amount: ($asset['residualValue'] ?? 0));
        $usefulLife    = (int) ($asset['usefulLifeMonths'] ?? 0);

        if ($method === 'linear') {
            if ($usefulLife <= 0) {
                return 0.0;
            }

            $depreciableCents = max(0, ($costCents - $residualCents));
            $monthlyCents     = (int) round($depreciableCents / $usefulLife);
            return $this->fromCents(cents: $monthlyCents);
        }

        if ($method === 'degressive') {
            $rate = (float) ($asset['degressiveRate'] ?? 0);
            if ($rate <= 0.0) {
                return 0.0;
            }

            $bookValue      = $this->currentBookValue(asset: $asset, referenceDate: $referenceDate);
            $bookValueCents = $this->toCents(amount: $bookValue);
            $monthlyCents   = (int) round(($bookValueCents * $rate) / 12);
            return $this->fromCents(cents: $monthlyCents);
        }

        // units-of-production and any other method fall through to 0 by
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
     * @param array<string,mixed> $asset         FixedAsset record.
     * @param string              $referenceDate Reference date (Y-m-d).
     *
     * @return float Current book value (>= residualValue, two decimals).
     *
     * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
     */
    public function currentBookValue(array $asset, string $referenceDate=''): float
    {
        $costCents = $this->toCents(amount: ($asset['acquisitionCost'] ?? 0));
        $method    = (string) ($asset['depreciationMethod'] ?? 'none');
        if ($method === 'none') {
            return $this->fromCents(cents: $costCents);
        }

        if ($referenceDate === '') {
            $referenceDate = date('Y-m-d');
        }

        $residualCents = $this->toCents(amount: ($asset['residualValue'] ?? 0));
        $months        = $this->monthsElapsed(
            acquisitionDate: (string) ($asset['acquisitionDate'] ?? $referenceDate),
            referenceDate: $referenceDate
        );

        if ($method === 'linear') {
            $usefulLife = (int) ($asset['usefulLifeMonths'] ?? 0);
            if ($usefulLife <= 0) {
                return $this->fromCents(cents: $costCents);
            }

            $depreciableCents = max(0, ($costCents - $residualCents));
            $perMonthCents    = (int) round($depreciableCents / $usefulLife);
            $accumCents       = ($perMonthCents * $months);
            $bookCents        = max($residualCents, ($costCents - $accumCents));
            return $this->fromCents(cents: $bookCents);
        }

        if ($method === 'degressive') {
            $rate = (float) ($asset['degressiveRate'] ?? 0);
            if ($rate <= 0.0) {
                return $this->fromCents(cents: $costCents);
            }

            $factor    = max(0.0, (1.0 - ($rate * ($months / 12.0))));
            $bookCents = (int) round($costCents * $factor);
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
     * @param array<string,mixed> $asset         FixedAsset record.
     * @param string              $referenceDate Reference date (Y-m-d).
     *
     * @return float Commercial-stream book value (>= 0, two decimals).
     *
     * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
     */
    public function commercialBookValue(array $asset, string $referenceDate=''): float
    {
        $rate = ($asset['commercialRate'] ?? null);
        if ($rate === null) {
            return $this->currentBookValue(asset: $asset, referenceDate: $referenceDate);
        }

        return $this->streamBookValue(
            asset: $asset,
            referenceDate: $referenceDate,
            annualRate: (float) $rate
        );

    }//end commercialBookValue()

    /**
     * Book value under the fiscal stream (REQ-FA-004).
     *
     * When `fiscalRate` is null, falls back to `currentBookValue`. Otherwise
     * applies the fiscal rate as `acquisitionCost × (1 − fiscalRate × yearsElapsed)`,
     * floored at zero (mirrors the schema's calculation expression).
     *
     * @param array<string,mixed> $asset         FixedAsset record.
     * @param string              $referenceDate Reference date (Y-m-d).
     *
     * @return float Fiscal-stream book value (>= 0, two decimals).
     *
     * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
     */
    public function fiscalBookValue(array $asset, string $referenceDate=''): float
    {
        $rate = ($asset['fiscalRate'] ?? null);
        if ($rate === null) {
            return $this->currentBookValue(asset: $asset, referenceDate: $referenceDate);
        }

        return $this->streamBookValue(
            asset: $asset,
            referenceDate: $referenceDate,
            annualRate: (float) $rate
        );

    }//end fiscalBookValue()

    /**
     * Read all four derived fields at once (REQ-FA-003, REQ-FA-004).
     *
     * Convenience aggregator the OR ScheduledWorkflow uses to post both
     * streams at the same tick. Returns floats (two decimals) keyed by the
     * `x-openregister-calculations` field names.
     *
     * @param array<string,mixed> $asset         FixedAsset record.
     * @param string              $referenceDate Reference date (Y-m-d).
     *
     * @return array{monthlyDepreciation:float, currentBookValue:float, commercialBookValue:float, fiscalBookValue:float}
     *
     * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
     */
    public function derivedFields(array $asset, string $referenceDate=''): array
    {
        return [
            'monthlyDepreciation'  => $this->monthlyDepreciation(asset: $asset, referenceDate: $referenceDate),
            'currentBookValue'     => $this->currentBookValue(asset: $asset, referenceDate: $referenceDate),
            'commercialBookValue'  => $this->commercialBookValue(asset: $asset, referenceDate: $referenceDate),
            'fiscalBookValue'      => $this->fiscalBookValue(asset: $asset, referenceDate: $referenceDate),
        ];

    }//end derivedFields()

    /**
     * Apply a parallel-stream depreciation rate against the acquisition cost.
     *
     * Helper shared by `commercialBookValue` and `fiscalBookValue`. The
     * shared formula is `acquisitionCost × (1 − annualRate × yearsElapsed)`,
     * floored at zero (REQ-FA-004).
     *
     * @param array<string,mixed> $asset         FixedAsset record.
     * @param string              $referenceDate Reference date (Y-m-d).
     * @param float               $annualRate    Annual rate as a fraction (e.g. 0.20).
     *
     * @return float Stream book value (>= 0, two decimals).
     *
     * @spec openspec/changes/add-shillinq-fixed-assets-depreciation/specs/bookkeeping-fixed-assets-depreciation/spec.md
     */
    private function streamBookValue(array $asset, string $referenceDate, float $annualRate): float
    {
        $costCents = $this->toCents(amount: ($asset['acquisitionCost'] ?? 0));
        if ($referenceDate === '') {
            $referenceDate = date('Y-m-d');
        }

        $months   = $this->monthsElapsed(
            acquisitionDate: (string) ($asset['acquisitionDate'] ?? $referenceDate),
            referenceDate: $referenceDate
        );
        $factor   = max(0.0, (1.0 - ($annualRate * ($months / 12.0))));
        $valueCents = (int) round($costCents * $factor);
        return $this->fromCents(cents: max(0, $valueCents));

    }//end streamBookValue()

}//end class
