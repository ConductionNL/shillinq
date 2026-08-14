<?php

/**
 * Cross-Subsidy Detector (WMO REQ-WMO-007)
 *
 * Pure-logic detector for the 6 cross-subsidy risk scenarios plus the Phase 3
 * bevoordeling-risk scenario (REQ-WMO-012). Iterates a commercial activity
 * with its IKP, allocation, ABB and (optional) benchmark records, and returns
 * `AlertLog` records for any matching scenario.
 *
 * Scenarios (REQ-WMO-007):
 *   1. loss-financing                  — IKP-marge < 0 for 2+ consecutive months
 *   2. omzet-spike-no-ikp-update       — omzet growth > 25% YoY without IKP recalculation
 *   3. overhead-under-allocation       — indirecteOverhead < 1% of totaleKosten
 *   4. abb-stale                       — exempted activity whose ABB has not been evaluated in > 2 years
 *   5. manual-override-accumulation    — > 5% of allocations for the activity carry handmatige overrides
 *   6. potentiele-overhead-onderschatting — direct cost growth without overhead growth
 *   7. bevoordeling-risk (Phase 3)     — gehanteerdTarief < market-benchmark median × 0.85
 *
 * Each scenario is a pure function on plain arrays so the detector is
 * unit-testable. The caller (a monthly ScheduledWorkflow runner) fetches OR
 * data and persists the returned AlertLog records.
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
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Side-effect-free cross-subsidy detector for WMO compliance (REQ-WMO-007).
 *
 * @spec openspec/changes/bookkeeping-market-government-separation/tasks.md#p2-12
 *
 * @SuppressWarnings(PHPMD.ElseExpression) Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 */
class CrossSubsidyDetector {
	/**
	 * Scenario thresholds (configurable per administration when wired to ConfigService).
	 */
	public const LOSS_FINANCING_MONTHS = 2;
	public const OMZET_SPIKE_RATIO = 1.25;
	public const OVERHEAD_UNDER_ALLOCATION_FLOOR_RATIO = 0.01;
	public const ABB_STALE_YEARS = 2;
	public const MANUAL_OVERRIDE_RATIO = 0.05;
	public const OVERHEAD_UNDERSCHATTING_DIRECT_GROWTH = 0.20;
	public const BEVOORDELING_DISCOUNT_THRESHOLD = 0.85;
	public const ESCALATION_WEEKS = 4;

	/**
	 * Default user-id used as the open-alert assignee.
	 *
	 * @var string
	 */
	public const DEFAULT_ASSIGNEE = 'concerncontroller';

	/**
	 * Default escalation assignee after 4 weeks (REQ-WMO-007 §escalation).
	 *
	 * @var string
	 */
	public const ESCALATION_ASSIGNEE = 'gemeentesecretaris';

	/**
	 * Detect loss-financing alert: IKP-marge < 0 for N consecutive months (REQ-WMO-007 §1).
	 *
	 * @param array<int,array<string,mixed>> $ikpHistory Recent IKP records (most-recent first).
	 * @param int $consecutiveMonths Required consecutive negative-marge months.
	 *
	 * @return bool True when the loss-financing scenario triggers.
	 */
	public function detectLossFinancing(array $ikpHistory, int $consecutiveMonths = self::LOSS_FINANCING_MONTHS): bool {
		if (count($ikpHistory) < $consecutiveMonths) {
			return false;
		}

		$consecutive = 0;
		foreach ($ikpHistory as $ikp) {
			if (is_array($ikp) === false) {
				continue;
			}

			$marge = $ikp['marge'] ?? null;
			if ($marge === null) {
				$consecutive = 0;
				continue;
			}

			if ((float)$marge < 0.0) {
				$consecutive++;
				if ($consecutive >= $consecutiveMonths) {
					return true;
				}
			} else {
				$consecutive = 0;
			}
		}

		return false;
	}//end detectLossFinancing()

	/**
	 * Detect omzet-spike-without-IKP-update (REQ-WMO-007 §2).
	 *
	 * @param float $currentYearRevenue Current FY omzet in EUR.
	 * @param float $priorYearRevenue Prior FY omzet in EUR.
	 * @param string|null $lastIkpPeriod Most-recent IKP period (YYYY-MM / YYYY-Qn).
	 * @param string $today Today's ISO date (YYYY-MM-DD).
	 * @param float $spikeRatio Spike ratio threshold (default 1.25 = 25%).
	 *
	 * @return bool True when omzet jumped >= spikeRatio without an IKP update in the same FY.
	 */
	public function detectOmzetSpikeNoIkpUpdate(
		float $currentYearRevenue,
		float $priorYearRevenue,
		?string $lastIkpPeriod,
		string $today,
		float $spikeRatio = self::OMZET_SPIKE_RATIO,
	): bool {
		if ($priorYearRevenue <= 0.0) {
			return false;
		}

		if (($currentYearRevenue / $priorYearRevenue) < $spikeRatio) {
			return false;
		}

		// Compare last IKP period's year against the current FY.
		try {
			$now = new DateTimeImmutable($today);
		} catch (\Throwable) {
			return false;
		}

		$currentYear = (int)$now->format('Y');
		if ($lastIkpPeriod === null || $lastIkpPeriod === '') {
			return true;
		}

		$ikpYear = (int)substr($lastIkpPeriod, 0, 4);
		return $ikpYear < $currentYear;
	}//end detectOmzetSpikeNoIkpUpdate()

