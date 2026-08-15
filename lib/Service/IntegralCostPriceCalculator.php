<?php

/**
 * Integral Cost Price Calculator (WMO IKP — Wet Markt en Overheid)
 *
 * Pure-logic calculator for the integrale-kostprijs (IKP) per commercial
 * activity per period (REQ-WMO-002). The calculator sums the 6 component
 * groups — directPayrollCost, directeMaterialen, directDepreciations,
 * indirecteOverhead (via BBV task_field 0.4 sleutel), capital_cost (WACC),
 * winstopslag — and computes compliance against the gehanteerd rate
 * (Art. 25i Mededingingswet integrale-kostprijs check).
 *
 * All money arithmetic uses integer cents to avoid IEEE-754 drift, mirroring
 * IcpCalculator and TrialBalanceCalculator. The caller (a ScheduledWorkflow
 * runner or service) feeds plain arrays of GL lines and the geldende
 * OverheadDistributionRule, this helper returns a plain IKP record array
 * that the OR ObjectService saves verbatim.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Side-effect-free Integral Cost Price arithmetic helper (REQ-WMO-002).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-5
 */
class IntegralCostPriceCalculator {
	/**
	 * Default WACC capital_cost rate (4.5%, configurable per administration).
	 *
	 * @var float
	 */
	public const DEFAULT_WACC = 0.045;

	/**
	 * Default profit mark-up rate (3%, configurable per activity per period).
	 *
	 * @var float
	 */
	public const DEFAULT_PROFIT_MARKUP = 0.03;

	/**
	 * Convert a money amount to integer cents (REQ-WMO-002 precision).
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents (half-even rounding).
	 *
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-5
	 */
	public function toCents(mixed $amount): int {
		return (int)round((float)($amount ?? 0) * 100, 0, PHP_ROUND_HALF_EVEN);
	}//end toCents()

	/**
	 * Convert integer cents back to a float money amount.
	 *
	 * @param int $cents Amount in whole cents.
	 *
	 * @return float Money amount in EUR.
	 *
	 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p1-5
	 */
	public function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

	/**
	 * Sum direct GL lines matching the activity's cost_centre/cost_object (REQ-WMO-002).
	 *
	 * @param array<int,array<string,mixed>> $glLines GL lines to scan.
	 * @param string $costCentre Kostenplaats code to match.
	 * @param string $costObject Kostendrager code to match.
	 * @param string $accountKind One of `payroll_cost`, `materialen`, `depreciations`.
	 *
	 * @return int Sum of matching GL lines in cents.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-002
	 */
	public function sumDirectCosts(array $glLines, string $costCentre, string $costObject, string $accountKind): int {
		$totalCents = 0;

		foreach ($glLines as $line) {
			if (is_array($line) === false) {
				continue;
			}

			$kp = (string)($line['costCentre'] ?? $line['costCentreCode'] ?? '');
			$kd = (string)($line['costObject'] ?? $line['costObjectCode'] ?? '');
			$kind = (string)($line['accountKind'] ?? $line['kind'] ?? '');

			if ($kp !== $costCentre || $kd !== $costObject) {
				continue;
			}

			if ($kind !== $accountKind) {
				continue;
			}

			$amount = (float)($line['amount'] ?? 0);
			if ($amount < 0) {
				// Credit notes / reversals are subtracted (sign-preserved).
				$totalCents += $this->toCents(amount: $amount);
				continue;
			}

			$totalCents += $this->toCents(amount: $amount);
		}//end foreach

		return $totalCents;
	}//end sumDirectCosts()

	/**
	 * Apply the geldende OverheadDistributionRule to the corporate overhead pool (REQ-WMO-002).
	 *
	 * Implements the BBV-sleutel inheritance: the rule.bronTaakvelden / rule.basis
	 * carry the BBV task_field 0.4 overhead, the rule's per-bucket ratios distribute
	 * it to the activity's overhead components.
	 *
	 * @param int $corporateOverheadCents Corporate overhead pool in cents.
	 * @param array<string,mixed> $rule The OverheadDistributionRule.
	 * @param string $costObject Kostendrager code (filter ratios to this drager).
	 *
	 * @return array<string,int> Per-bucket overhead in cents (huisvesting, ict, directieEnStaf, facilitair, custom).
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md#req-wmo-003
	 */
	public function distributeOverhead(int $corporateOverheadCents, array $rule, string $costObject): array {
		$buckets = [];

		$ratios = $rule['ratios'] ?? $rule['verdeelsleutel'] ?? [];
		if (is_array($ratios) === false) {
			return $buckets;
		}

		foreach ($ratios as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$entryDrager = (string)($entry['costObject'] ?? $entry['costObjectCode'] ?? '');
			if ($entryDrager !== '' && $entryDrager !== $costObject) {
				continue;
			}

			$bucket = (string)($entry['bucket'] ?? $entry['category'] ?? 'other');
			$ratio = (float)($entry['ratio'] ?? 0);

			if ($ratio < 0.0) {
				$ratio = 0.0;
			}

			if ($ratio > 1.0) {
				$ratio = 1.0;
			}

			$bucketCents = (int)round($corporateOverheadCents * $ratio, 0, PHP_ROUND_HALF_EVEN);
			$buckets[$bucket] = ($buckets[$bucket] ?? 0) + $bucketCents;
		}//end foreach

		return $buckets;
	}//end distributeOverhead()

