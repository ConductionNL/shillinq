<?php

/**
 * Budget Projection Service
 *
 * The thin orchestrator for the `budget-projection-engine` change
 * (REQ-BPE-010): the integration point `budget-grid-view`/`budget-charts`
 * call into. {@see BudgetProjectionReader} does every OpenRegister read;
 * {@see BudgetProjectionCalculator} does every growth-rate, extrapolation,
 * seam and roll-up computation. This class does neither — it resolves a
 * request into reader/calculator calls and shapes the response, mirroring
 * the {@see BbvProgrammeBudgetService}/{@see BbvProgrammeBudgetReader}/
 * {@see BbvProgrammeBudgetCalculator} split exactly.
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
 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;

/**
 * Orchestrates {@see BudgetProjectionReader} + {@see BudgetProjectionCalculator}
 * into per-account and per-`LedgerGroup` trend/cumulative series (REQ-BPE-010).
 *
 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-010
 */
class BudgetProjectionService {
	/**
	 * Construct the service.
	 *
	 * @param BudgetProjectionReader $reader Every OpenRegister read the engine needs.
	 * @param BudgetProjectionCalculator $calculator The REQ-BPE-001..008 arithmetic.
	 */
	public function __construct(
		private readonly BudgetProjectionReader $reader,
		private readonly BudgetProjectionCalculator $calculator = new BudgetProjectionCalculator(),
	) {

	}//end __construct()

	/**
	 * Project one account's trend and cumulative series over the requested
	 * months (REQ-BPE-001, REQ-BPE-006, REQ-BPE-008).
	 *
	 * @param string $administrationId The administration to scope the read to.
	 * @param string $accountNumber The account to project.
	 * @param list<string> $months The `YYYY-MM` months to resolve, in CHRONOLOGICAL ORDER (any mix of past/future —
	 *        the seam, REQ-BPE-006, decides each one independently) — the cumulative series is a running sum built
	 *        in the order given.
	 *
	 * @return array{
	 *     accountNumber: string,
	 *     accountType: string,
	 *     metric: string,
	 *     rate: ?float,
	 *     validSteps: int,
	 *     trend: array<string,array<string,mixed>>,
	 *     cumulative: array<string,int>,
	 * } The account's projection envelope.
	 *
	 * @throws InvalidArgumentException When the account is not found in the loaded context.
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-010
	 */
	public function projectAccount(string $administrationId, string $accountNumber, array $months): array {
		$context = $this->reader->loadContext(administrationId: $administrationId, includeLedgerGroups: false);

		return $this->projectAccountFromContext(accountNumber: $accountNumber, months: $months, context: $context);

	}//end projectAccount()

	/**
	 * Project one `LedgerGroup`'s trend and cumulative series as the sum of
	 * its resolved members' own projections (REQ-BPE-007).
	 *
	 * @param string $administrationId The administration to scope the read to.
	 * @param string $ledgerGroupKey The group's id or slug.
	 * @param list<string> $months The `YYYY-MM` months to resolve, in CHRONOLOGICAL ORDER (see {@see projectAccount()}).
	 *
	 * @return array{
	 *     ledgerGroupKey: string,
	 *     memberAccountNumbers: list<string>,
	 *     trend: array<string,array<string,mixed>>,
	 *     cumulative: array<string,int>,
	 * } The group's projection envelope.
	 *
	 * @throws InvalidArgumentException When the group is not found in the loaded context.
	 *
	 * @spec openspec/changes/budget-projection-engine/specs/budget-projection-engine/spec.md#req-bpe-007
	 */
	public function projectGroup(string $administrationId, string $ledgerGroupKey, array $months): array {
		$context = $this->reader->loadContext(administrationId: $administrationId, includeLedgerGroups: true);

		$index = ($context['ledgerGroupKeyToIndex'][$ledgerGroupKey] ?? null);
		if ($index === null) {
			throw new InvalidArgumentException('BudgetProjectionService: unknown LedgerGroup "' . $ledgerGroupKey . '".');
		}

		$entry = $context['ledgerGroupEntries'][$index];
		$memberAccountNumbers = $entry['memberAccountNumbers'];

		$memberEnvelopes = [];
		foreach ($memberAccountNumbers as $memberAccountNumber) {
			if (isset($context['accounts'][$memberAccountNumber]) === false) {
				continue;
			}

			$memberEnvelopes[] = $this->projectAccountFromContext(
				accountNumber: $memberAccountNumber,
				months: $months,
				context: $context
			);
		}

		$trend = [];
		$accountType = ($memberEnvelopes[0]['accountType'] ?? 'expenses');
		foreach ($months as $month) {
			$membersForMonth = [];
			foreach ($memberEnvelopes as $envelope) {
				$membersForMonth[] = $envelope['trend'][$month];
			}

			$trend[$month] = $this->calculator->groupProjected(members: $membersForMonth);
		}

		$cumulativeSeries = $this->calculator->cumulative(trend: array_values($trend), accountType: $accountType);
		$cumulative = array_combine($months, $cumulativeSeries);

		return [
			'ledgerGroupKey' => $ledgerGroupKey,
			'memberAccountNumbers' => $memberAccountNumbers,
			'trend' => $trend,
			'cumulative' => $cumulative,
		];

	}//end projectGroup()

