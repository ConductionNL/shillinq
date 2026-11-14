<?php

/**
 * Cross Subsidy Scan
 *
 * The daily cross-subsidy check over every active commercial activity
 * (public-sector-task-field-allocation, REQ-WMO-014). Per activity the
 * `CrossSubsidyDetector` checks run over its stored integral cost prices, cost
 * allocations, exemption decision and market benchmarks, and each signal that
 * fires writes one `AlertLog` through `composeAlert` — unless an alert of that
 * type is still open for the activity, so a second run repeats nothing.
 *
 * The turnover-spike check is not run: it compares this year's turnover with
 * last year's, and no stored record carries both.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PublicSector
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PublicSector;

use DateTimeImmutable;
use OCA\Shillinq\Kernel\Contracts\CrossSubsidyScanInterface;
use OCA\Shillinq\Service\CrossSubsidyDetector;

/**
 * Runs the cross-subsidy checks and logs one alert per new signal.
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
 */
class CrossSubsidyScan implements CrossSubsidyScanInterface {

	/**
	 * The alert states that still count as open: a new alert of the type waits.
	 */
	private const UNRESOLVED_STATES = ['open', 'escalation-due', 'escalated'];

	/**
	 * Constructor.
	 *
	 * @param PublicSectorRecords  $records  Activities, cost prices, allocations, decisions, benchmarks and alerts.
	 * @param CrossSubsidyDetector $detector The cross-subsidy checks.
	 */
	public function __construct(
		private readonly PublicSectorRecords $records,
		private readonly CrossSubsidyDetector $detector,
	) {
	}//end __construct()

	/**
	 * Check every active commercial activity and log the new signals.
	 *
	 * @param string $today Today's date (`Y-m-d`).
	 *
	 * @return array{activities: int, alerts: int, alreadyOpen: int}
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function run(string $today): array {
		$counts = ['activities' => 0, 'alerts' => 0, 'alreadyOpen' => 0];
		foreach ($this->records->records(schema: 'CommercialActivity', filters: ['state' => 'active']) as $activity) {
			$code = self::activityKey(activity: $activity);
			if (($activity['state'] ?? '') !== 'active' || $code === '') {
				continue;
			}

			$counts['activities']++;
			$open = $this->openAlertTypes(activityKey: $code);
			foreach ($this->signals(activity: $activity, activityKey: $code, today: $today) as $type => $signal) {
				if (isset($open[$type]) === true) {
					$counts['alreadyOpen']++;
					continue;
				}

				$alert = $this->detector->composeAlert(
					alertType: $type,
					commercialActivityId: $code,
					severity: $signal['severity'],
					administrationId: (string)($activity['administrationId'] ?? ''),
					detectionContext: $signal['context']
				);
				$this->records->create(schema: 'AlertLog', object: array_filter($alert, static fn (mixed $value): bool => $value !== null));
				$counts['alerts']++;
			}
		}//end foreach

		return $counts;

	}//end run()

	/**
	 * The signals that fire for one activity, by alert type.
	 *
	 * @param array<string,mixed> $activity    The activity.
	 * @param string              $activityKey The key its records name it by.
	 * @param string              $today       Today's date.
	 *
	 * @return array<string, array{severity: string, context: array<string,mixed>}>
	 */
	private function signals(array $activity, string $activityKey, string $today): array {
		$signals = [];
		$prices = $this->costPrices(activityKey: $activityKey);
		$latest = ($prices[0] ?? null);

		if ($this->detector->detectLossFinancing(ikpHistory: $prices) === true) {
			$signals['loss-financing'] = ['severity' => 'HIGH', 'context' => ['periods' => array_column($prices, 'period')]];
		}

		if ($latest !== null && $this->detector->detectOverheadUnderAllocation(ikp: $latest) === true) {
			$signals['overhead-under-allocation'] = [
				'severity' => 'MEDIUM',
				'context'  => ['period' => (string)($latest['period'] ?? ''), 'totalCost' => (float)($latest['totalCost'] ?? 0)],
			];
		}

		$prior = ($prices[1] ?? null);
		if ($latest !== null && $prior !== null
			&& $this->detector->detectOverheadOnderschatting(
				currentDirectCosts: self::directCosts(price: $latest),
				priorDirectCosts: self::directCosts(price: $prior),
				currentOverhead: self::overhead(price: $latest),
				priorOverhead: self::overhead(price: $prior)
			) === true
		) {
			$signals['potentiele-overhead-onderschatting'] = [
				'severity' => 'MEDIUM',
				'context'  => ['period' => (string)($latest['period'] ?? ''), 'priorPeriod' => (string)($prior['period'] ?? '')],
			];
		}

		if (($activity['isExempted'] ?? false) === true) {
			$decision = ($this->records->find(schema: 'AlgemeenBelangBesluit', id: (string)($activity['exemptionDecisionId'] ?? '')) ?? []);
			if ($this->detector->detectAbbStale(activity: $activity, abb: $decision, today: $today) === true) {
				$signals['abb-stale'] = ['severity' => 'MEDIUM', 'context' => ['nextEvaluation' => (string)($decision['nextEvaluation'] ?? '')]];
			}
		}

		$allocations = $this->records->records(schema: 'ActivityCostAllocation', filters: ['commercialActivityId' => $activityKey]);
		$allocations = array_values(
			array_filter($allocations, static fn (array $row): bool => (string)($row['commercialActivityId'] ?? '') === $activityKey)
		);
		$manual = count(array_filter($allocations, static fn (array $row): bool => ($row['automaticApplied'] ?? true) === false));
		if ($this->detector->detectManualOverrideAccumulation(manualOverrideCount: $manual, totalAllocations: count($allocations)) === true) {
			$signals['manual-override-accumulation'] = ['severity' => 'LOW', 'context' => ['manual' => $manual, 'total' => count($allocations)]];
		}

		if ($latest !== null && is_numeric($latest['appliedRate'] ?? null) === true && is_numeric($latest['costPricePerUnit'] ?? null) === true) {
			$benchmarks = $this->recentBenchmarks(activityKey: $activityKey, today: $today);
			$rate = (float)$latest['appliedRate'];
			$costPrice = (float)$latest['costPricePerUnit'];
			if ($this->detector->detectBevoordelingRisk(appliedRate: $rate, costPricePerUnit: $costPrice, benchmarks: $benchmarks) === true) {
				$signals['bevoordeling-risk'] = [
					'severity' => 'HIGH',
					'context'  => ['period' => (string)($latest['period'] ?? ''), 'appliedRate' => $rate, 'benchmarks' => count($benchmarks)],
				];
			}
		}

		return $signals;

	}//end signals()

