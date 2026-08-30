<?php

/**
 * WMO Compliance Calculator
 *
 * Pure-logic helper for the Wet Markt en Overheid (Mededingingswet hfd. 4b)
 * commercial-activity bookkeeping capability. Holds the side-effect-free
 * arithmetic that the WMO services apply after fetching GL + CommercialActivity
 * + AllocationRule data via the OpenRegister ObjectService: computing the
 * integrale kostprijs from its six statutory components (REQ-WMO-002), deriving
 * the kostprijs per eenheid and the Art. 25i compliance flag, splitting a
 * transaction across publieke/commerciële sub-administraties per the geldende
 * OverheadDistributionRule (REQ-WMO-003), the kostendekkingsratio for the
 * jaarrekening-bijlage (REQ-WMO-004), and the stale-activity review trigger
 * (REQ-WMO-001c). All money arithmetic is performed in integer cents to avoid
 * IEEE-754 equality drift, mirroring TrialBalanceCalculator and BalanceGuard.
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
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Side-effect-free WMO compliance arithmetic helper.
 *
 * No OpenRegister dependency: every method takes plain arrays/scalars and
 * returns plain arrays/scalars so the logic is unit-testable in isolation. The
 * scheduled IKP runner, the allocation splitter and the jaarrekening exporter
 * wire this helper to live GL + register data via the ObjectService.
 *
 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
 */
class WmoComplianceCalculator {
	/**
	 * Default weighted-average cost-of-capital rate for vermogenskosten (REQ-WMO-002).
	 *
	 * @var float
	 */
	public const DEFAULT_WACC = 0.045;

	/**
	 * Default profit mark-up percentage on total direct + overhead cost.
	 *
	 * @var float
	 */
	public const DEFAULT_PROFIT_MARKUP = 0.03;

	/**
	 * Number of days after which a commercial activity is due for annual review.
	 *
	 * @var int
	 */
	public const REVIEW_INTERVAL_DAYS = 365;