	/**
	 * Calculate capital_cost via WACC on invested book value (REQ-WMO-002).
	 *
	 * Vermogenskosten = invested book value × WACC × period-fraction. Period-fraction
	 * defaults to 1.0 (annual); pass 0.25 for a quarter, ~0.0833 for a month.
	 *
	 * @param int $investedBookValueCents Invested book value (asset base) in cents.
	 * @param float $waccRate Annual WACC rate (e.g. 0.045 for 4.5%).
	 * @param float $periodFraction Fraction of a year covered (default 1.0).
	 *
	 * @return int Vermogenskosten in cents.
	 */
	public function calculateVermogenskosten(int $investedBookValueCents, float $waccRate, float $periodFraction = 1.0): int {
		if ($waccRate < 0.0) {
			$waccRate = 0.0;
		}

		if ($periodFraction < 0.0) {
			$periodFraction = 0.0;
		}

		return (int)round($investedBookValueCents * $waccRate * $periodFraction, 0, PHP_ROUND_HALF_EVEN);
	}//end calculateVermogenskosten()

	/**
	 * Calculate the profit mark-up on the sum of all other components (REQ-WMO-002).
	 *
	 * @param int $baseCents Sum of direct + indirect + capital_cost in cents.
	 * @param float $rate Profit mark-up rate (default 0.03 = 3%).
	 *
	 * @return int Profit mark-up in cents.
	 */
	public function calculateProfitMarkup(int $baseCents, float $rate = self::DEFAULT_PROFIT_MARKUP): int {
		if ($rate < 0.0) {
			$rate = 0.0;
		}

		return (int)round($baseCents * $rate, 0, PHP_ROUND_HALF_EVEN);
	}//end calculateProfitMarkup()

	/**
	 * Calculate kostprijs per unit (REQ-WMO-002).
	 *
	 * @param int $totaleCostCents Total cost in cents.
	 * @param float $soldUnits Units sold (must be > 0).
	 *
	 * @return float|null Cost per unit in EUR, or null if eenheden is 0.
	 */
	public function calculateKostprijsPerEenheid(int $totaleCostCents, float $soldUnits): ?float {
		if ($soldUnits <= 0.0) {
			return null;
		}

		return round(($totaleCostCents / 100) / $soldUnits, 4);
	}//end calculateKostprijsPerEenheid()

	/**
	 * Determine compliance status: appliedRate >= costPricePerUnit (REQ-WMO-004 / Art. 25i).
	 *
	 * @param float|null $appliedRate Actual price charged per unit (EUR).
	 * @param float|null $costPricePerUnit IKP per unit (EUR).
	 * @param int $totaleCostCents Total cost in cents (fallback when no eenheden).
	 * @param float|null $revenueEur Omzet in EUR (fallback when no eenheden).
	 *
	 * @return bool True when compliant (Art. 25i integrale-kostprijs rule).
	 */
	public function isCompliant(?float $appliedRate, ?float $costPricePerUnit, int $totaleCostCents, ?float $revenueEur = null): bool {
		if ($appliedRate !== null && $costPricePerUnit !== null) {
			return $appliedRate >= $costPricePerUnit;
		}

		if ($revenueEur !== null) {
			$revenueCents = $this->toCents(amount: $revenueEur);
			return $revenueCents >= $totaleCostCents;
		}

		// Insufficient data — default to non-compliant so the operator sees the gap.
		return false;
	}//end isCompliant()