	/**
	 * The activity's cost prices, most recent period first.
	 *
	 * @param string $activityKey The key the cost prices name the activity by.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function costPrices(string $activityKey): array {
		$prices = array_values(
			array_filter(
				$this->records->records(schema: 'IntegralCostPrice', filters: ['commercialActivityId' => $activityKey]),
				static fn (array $row): bool => (string)($row['commercialActivityId'] ?? '') === $activityKey
			)
		);
		usort($prices, static fn (array $a, array $b): int => strcmp((string)($b['period'] ?? ''), (string)($a['period'] ?? '')));
		return $prices;

	}//end costPrices()

	/**
	 * The activity's market benchmarks of the last twelve months.
	 *
	 * @param string $activityKey The key the benchmarks name the activity by.
	 * @param string $today       Today's date.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function recentBenchmarks(string $activityKey, string $today): array {
		$from = (new DateTimeImmutable($today))->modify('-12 months')->format('Y-m-d');
		$recent = [];
		foreach ($this->records->records(schema: 'MarketBenchmark', filters: ['commercialActivityId' => $activityKey]) as $row) {
			$day = substr((string)($row['referenceDate'] ?? ''), 0, 10);
			if ((string)($row['commercialActivityId'] ?? '') === $activityKey && $day >= $from && $day <= $today) {
				$recent[] = $row;
			}
		}

		return $recent;

	}//end recentBenchmarks()

	/**
	 * The alert types still open for the activity.
	 *
	 * @param string $activityKey The key the alerts name the activity by.
	 *
	 * @return array<string, true>
	 */
	private function openAlertTypes(string $activityKey): array {
		$open = [];
		foreach ($this->records->records(schema: 'AlertLog', filters: ['commercialActivityId' => $activityKey]) as $alert) {
			if ((string)($alert['commercialActivityId'] ?? '') === $activityKey
				&& in_array((string)($alert['status'] ?? 'open'), self::UNRESOLVED_STATES, true) === true
			) {
				$open[(string)($alert['alertType'] ?? '')] = true;
			}
		}

		return $open;

	}//end openAlertTypes()

	/**
	 * The key an activity's records name it by: its code, else its id.
	 *
	 * @param array<string,mixed> $activity The activity.
	 *
	 * @return string
	 */
	private static function activityKey(array $activity): string {
		$code = trim((string)($activity['code'] ?? ''));
		if ($code !== '') {
			return $code;
		}

		return (string)($activity['id'] ?? '');

	}//end activityKey()

	/**
	 * The direct costs of a cost price, in euros.
	 *
	 * @param array<string,mixed> $price The cost price.
	 *
	 * @return float
	 */
	private static function directCosts(array $price): float {
		$components = (array)($price['componenten'] ?? []);
		$direct = 0.0;
		foreach (['directPayrollCost', 'directMaterials', 'directDepreciations'] as $component) {
			$direct += (float)($components[$component] ?? 0);
		}

		return $direct;

	}//end directCosts()

	/**
	 * The overhead of a cost price, in euros.
	 *
	 * @param array<string,mixed> $price The cost price.
	 *
	 * @return float
	 */
	private static function overhead(array $price): float {
		$components = (array)($price['componenten'] ?? []);
		return (float)array_sum(array_map('floatval', (array)($components['indirecteOverhead'] ?? [])));

	}//end overhead()
}//end class