	/**
	 * Convert a money amount to integer cents (precision rule).
	 *
	 * @param mixed $amount Money amount (float|int|numeric-string|null).
	 *
	 * @return int Amount in whole cents.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
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
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function fromCents(int $cents): float {
		return ($cents / 100);
	}//end fromCents()

	/**
	 * Sum the indirecteOverhead component map (huisvesting, ict, ...) in cents.
	 *
	 * @param array<string,mixed> $overhead The overhead component map.
	 *
	 * @return int Total overhead in cents.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function overheadTotalCents(array $overhead): int {
		$total = 0;
		foreach ($overhead as $value) {
			$total += $this->toCents(amount: $value);
		}

		return $total;
	}//end overheadTotalCents()

	/**
	 * Compute the integrale kostprijs from its six statutory components (REQ-WMO-002).
	 *
	 * The IKP equals directe loonkosten + directe materialen + directe
	 * afschrijvingen + indirecte overhead (sum of the BBV-sleutel sub-allocations)
	 * + vermogenskosten + winstopslag. Returns the totaleKosten and, when
	 * verkochteEenheden is provided and positive, the kostprijsPerEenheid — both
	 * as float money rounded to whole cents.
	 *
	 * @param array<string,mixed> $componenten The six component groups. indirecteOverhead
	 *                                         is a map summed internally.
	 * @param float|null $soldUnits Units sold in the period (null when not tracked).
	 *
	 * @return array{totalCost: float, costPricePerUnit: float|null} The cost totals.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function integralCostPrice(array $componenten, ?float $soldUnits = null): array {
		$overhead = [];
		if (isset($componenten['indirecteOverhead']) === true && is_array($componenten['indirecteOverhead']) === true) {
			$overhead = $componenten['indirecteOverhead'];
		}

		$totalCents = $this->toCents(amount: ($componenten['directPayrollCost'] ?? 0));
		$totalCents += $this->toCents(amount: ($componenten['directMaterials'] ?? 0));
		$totalCents += $this->toCents(amount: ($componenten['directDepreciations'] ?? 0));
		$totalCents += $this->overheadTotalCents(overhead: $overhead);
		$totalCents += $this->toCents(amount: ($componenten['capitalCost'] ?? 0));
		$totalCents += $this->toCents(amount: ($componenten['profitMarkup'] ?? 0));

		$perUnit = null;
		if ($soldUnits !== null && $soldUnits > 0.0) {
			$perUnit = round(($this->fromCents(cents: $totalCents) / $soldUnits), 2);
		}

		return [
			'totalCost' => $this->fromCents(cents: $totalCents),
			'costPricePerUnit' => $perUnit,
		];

	}//end integralCostPrice()

	/**
	 * Compute vermogenskosten from a capital base and WACC rate (REQ-WMO-002).
	 *
	 * @param float $capitalBase The activity's capital base in EUR (e.g. equipment book value).
	 * @param float|null $waccRate The WACC rate (defaults to DEFAULT_WACC when null).
	 *
	 * @return float Vermogenskosten in EUR rounded to whole cents.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function vermogenskosten(float $capitalBase, ?float $waccRate = null): float {
		$rate = ($waccRate ?? self::DEFAULT_WACC);
		return $this->fromCents(cents: $this->toCents(amount: ($capitalBase * $rate)));
	}//end vermogenskosten()

	/**
	 * Compute the profit mark-up as a percentage of the costs-before-mark-up (REQ-WMO-002).
	 *
	 * @param float $costsBeforeMarkup Sum of direct + overhead + vermogenskosten in EUR.
	 * @param float|null $rate The profit mark-up rate (defaults to DEFAULT_PROFIT_MARKUP when null).
	 *
	 * @return float Profit mark-up in EUR rounded to whole cents.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function profitMarkup(float $costsBeforeMarkup, ?float $rate = null): float {
		$pct = ($rate ?? self::DEFAULT_PROFIT_MARKUP);
		return $this->fromCents(cents: $this->toCents(amount: ($costsBeforeMarkup * $pct)));
	}//end profitMarkup()

	/**
	 * Derive the Art. 25i compliance flag, marge and margePercentage (REQ-WMO-002).
	 *
	 * An activity is compliant when the gehanteerd tarief covers the integrale
	 * kostprijs. When verkochteEenheden are tracked the comparison is per unit
	 * (gehanteerdTarief >= kostprijsPerEenheid); otherwise it is against the
	 * totaleKosten. When no tarief is recorded the activity cannot yet be judged
	 * and is treated as non-compliant (a price MUST be set to prove cost coverage).
	 *
	 * @param float|null $appliedRate The price charged (per unit or total) in EUR.
	 * @param float $totalCost The integral cost total in EUR.
	 * @param float|null $costPricePerUnit The per-unit IKP in EUR (null when units not tracked).
	 *
	 * @return array{compliant: bool, marge: float, margePercentage: float} The compliance verdict.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function complianceVerdict(?float $appliedRate, float $totalCost, ?float $costPricePerUnit = null): array {
		if ($appliedRate === null) {
			return [
				'compliant' => false,
				'marge' => 0.0,
				'margePercentage' => 0.0,
			];
		}

		$reference = $totalCost;
		if ($costPricePerUnit !== null) {
			$reference = $costPricePerUnit;
		}

		$margeCents = ($this->toCents(amount: $appliedRate) - $this->toCents(amount: $reference));
		$referenceCents = $this->toCents(amount: $reference);

		$margePercentage = 0.0;
		if ($referenceCents > 0) {
			$margePercentage = round((($margeCents / $referenceCents) * 100), 2);
		}

		return [
			'compliant' => ($margeCents >= 0),
			'marge' => $this->fromCents(cents: $margeCents),
			'margePercentage' => $margePercentage,
		];

	}//end complianceVerdict()

	/**
	 * Split a transaction amount across sub-administraties per allocation ratios (REQ-WMO-003).
	 *
	 * Applies each ratio to the original amount in integer cents, then assigns any
	 * rounding remainder to the largest split so the split amounts sum exactly to
	 * the original (no cent is lost or created). Ratios are expected to sum to 1.0;
	 * the method does not renormalise but does conserve the total.
	 *
	 * @param float $originalAmount The total amount to split, in EUR.
	 * @param array<int,array<string,mixed>> $ruleTargets Allocation targets, each with a
	 *                                                    kostendrager, ratio and optional
	 *                                                    dimensie/grootboek.
	 *
	 * @return array<int,array<string,mixed>> The split lines with conserved amounts.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function splitTransaction(float $originalAmount, array $ruleTargets): array {
		$originalCents = $this->toCents(amount: $originalAmount);
		$splits = [];
		$assignedCents = 0;
		$largestIndex = 0;
		$largestCents = -1;

		foreach (array_values($ruleTargets) as $index => $target) {
			$ratio = (float)($target['ratio'] ?? 0);
			$cents = (int)round($originalCents * $ratio);
			$assignedCents += $cents;
			if ($cents > $largestCents) {
				$largestCents = $cents;
				$largestIndex = $index;
			}

			$splits[] = [
				'costObject' => (string)($target['costObject'] ?? ''),
				'ratio' => $ratio,
				'amount' => $this->fromCents(cents: $cents),
				'generalLedger' => ($target['generalLedger'] ?? null),
				'dimension' => (string)($target['dimension'] ?? ''),
			];
		}

		// Conserve the total: push any rounding remainder onto the largest split.
		$remainder = ($originalCents - $assignedCents);
		if ($remainder !== 0 && $splits !== []) {
			$correctedCents = ($this->toCents(amount: $splits[$largestIndex]['amount']) + $remainder);
			$splits[$largestIndex]['amount'] = $this->fromCents(cents: $correctedCents);
		}

		return $splits;
	}//end splitTransaction()

	/**
	 * Verify split amounts conserve the original transaction total to the cent.
	 *
	 * @param float $originalAmount The original transaction amount in EUR.
	 * @param array<int,array<string,mixed>> $splits The split lines.
	 *
	 * @return bool True when the split amounts sum exactly to the original.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function splitsAreBalanced(float $originalAmount, array $splits): bool {
		$sum = 0;
		foreach ($splits as $split) {
			$sum += $this->toCents(amount: ($split['amount'] ?? 0));
		}

		return ($sum === $this->toCents(amount: $originalAmount));
	}//end splitsAreBalanced()

	/**
	 * Compute the kostendekkingsratio for the jaarrekening-bijlage (REQ-WMO-004).
	 *
	 * Ratio = omzet / integrale kostprijs, expressed as a percentage. An activity
	 * is Art. 25i compliant when the ratio is >= 100%. A zero (or non-positive)
	 * integrale kostprijs yields a 0.0 ratio and a non-compliant verdict rather
	 * than a division by zero.
	 *
	 * @param float $revenue Annual revenue from customer billings in EUR.
	 * @param float $integralCostPrice The definitief IKP total in EUR.
	 *
	 * @return array{ratio: float, compliant: bool} The coverage ratio and compliance verdict.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function kostendekkingsratio(float $revenue, float $integralCostPrice): array {
		$costPriceCents = $this->toCents(amount: $integralCostPrice);
		if ($costPriceCents <= 0) {
			return [
				'ratio' => 0.0,
				'compliant' => false,
			];
		}

		$ratio = round((($this->toCents(amount: $revenue) / $costPriceCents) * 100), 2);

		return [
			'ratio' => $ratio,
			'compliant' => ($ratio >= 100.0),
		];

	}//end kostendekkingsratio()

	/**
	 * Determine whether a commercial activity is due for annual review (REQ-WMO-001c).
	 *
	 * An activity is due when its lastReviewedAt is null (never reviewed) or more
	 * than REVIEW_INTERVAL_DAYS old relative to the reference date. Unparseable
	 * timestamps are treated as due so a malformed record is never silently
	 * skipped from the compliance review cycle.
	 *
	 * @param string|null $lastReviewedAt The ISO-8601 last-review timestamp (or null).
	 * @param string $referenceDate The ISO-8601 reference date (e.g. today).
	 *
	 * @return bool True when an annual review task should be generated.
	 *
	 * @spec openspec/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function reviewIsDue(?string $lastReviewedAt, string $referenceDate): bool {
		if ($lastReviewedAt === null || trim($lastReviewedAt) === '') {
			return true;
		}

		$last = strtotime($lastReviewedAt);
		$ref = strtotime($referenceDate);
		if ($last === false || $ref === false) {
			return true;
		}

		$ageDays = (int)floor((($ref - $last) / 86400));

		return ($ageDays > self::REVIEW_INTERVAL_DAYS);
	}//end reviewIsDue()
}//end class