	/**
	 * Detect overhead under-allocation: indirecteOverhead < 1% of totaleKosten (REQ-WMO-007 §3).
	 *
	 * @param array<string,mixed> $ikp The IKP record.
	 * @param float $floorRatio Floor ratio (default 0.01 = 1%).
	 *
	 * @return bool True when overhead is under-allocated.
	 */
	public function detectOverheadUnderAllocation(array $ikp, float $floorRatio = self::OVERHEAD_UNDER_ALLOCATION_FLOOR_RATIO): bool {
		$totalCost = (float)($ikp['totalCost'] ?? 0);
		if ($totalCost <= 0.0) {
			return false;
		}

		$componenten = (array)($ikp['componenten'] ?? []);
		$overheadBuckets = (array)($componenten['indirecteOverhead'] ?? []);
		$overheadTotal = 0.0;
		foreach ($overheadBuckets as $bucket) {
			$overheadTotal += (float)$bucket;
		}

		return ($overheadTotal / $totalCost) < $floorRatio;
	}//end detectOverheadUnderAllocation()

	/**
	 * Detect ABB-stale: exempted activity whose ABB has not been evaluated in > N years (REQ-WMO-007 §4).
	 *
	 * @param array<string,mixed> $activity The CommercialActivity.
	 * @param array<string,mixed> $abb The linked AlgemeenBelangBesluit.
	 * @param string $today Today's ISO date.
	 * @param int $staleYears Stale-after threshold in years (default 2).
	 *
	 * @return bool True when the ABB is stale.
	 */
	public function detectAbbStale(array $activity, array $abb, string $today, int $staleYears = self::ABB_STALE_YEARS): bool {
		if (((bool)($activity['isExempted'] ?? false)) === false) {
			return false;
		}

		$nextEvaluation = (string)($abb['nextEvaluation'] ?? '');
		if ($nextEvaluation === '') {
			return true;
		}

		try {
			$vol = new DateTimeImmutable($nextEvaluation);
			$now = new DateTimeImmutable($today);
		} catch (\Throwable) {
			return false;
		}

		if ($vol >= $now) {
			return false;
		}

		$diff = $now->diff($vol);
		return ((int)$diff->y) >= $staleYears || (((int)$diff->y) === 0 && (int)$diff->m >= ($staleYears * 12));
	}//end detectAbbStale()

	/**
	 * Detect manual-override accumulation > N% (REQ-WMO-007 §5).
	 *
	 * @param int $manualOverrideCount Count of allocations with automatischToegepast=false.
	 * @param int $totalAllocations Total count of allocations for the activity.
	 * @param float $ratioThreshold Override-rate threshold (default 0.05 = 5%).
	 *
	 * @return bool True when the override rate exceeds the threshold.
	 */
	public function detectManualOverrideAccumulation(
		int $manualOverrideCount,
		int $totalAllocations,
		float $ratioThreshold = self::MANUAL_OVERRIDE_RATIO,
	): bool {
		if ($totalAllocations <= 0) {
			return false;
		}

		return (($manualOverrideCount / $totalAllocations) > $ratioThreshold);
	}//end detectManualOverrideAccumulation()

	/**
	 * Detect potentiele-overhead-onderschatting: direct cost growth without overhead growth (REQ-WMO-007 §6).
	 *
	 * @param float $currentDirectCosts Current period direct costs (sum of 3 direct components in EUR).
	 * @param float $priorDirectCosts Prior period direct costs in EUR.
	 * @param float $currentOverhead Current period indirect overhead in EUR.
	 * @param float $priorOverhead Prior period indirect overhead in EUR.
	 * @param float $growthThreshold Direct-cost growth threshold (default 0.20 = 20%).
	 *
	 * @return bool True when direct costs grew but overhead did not.
	 */
	public function detectOverheadOnderschatting(
		float $currentDirectCosts,
		float $priorDirectCosts,
		float $currentOverhead,
		float $priorOverhead,
		float $growthThreshold = self::OVERHEAD_UNDERSCHATTING_DIRECT_GROWTH,
	): bool {
		if ($priorDirectCosts <= 0.0) {
			return false;
		}

		$directGrowth = (($currentDirectCosts - $priorDirectCosts) / $priorDirectCosts);
		if ($directGrowth < $growthThreshold) {
			return false;
		}

		if ($priorOverhead <= 0.0) {
			return $currentOverhead <= 0.0;
		}

		$overheadGrowth = (($currentOverhead - $priorOverhead) / $priorOverhead);
		return ($overheadGrowth < ($growthThreshold / 2));
	}//end detectOverheadOnderschatting()