	/**
	 * Compose a full IntegralCostPrice record (REQ-WMO-002).
	 *
	 * @param array<string,mixed> $input Calculation inputs (commercialActivityId,
	 *                                   period, administrationId, cost_centre,
	 *                                   cost_object, glLines, corporateOverheadCents,
	 *                                   overheadRule, investedBookValueCents, waccRate,
	 *                                   profitMarkupRate, periodFraction, soldUnits,
	 *                                   unitLabel, appliedRate, omzetEur, status).
	 *
	 * @return array<string,mixed> IKP record matching the schema.
	 */
	public function compose(array $input): array {
		$glLines = (array)($input['glLines'] ?? []);
		$kp = (string)$input['costCentre'];
		$kd = (string)$input['costObject'];

		$payrollCostCents = $this->sumDirectCosts(glLines: $glLines, costCentre: $kp, costObject: $kd, accountKind: 'payrollCost');
		$materialenCents = $this->sumDirectCosts(glLines: $glLines, costCentre: $kp, costObject: $kd, accountKind: 'materialen');
		$depreciationsCents = $this->sumDirectCosts(glLines: $glLines, costCentre: $kp, costObject: $kd, accountKind: 'depreciations');

		$overheadBuckets = $this->distributeOverhead(
			corporateOverheadCents: (int)($input['corporateOverheadCents'] ?? 0),
			rule: (array)($input['overheadRule'] ?? []),
			costObject: $kd
		);

		$overheadTotalCents = 0;
		foreach ($overheadBuckets as $cents) {
			$overheadTotalCents += $cents;
		}

		$vermogensCents = $this->calculateVermogenskosten(
			investedBookValueCents: (int)($input['investedBookValueCents'] ?? 0),
			waccRate: (float)($input['waccRate'] ?? self::DEFAULT_WACC),
			periodFraction: (float)($input['periodFraction'] ?? 1.0)
		);

		$baseBeforeMarkup = ($payrollCostCents + $materialenCents + $depreciationsCents + $overheadTotalCents + $vermogensCents);
		// The old spelling is still read: a caller that has not been updated would
		// otherwise fall through to the DEFAULT rate, which is not an error but a
		// silently different price.
		$profitMarkupCents = $this->calculateProfitMarkup(
			baseCents: $baseBeforeMarkup,
			rate: (float)($input['profitMarkupRate'] ?? $input['winstopslagRate'] ?? self::DEFAULT_PROFIT_MARKUP)
		);

		$totaleCostCents = ($baseBeforeMarkup + $profitMarkupCents);

		$soldUnits = (float)($input['soldUnits'] ?? 0);
		$costPricePerUnit = $this->calculateKostprijsPerEenheid(totaleCostCents: $totaleCostCents, soldUnits: $soldUnits);

		$appliedRate = null;
		if (isset($input['appliedRate']) === true) {
			$appliedRate = (float)$input['appliedRate'];
		}

		$marge = null;
		$margePercentage = null;
		if ($appliedRate !== null && $costPricePerUnit !== null) {
			$marge = round(($appliedRate - $costPricePerUnit), 4);
			$base = 1.0;
			if ($costPricePerUnit > 0.0) {
				$base = $costPricePerUnit;
			}

			$margePercentage = round((($marge / $base) * 100), 4);
		}

		$revenueEur = null;
		if (isset($input['omzetEur']) === true) {
			$revenueEur = (float)$input['omzetEur'];
		}

		$compliant = $this->isCompliant(
			appliedRate: $appliedRate,
			costPricePerUnit: $costPricePerUnit,
			totaleCostCents: $totaleCostCents,
			revenueEur: $revenueEur
		);

		$verkochteUnitsOut = null;
		if ($soldUnits > 0.0) {
			$verkochteUnitsOut = $soldUnits;
		}

		return [
			'commercialActivityId' => (string)$input['commercialActivityId'],
			'period' => (string)$input['period'],
			'calculatedOn' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM),
			'status' => (string)($input['status'] ?? 'voorlopig'),
			'componenten' => [
				'directPayrollCost' => $this->fromCents(cents: $payrollCostCents),
				'directMaterials' => $this->fromCents(cents: $materialenCents),
				'directDepreciations' => $this->fromCents(cents: $depreciationsCents),
				'indirecteOverhead' => array_map(fn (int $c): float => $this->fromCents(cents: $c), $overheadBuckets),
				'capitalCost' => $this->fromCents(cents: $vermogensCents),
				'profitMarkup' => $this->fromCents(cents: $profitMarkupCents),
			],
			'totalCost' => $this->fromCents(cents: $totaleCostCents),
			'soldUnits' => $verkochteUnitsOut,
			'unitLabel' => ($input['unitLabel'] ?? null),
			'costPricePerUnit' => $costPricePerUnit,
			'appliedRate' => $appliedRate,
			'marge' => $marge,
			'margePercentage' => $margePercentage,
			'compliant' => $compliant,
			'administrationId' => (string)$input['administrationId'],
		];

	}//end compose()
}//end class