	/**
	 * Build one account's envelope from an already-loaded reader context —
	 * shared by {@see projectAccount()} and {@see projectGroup()} so a group
	 * request loads the context once and reuses it for every member,
	 * rather than each member re-triggering its own `loadContext()` call
	 * (which would reintroduce the per-account query cost REQ-BPE-009
	 * forbids).
	 *
	 * @param string $accountNumber The account to project.
	 * @param list<string> $months The `YYYY-MM` months to resolve.
	 * @param array<string,mixed> $context The {@see BudgetProjectionReader::loadContext()} bundle.
	 *
	 * @return array{accountNumber:string,accountType:string,metric:string,rate:?float,validSteps:int,trend:array<string,array<string,mixed>>,cumulative:array<string,int>}
	 *
	 * @throws InvalidArgumentException When the account is not found in the context.
	 */
	private function projectAccountFromContext(string $accountNumber, array $months, array $context): array {
		$account = ($context['accounts'][$accountNumber] ?? null);
		if ($account === null) {
			throw new InvalidArgumentException('BudgetProjectionService: unknown account "' . $accountNumber . '".');
		}

		$accountType = (string)$account['accountType'];
		$metric = $this->calculator->projectionMetric(accountType: $accountType);

		$window = ($context['windowByAccount'][$accountNumber] ?? ['months' => [], 'values' => []]);
		$lastActualMonth = ($context['lastActualMonthByAccount'][$accountNumber] ?? null);

		// `metricSeries()` always returns a list the same length as its
		// input, and the reader always pairs window months 1:1 with window
		// values, so this combine cannot mismatch (PHP 8's `array_combine()`
		// throws `ValueError` rather than returning false on a real
		// mismatch, so no `false` guard is reachable here).
		$series = $this->calculator->metricSeries(orderedNetMovementCents: $window['values'], metric: $metric);
		$actualMetricByMonth = array_combine($window['months'], $series);

		$growth = null;
		if (count($series) > 0) {
			$growth = $this->calculator->growthRate(values: $series);
		}

		$baseValue = ($series[count($series) - 1] ?? null);

		$trend = [];
		foreach ($months as $month) {
			$hasActual = array_key_exists($month, $actualMetricByMonth);
			$kind = $this->calculator->seam(hasActual: $hasActual, month: $month, lastActualMonth: $lastActualMonth);

			if ($kind === 'actual') {
				$trend[$month] = ['kind' => 'actual', 'amount' => $actualMetricByMonth[$month]];
				continue;
			}

			if ($kind === 'unprojectable') {
				$trend[$month] = ['kind' => 'unprojectable', 'reason' => 'no-history', 'validSteps' => 0];
				continue;
			}

			// $kind === 'projected'.
			if ($growth === null || isset($growth['reason']) === true || $baseValue === null) {
				$trend[$month] = [
					'kind' => 'unprojectable',
					'reason' => ($growth['reason'] ?? 'no-history'),
					'validSteps' => (int)($growth['validSteps'] ?? 0),
				];
				continue;
			}

			$k = $this->calculator->monthOffset(fromMonth: (string)$lastActualMonth, toMonth: $month);
			if ($k < 1 || $k > BudgetProjectionCalculator::PROJECTION_HORIZON_MONTHS) {
				$trend[$month] = ['kind' => 'unprojectable', 'reason' => 'no-history', 'validSteps' => $growth['validSteps']];
				continue;
			}

			$trend[$month] = [
				'kind' => 'projected',
				'amount' => $this->calculator->extrapolate(v0: (int)$baseValue, rate: $growth['rate'], k: $k),
				'rate' => $growth['rate'],
				'validSteps' => $growth['validSteps'],
			];
		}//end foreach

		// `cumulative()` always returns one value per trend entry, and
		// `$trend` has exactly one entry per element of `$months`, so this
		// combine cannot mismatch either.
		$cumulativeSeries = $this->calculator->cumulative(trend: array_values($trend), accountType: $accountType);
		$cumulative = array_combine($months, $cumulativeSeries);

		return [
			'accountNumber' => $accountNumber,
			'accountType' => $accountType,
			'metric' => $metric,
			'rate' => ($growth['rate'] ?? null),
			'validSteps' => (int)($growth['validSteps'] ?? 0),
			'trend' => $trend,
			'cumulative' => $cumulative,
		];

	}//end projectAccountFromContext()
}//end class