	/**
	 * Detect bevoordeling-risk (Phase 3 REQ-WMO-012): gehanteerdTarief < benchmark median × discount.
	 *
	 * @param float $appliedRate Actual price charged per unit (EUR).
	 * @param float $costPricePerUnit IKP per unit (EUR) — must also be <= gehanteerdTarief.
	 * @param array<int,array<string,mixed>> $benchmarks MarketBenchmark records within last 12 months.
	 * @param float $discountThreshold Discount threshold (default 0.85 = 15% below market).
	 *
	 * @return bool True when the price is suspiciously below market.
	 */
	public function detectBevoordelingRisk(
		float $appliedRate,
		float $costPricePerUnit,
		array $benchmarks,
		float $discountThreshold = self::BEVOORDELING_DISCOUNT_THRESHOLD,
	): bool {
		if ($appliedRate < $costPricePerUnit) {
			// Caller already raises a non-compliant alert for this.
			return false;
		}

		$values = [];
		foreach ($benchmarks as $bench) {
			if (is_array($bench) === false) {
				continue;
			}

			$amount = (float)($bench['amount'] ?? 0);
			if ($amount > 0.0) {
				$values[] = $amount;
			}
		}

		if ($values === []) {
			return false;
		}

		sort($values);
		$count = count($values);
		if (($count % 2) === 1) {
			$median = $values[(int)($count / 2)];
		} else {
			$median = (($values[($count / 2) - 1] + $values[$count / 2]) / 2);
		}

		return $appliedRate < ($median * $discountThreshold);
	}//end detectBevoordelingRisk()

	/**
	 * Compose an AlertLog record (REQ-WMO-007).
	 *
	 * @param string $alertType One of the 7 alert-type enums.
	 * @param string $commercialActivityId FK to the affected activity.
	 * @param string $severity One of LOW|MEDIUM|HIGH.
	 * @param string $administrationId FK to the administration.
	 * @param array<string,mixed> $detectionContext Detector-supplied context payload.
	 * @param string $assignedTo Initial assignee (default concerncontroller).
	 *
	 * @return array<string,mixed> AlertLog record matching the schema.
	 */
	public function composeAlert(
		string $alertType,
		string $commercialActivityId,
		string $severity,
		string $administrationId,
		array $detectionContext = [],
		string $assignedTo = self::DEFAULT_ASSIGNEE,
	): array {
		return [
			'alertType' => $alertType,
			'commercialActivityId' => $commercialActivityId,
			'severity' => $severity,
			'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM),
			'assignedTo' => $assignedTo,
			'status' => 'open',
			'escalatedAt' => null,
			'resolutionNotes' => null,
			'detectionContext' => $detectionContext,
			'administrationId' => $administrationId,
		];

	}//end composeAlert()

	/**
	 * Determine whether an open alert should be escalated to gemeentesecretaris (REQ-WMO-007 §escalation).
	 *
	 * @param array<string,mixed> $alert The current AlertLog record.
	 * @param string $today Today's ISO date.
	 *
	 * @return bool True when the alert is open > ESCALATION_WEEKS weeks.
	 */
	public function shouldEscalate(array $alert, string $today): bool {
		if ((string)($alert['status'] ?? '') !== 'open') {
			return false;
		}

		$generatedAt = (string)($alert['generatedAt'] ?? '');
		if ($generatedAt === '') {
			return false;
		}

		try {
			$generated = new DateTimeImmutable($generatedAt);
			$now = new DateTimeImmutable($today);
		} catch (\Throwable) {
			return false;
		}

		$threshold = $generated->add(new DateInterval('P' . (self::ESCALATION_WEEKS * 7) . 'D'));
		return $now >= $threshold;
	}//end shouldEscalate()

	/**
	 * Project an alert into the escalation-due state with reassignment (REQ-WMO-007 §escalation).
	 *
	 * @param array<string,mixed> $alert The current AlertLog record.
	 *
	 * @return array<string,mixed> The updated AlertLog with status=escalation-due.
	 */
	public function escalate(array $alert): array {
		$alert['status'] = 'escalation-due';
		$alert['assignedTo'] = self::ESCALATION_ASSIGNEE;
		$alert['escalatedAt'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeImmutable::ATOM);

		return $alert;
	}//end escalate()

	/**
	 * Resolve an open alert (REQ-WMO-007 §resolution).
	 *
	 * @param array<string,mixed> $alert The current AlertLog record.
	 * @param string $resolution One of `reviewed-no-action` / `remediated`.
	 * @param string $notes Operator's motivation.
	 *
	 * @return array<string,mixed> Updated AlertLog with resolution status + notes.
	 *
	 * @throws InvalidArgumentException When the resolution status is invalid.
	 */
	public function resolve(array $alert, string $resolution, string $notes): array {
		if (in_array($resolution, ['reviewed-no-action', 'remediated'], true) === false) {
			throw new InvalidArgumentException('Invalid resolution status: ' . $resolution);
		}

		if (trim($notes) === '') {
			throw new InvalidArgumentException('Resolution requires non-empty motivation notes');
		}

		$alert['status'] = $resolution;
		$alert['resolutionNotes'] = $notes;

		return $alert;
	}//end resolve()
}//end class
