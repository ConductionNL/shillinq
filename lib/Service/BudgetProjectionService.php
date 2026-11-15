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
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-010
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
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-010
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
	 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-010
	 */
	public function projectAccount(string $administrationId, string $accountNumber, array $months): array {
		$context = $this->reader->loadContext(administrationId: $administrationId, includeLedgerGroups: false);

		return $this->projectAccountFromContext(run: new BudgetProjectionRun(months: $months, context: $context), accountNumber: $accountNumber);

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
	 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-007
	 */
	public function projectGroup(string $administrationId, string $ledgerGroupKey, array $months): array {
		$context = $this->reader->loadContext(administrationId: $administrationId, includeLedgerGroups: true);

		$index = ($context['ledgerGroupKeyToIndex'][$ledgerGroupKey] ?? null);
		if ($index === null) {
			throw new InvalidArgumentException('BudgetProjectionService: unknown LedgerGroup "' . $ledgerGroupKey . '".');
		}

		$run = new BudgetProjectionRun(months: $months, context: $context);
		$memberAccountNumbers = $context['ledgerGroupEntries'][$index]['memberAccountNumbers'];
		$memberEnvelopes = $this->memberEnvelopes(run: $run, memberAccountNumbers: $memberAccountNumbers);
		$trend = $this->groupTrend(run: $run, memberEnvelopes: $memberEnvelopes);
		$accountType = ($memberEnvelopes[0]['accountType'] ?? 'expenses');

		return [
			'ledgerGroupKey' => $ledgerGroupKey,
			'memberAccountNumbers' => $memberAccountNumbers,
			'trend' => $trend,
			'cumulative' => array_combine($months, $this->calculator->cumulative(trend: array_values($trend), accountType: $accountType)),
		];

	}//end projectGroup()

	/**
	 * The envelope of every member account present in the context, in
	 * member order, from the one loaded context.
	 *
	 * @param BudgetProjectionRun $run                  The request.
	 * @param list<string>        $memberAccountNumbers The group's member accounts.
	 *
	 * @return list<array<string,mixed>>
	 */
	private function memberEnvelopes(BudgetProjectionRun $run, array $memberAccountNumbers): array {
		$memberEnvelopes = [];
		foreach ($memberAccountNumbers as $memberAccountNumber) {
			if (isset($run->context['accounts'][$memberAccountNumber]) === true) {
				$memberEnvelopes[] = $this->projectAccountFromContext(run: $run, accountNumber: $memberAccountNumber);
			}
		}

		return $memberEnvelopes;

	}//end memberEnvelopes()

	/**
	 * The group's projected value per month, rolled up from its members'.
	 *
	 * @param BudgetProjectionRun       $run             The request.
	 * @param list<array<string,mixed>> $memberEnvelopes The members' envelopes.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function groupTrend(BudgetProjectionRun $run, array $memberEnvelopes): array {
		$trend = [];
		foreach ($run->months as $month) {
			$trend[$month] = $this->calculator->groupProjected(
				members: array_map(static fn (array $envelope): array => $envelope['trend'][$month], $memberEnvelopes)
			);
		}

		return $trend;

	}//end groupTrend()

	/**
	 * Build one account's envelope from an already-loaded reader context —
	 * shared by {@see projectAccount()} and {@see projectGroup()} so a group
	 * request loads the context once and reuses it for every member,
	 * rather than each member re-triggering its own `loadContext()` call
	 * (which would reintroduce the per-account query cost REQ-BPE-009
	 * forbids).
	 *
	 * @param BudgetProjectionRun $run           The request: months and loaded context.
	 * @param string              $accountNumber The account to project.
	 *
	 * @return array{accountNumber:string,accountType:string,metric:string,rate:?float,validSteps:int,trend:array<string,array<string,mixed>>,cumulative:array<string,int>}
	 *
	 * @throws InvalidArgumentException When the account is not found in the context.
	 */
	private function projectAccountFromContext(BudgetProjectionRun $run, string $accountNumber): array {
		$basis = $this->projectionBasis(context: $run->context, accountNumber: $accountNumber);
		$trend = [];
		foreach ($run->months as $month) {
			$trend[$month] = $this->monthTrend(basis: $basis, month: $month);
		}

		// `cumulative()` always returns one value per trend entry, and
		// `$trend` has exactly one entry per element of `$months`, so this
		// combine cannot mismatch either.
		$cumulativeSeries = $this->calculator->cumulative(trend: array_values($trend), accountType: $basis->accountType);

		return [
			'accountNumber' => $accountNumber,
			'accountType' => $basis->accountType,
			'metric' => $basis->metric,
			'rate' => ($basis->growth['rate'] ?? null),
			'validSteps' => (int)($basis->growth['validSteps'] ?? 0),
			'trend' => $trend,
			'cumulative' => array_combine($run->months, $cumulativeSeries),
		];

	}//end projectAccountFromContext()

	/**
	 * What the account's projection is computed from: its metric series over
	 * the actuals window, its growth rate and its last value.
	 *
	 * @param array<string,mixed> $context       The loaded reader context.
	 * @param string              $accountNumber The account to project.
	 *
	 * @return AccountProjectionBasis
	 *
	 * @throws InvalidArgumentException When the account is not found in the context.
	 */
	private function projectionBasis(array $context, string $accountNumber): AccountProjectionBasis {
		$accountType = $this->accountTypeOf(context: $context, accountNumber: $accountNumber);
		$metric = $this->calculator->projectionMetric(accountType: $accountType);
		$window = ($context['windowByAccount'][$accountNumber] ?? ['months' => [], 'values' => []]);
		$lastActualMonth = ($context['lastActualMonthByAccount'][$accountNumber] ?? null);

		// `metricSeries()` always returns a list the same length as its
		// input, and the reader always pairs window months 1:1 with window
		// values, so this combine cannot mismatch (PHP 8's `array_combine()`
		// throws `ValueError` rather than returning false on a real
		// mismatch, so no `false` guard is reachable here).
		$series = $this->calculator->metricSeries(orderedNetMovementCents: $window['values'], metric: $metric);

		return new AccountProjectionBasis(
			accountNumber: $accountNumber,
			accountType: $accountType,
			metric: $metric,
			actualMetricByMonth: array_combine($window['months'], $series),
			lastActualMonth: $lastActualMonth,
			growth: $this->growthOf(series: $series),
			baseValue: ($series[count($series) - 1] ?? null),
		);

	}//end projectionBasis()

	/**
	 * The account's type from the loaded context.
	 *
	 * @param array<string,mixed> $context       The loaded reader context.
	 * @param string              $accountNumber The account.
	 *
	 * @return string
	 *
	 * @throws InvalidArgumentException When the account is not found in the context.
	 */
	private function accountTypeOf(array $context, string $accountNumber): string {
		$account = ($context['accounts'][$accountNumber] ?? null);
		if ($account === null) {
			throw new InvalidArgumentException('BudgetProjectionService: unknown account "' . $accountNumber . '".');
		}

		return (string)$account['accountType'];

	}//end accountTypeOf()

	/**
	 * The calculator's growth rate over a metric series, or null for an empty one.
	 *
	 * @param list<int|float> $series The metric series.
	 *
	 * @return array<string,mixed>|null
	 */
	private function growthOf(array $series): array|null {
		if (count($series) > 0) {
			return $this->calculator->growthRate(values: $series);
		}

		return null;

	}//end growthOf()

	/**
	 * One month of the account's trend: its actual, an unprojectable month
	 * before the seam, or a projection.
	 *
	 * @param AccountProjectionBasis $basis The account's basis.
	 * @param string                 $month The `YYYY-MM` month.
	 *
	 * @return array<string,mixed>
	 */
	private function monthTrend(AccountProjectionBasis $basis, string $month): array {
		$hasActual = array_key_exists($month, $basis->actualMetricByMonth);
		$kind = $this->calculator->seam(hasActual: $hasActual, month: $month, lastActualMonth: $basis->lastActualMonth);

		if ($kind === 'actual') {
			return ['kind' => 'actual', 'amount' => $basis->actualMetricByMonth[$month]];
		}

		if ($kind === 'unprojectable') {
			return ['kind' => 'unprojectable', 'reason' => 'no-history', 'validSteps' => 0];
		}

		return $this->projectedMonth(basis: $basis, month: $month);

	}//end monthTrend()

	/**
	 * A month after the seam: extrapolated within the horizon from a usable
	 * growth rate, else unprojectable with the reason.
	 *
	 * @param AccountProjectionBasis $basis The account's basis.
	 * @param string                 $month The `YYYY-MM` month.
	 *
	 * @return array<string,mixed>
	 */
	private function projectedMonth(AccountProjectionBasis $basis, string $month): array {
		$unusable = $this->unusableGrowth(basis: $basis);
		if ($unusable !== null) {
			return $unusable;
		}

		$growth = $basis->growth;
		$k = $this->calculator->monthOffset(fromMonth: (string)$basis->lastActualMonth, toMonth: $month);
		if ($k < 1 || $k > BudgetProjectionCalculator::PROJECTION_HORIZON_MONTHS) {
			return ['kind' => 'unprojectable', 'reason' => 'no-history', 'validSteps' => $growth['validSteps']];
		}

		return [
			'kind' => 'projected',
			'amount' => $this->calculator->extrapolate(v0: (int)$basis->baseValue, rate: $growth['rate'], k: $k),
			'rate' => $growth['rate'],
			'validSteps' => $growth['validSteps'],
		];

	}//end projectedMonth()

	/**
	 * The unprojectable entry when there is no usable growth rate or base
	 * value, with the calculator's reason; null when the account can be
	 * extrapolated.
	 *
	 * @param AccountProjectionBasis $basis The account's basis.
	 *
	 * @return array<string,mixed>|null
	 */
	private function unusableGrowth(AccountProjectionBasis $basis): array|null {
		$growth = $basis->growth;
		if ($growth !== null && isset($growth['reason']) === false && $basis->baseValue !== null) {
			return null;
		}

		return [
			'kind' => 'unprojectable',
			'reason' => ($growth['reason'] ?? 'no-history'),
			'validSteps' => (int)($growth['validSteps'] ?? 0),
		];

	}//end unusableGrowth()
}//end class
