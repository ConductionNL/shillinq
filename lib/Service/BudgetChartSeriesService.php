<?php

/**
 * Budget Chart Series Service
 *
 * The composition layer behind `GET /api/budget-charts/series`
 * (REQ-BCH-003, REQ-BCH-008): shapes the actual / projected / begroot
 * trend+cumulative envelope both `BudgetTrendChart` placements consume, for
 * every in-scope `Account` and `LedgerGroup` in one administration. It
 * performs NO GL join and NO growth-rate arithmetic of its own — both stay
 * exactly where the three sibling changes already put them.
 *
 * ## Why this composes {@see BudgetProjectionReader}/{@see BudgetProjectionCalculator}
 * directly, not {@see BudgetProjectionService}
 *
 * `design.md` §7 names `BudgetProjectionService` as the collaborator, but
 * its public `projectAccount()`/`projectGroup()` methods each call
 * `BudgetProjectionReader::loadContext()` AFRESH on every invocation
 * (verified by reading `BudgetProjectionService.php`: neither public method
 * accepts an already-loaded context). REQ-BCH-008's second scenario
 * requires the WHOLE endpoint to cost at most one `BudgetProjectionReader`
 * load (≤4 `findAll()`) and one `BudgetVsActualsReader` load (≤5
 * `findAll()`) — flat, independent of how many accounts/`LedgerGroup`s the
 * response covers (`design.md` §8a, restated in REQ-BCH-008). Calling
 * `projectAccount()` once per account in the administration would multiply
 * that cost by the account count — exactly the "16-18 queries/page,
 * surfacing only as e2e timeouts" failure mode this codebase has already
 * hit once on a sibling feature (facet composition, unrelated app). This
 * class therefore injects the projection engine's `Reader` (called ONCE)
 * and `Calculator` (pure, stateless) directly, and reimplements ONLY the
 * per-month glue loop `BudgetProjectionService::projectAccountFromContext()`
 * already uses internally (a private method, unreachable from here) —
 * every actual number in that loop still comes from
 * `BudgetProjectionCalculator`'s own public methods
 * (`metricSeries()`/`growthRate()`/`seam()`/`extrapolate()`/`cumulative()`),
 * so no growth-rate arithmetic is re-derived, only the orchestration shape
 * already public on the sibling service is mirrored to make single-load
 * reuse possible. This is a deliberate, documented deviation from the
 * literal collaborator NAME `design.md`/`tasks.md` state, in favour of the
 * literal, tested REQUIREMENT (REQ-BCH-008) they both also state.
 *
 * ## Actual sourcing (REQ-BCH-003)
 *
 * The displayed "actual" amount for an `actual`-kind month comes from
 * {@see BudgetVsActualsReader}'s own `actualsByAccountMonth` bucket (never
 * from the projection engine's parallel bucket), reduced through
 * {@see BudgetProjectionCalculator::metricSeries()} so a stock account's
 * actual series is a running closing balance rather than a raw monthly net
 * movement, exactly as REQ-BPE-001 defines for the projected side — reused,
 * not re-derived, since `metricSeries()` is a public pure method taking any
 * ordered net-movement series, not only the projection reader's own window.
 * The actual/projected SEAM decision itself (`design.md` §5, REQ-BPE-006)
 * is resolved from the projection engine's own `lastActualMonthByAccount` +
 * window, so a projected-or-unprojectable classification always matches
 * what `BudgetProjectionService` would independently report for the same
 * account and month.
 *
 * ## Budgeted resolution for an individual `Account` (not specced by any sibling)
 *
 * `BudgetLine` is keyed by `ledgerGroupId`, never `accountNumber` — there is
 * no per-account budget figure in the schema. For `ChartOfAccountsDetail`'s
 * `scope: "account"` chart, this class resolves the account's budgeted
 * series from the FIRST `LedgerGroup` whose own resolved
 * `memberAccountNumbers` (direct membership, not a recursive
 * parent/children walk) includes this account — the verzamelpost an
 * operator would recognise as "where this account's plan lives." An
 * account belonging to no `LedgerGroup` has no plan to show: its budgeted
 * series is `[]` (an honest, real absence — never a fabricated figure,
 * mirroring REQ-BCH-004's "never a fabricated" principle extended from the
 * projection series to this one).
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
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use InvalidArgumentException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Composes {@see BudgetVsActualsReader}/{@see BudgetVsActualsCalculator} and
 * {@see BudgetProjectionReader}/{@see BudgetProjectionCalculator} into the
 * trend+cumulative actual/projected/begroot envelope (REQ-BCH-003,
 * REQ-BCH-008).
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
 */
class BudgetChartSeriesService {
	/**
	 * AnnualBudget schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_ANNUAL_BUDGET = 'AnnualBudget';

	/**
	 * Hard ceiling on the number of months a single request may span —
	 * a sanity bound, not a product decision; `design.md` never states a
	 * maximum range, but an unbounded one would also unboundedly grow the
	 * per-fiscal-year `AnnualBudget` resolution query count.
	 *
	 * @var integer
	 */
	private const MAX_MONTHS = 36;

	/**
	 * Construct the service.
	 *
	 * @param BudgetVsActualsReader $vsActualsReader Actual + budgeted GL/BudgetLine reads (budget-core-schema).
	 * @param BudgetVsActualsCalculator $vsActualsCalculator Actual/budgeted roll-up arithmetic (budget-core-schema).
	 * @param BudgetProjectionReader $projectionReader Projection-engine GL reads (budget-projection-engine).
	 * @param BudgetProjectionCalculator $projectionCalculator Growth-rate/seam/cumulative arithmetic (budget-projection-engine).
	 * @param IAppConfig $appConfig App config (OpenRegister register slug).
	 * @param LoggerInterface $logger Logger — never receives a record body.
	 * @param ObjectServiceInterface $objectService OpenRegister object service, used ONLY to resolve the in-force `AnnualBudget`(s).
	 */
	public function __construct(
		private readonly BudgetVsActualsReader $vsActualsReader,
		private readonly BudgetVsActualsCalculator $vsActualsCalculator,
		private readonly BudgetProjectionReader $projectionReader,
		private readonly BudgetProjectionCalculator $projectionCalculator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Resolve the trend+cumulative actual/projected/begroot envelope for
	 * every in-scope `Account` and `LedgerGroup` in one administration
	 * (REQ-BCH-003, REQ-BCH-008).
	 *
	 * @param string $administrationId The administration to scope every read to.
	 * @param string $from The first `YYYY-MM` month, inclusive.
	 * @param string $to The last `YYYY-MM` month, inclusive.
	 * @param string|null $annualBudgetId Explicit `AnnualBudget` override; null resolves each fiscal year's `isDefault` budget.
	 *
	 * @return array{
	 *     months: list<string>,
	 *     accounts: list<array<string,mixed>>,
	 *     ledgerGroups: list<array<string,mixed>>,
	 * } The response envelope `BudgetChartsController::series()` returns verbatim.
	 *
	 * @throws InvalidArgumentException When `$from`/`$to` are malformed or `$from` is after `$to`.
	 *
	 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
	 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
	 */
	public function resolveSeries(string $administrationId, string $from, string $to, ?string $annualBudgetId = null): array {
		$months = $this->buildMonthRange(from: $from, to: $to);

		$annualBudgetIdsByYear = $this->resolveAnnualBudgetIdsByYear(
			administrationId: $administrationId,
			months: $months,
			override: $annualBudgetId
		);
		$allAnnualBudgetIds = array_values(array_unique(array_filter(array_values($annualBudgetIdsByYear))));

		// The two reader loads — EXACTLY ONCE EACH, regardless of how many
		// accounts/LedgerGroups this administration has (REQ-BCH-008).
		$vsActualsContext = $this->vsActualsReader->loadContext(
			administrationId: $administrationId,
			annualBudgetIds: $allAnnualBudgetIds,
			includeLedgerGroups: true
		);
		$projectionContext = $this->projectionReader->loadContext(administrationId: $administrationId, includeLedgerGroups: true);

		$accountNumbers = $this->inScopeAccountNumbers(vsActualsContext: $vsActualsContext);

		$accounts = [];
		$envelopesByAccount = [];
		foreach ($accountNumbers as $accountNumber) {
			$envelope = $this->buildAccountEnvelope(
				accountNumber: $accountNumber,
				months: $months,
				vsActualsContext: $vsActualsContext,
				projectionContext: $projectionContext,
				annualBudgetIdsByYear: $annualBudgetIdsByYear
			);
			$envelopesByAccount[$accountNumber] = $envelope;
			$accounts[] = $envelope;
		}

		$ledgerGroups = [];
		foreach ($vsActualsContext['ledgerGroupEntries'] as $entry) {
			$ledgerGroups[] = $this->buildLedgerGroupEnvelope(
				entry: $entry,
				months: $months,
				vsActualsContext: $vsActualsContext,
				annualBudgetIdsByYear: $annualBudgetIdsByYear,
				envelopesByAccount: $envelopesByAccount
			);
		}

		return ['months' => $months, 'accounts' => $accounts, 'ledgerGroups' => $ledgerGroups];

	}//end resolveSeries()

	/**
	 * Build the chronological `YYYY-MM` month list from `$from` to `$to`, inclusive.
	 *
	 * @param string $from The first month.
	 * @param string $to The last month.
	 *
	 * @return list<string> The chronological month list.
	 *
	 * @throws InvalidArgumentException When malformed, out of order, or exceeding {@see MAX_MONTHS}.
	 */
	private function buildMonthRange(string $from, string $to): array {
		if (preg_match('/^\d{4}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}$/', $to) !== 1) {
			throw new InvalidArgumentException('BudgetChartSeriesService: from/to must be YYYY-MM.');
		}

		if ($from > $to) {
			throw new InvalidArgumentException('BudgetChartSeriesService: from must not be after to.');
		}

		$months = [];
		$cursor = $from;
		$guard = 0;
		while ($cursor <= $to && $guard < self::MAX_MONTHS) {
			$months[] = $cursor;
			$cursor = $this->projectionCalculator->nextMonth(month: $cursor);
			$guard++;
		}

		if ($cursor <= $to) {
			throw new InvalidArgumentException('BudgetChartSeriesService: range exceeds ' . self::MAX_MONTHS . ' months.');
		}

		return $months;

	}//end buildMonthRange()

	/**
	 * Resolve the in-force `AnnualBudget` id for every distinct fiscal year
	 * spanned by `$months` (`design.md` §2a).
	 *
	 * An explicit `$override` is used AS-IS for every year in range — this
	 * class does not look the override up to learn its "real" fiscal year
	 * (that would need an id-keyed `find()`, unavailable on this app's
	 * `never id` property-filter query convention {@see BudgetVsActualsReader::query()}
	 * documents). `design.md` §2a's own override scenario is single-year;
	 * a caller requesting a multi-year range together with an override
	 * gets that one budget's `BudgetLine`s applied across every year in the
	 * range, which is the simplest reading of "reads that specific
	 * AnnualBudget's own BudgetLines instead" that needs no extra read.
	 *
	 * @param string $administrationId The administration to scope every read to.
	 * @param list<string> $months The requested `YYYY-MM` months.
	 * @param string|null $override An explicit `AnnualBudget` id, applied to every fiscal year in range.
	 *
	 * @return array<int,string|null> Fiscal year => resolved AnnualBudget id, or null when none exists.
	 */
	private function resolveAnnualBudgetIdsByYear(string $administrationId, array $months, ?string $override): array {
		$years = [];
		foreach ($months as $month) {
			$years[(int)substr($month, 0, 4)] = true;
		}

		$result = [];
		foreach (array_keys($years) as $year) {
			if ($override !== null && $override !== '') {
				$result[$year] = $override;
				continue;
			}

			$result[$year] = $this->resolveDefaultAnnualBudgetId(administrationId: $administrationId, fiscalYear: $year);
		}

		return $result;

	}//end resolveAnnualBudgetIdsByYear()

	/**
	 * Resolve the `isDefault: true` `AnnualBudget` id for one administration
	 * + fiscal year (`budget-core-schema` §2.2's one-default invariant,
	 * already enforced by `AnnualBudgetDefaultGuard`).
	 *
	 * @param string $administrationId The administration to scope the read to.
	 * @param integer $fiscalYear The fiscal year.
	 *
	 * @return string|null The AnnualBudget id, or null when no default budget exists for this year.
	 */
	private function resolveDefaultAnnualBudgetId(string $administrationId, int $fiscalYear): ?string {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema(self::SCHEMA_ANNUAL_BUDGET)
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'fiscalYear' => $fiscalYear,
							'isDefault' => true,
						],
					]
				);
		} catch (Throwable $e) {
			$this->logger->error(
				'BudgetChartSeriesService: failed to resolve default AnnualBudget',
				['administrationId' => $administrationId, 'fiscalYear' => $fiscalYear, 'exception' => $e->getMessage()]
			);
			return null;
		}

		foreach ($rows as $row) {
			$id = (string)($row['@self']['id'] ?? $row['id'] ?? '');
			if ($id !== '') {
				return $id;
			}
		}

		return null;

	}//end resolveDefaultAnnualBudgetId()

	/**
	 * The response-size scoping rule (`design.md` §8a): every account with
	 * at least one posted GL bucket entry, OR that is a resolved member of
	 * some `LedgerGroup` — dormant accounts with neither are omitted.
	 *
	 * @param array<string,mixed> $vsActualsContext The {@see BudgetVsActualsReader::loadContext()} bundle.
	 *
	 * @return list<string> The in-scope account numbers.
	 */
	private function inScopeAccountNumbers(array $vsActualsContext): array {
		$numbers = [];
		foreach (array_keys($vsActualsContext['actualsByAccountMonth']) as $accountNumber) {
			$numbers[(string)$accountNumber] = true;
		}

		foreach ($vsActualsContext['ledgerGroupEntries'] as $entry) {
			foreach (($entry['memberAccountNumbers'] ?? []) as $accountNumber) {
				$numbers[(string)$accountNumber] = true;
			}
		}

		// PHP casts a purely-numeric string array key to an int key (e.g.
		// account "1000" -> key 1000 -> int(1000)) — cast back explicitly,
		// the same gotcha BudgetVsActualsReader::resolveMembers() and
		// BudgetProjectionReader::loadContext() both already document.
		return array_values(array_map('strval', array_keys($numbers)));

	}//end inScopeAccountNumbers()

	/**
	 * Build one account's response envelope: `accountType`, a combined
	 * `trend` (actual/projected/unprojectable per month, REQ-BPE-006 seam),
	 * `cumulative` (REQ-BPE-008), and `budgeted` (own resolved
	 * `LedgerGroup`'s `BudgetLine`, per the class docblock's "no per-account
	 * schema field" note).
	 *
	 * @param string $accountNumber The account number.
	 * @param list<string> $months The requested months.
	 * @param array<string,mixed> $vsActualsContext The {@see BudgetVsActualsReader} context.
	 * @param array<string,mixed> $projectionContext The {@see BudgetProjectionReader} context.
	 * @param array<int,string|null> $annualBudgetIdsByYear Fiscal year => resolved AnnualBudget id.
	 *
	 * @return array<string,mixed> The account's envelope.
	 */
	private function buildAccountEnvelope(
		string $accountNumber,
		array $months,
		array $vsActualsContext,
		array $projectionContext,
		array $annualBudgetIdsByYear
	): array {
		$account = ($projectionContext['accounts'][$accountNumber] ?? null);
		$accountType = (string)($account['accountType'] ?? 'expenses');

		$trend = $this->accountTrend(
			accountNumber: $accountNumber,
			accountType: $accountType,
			months: $months,
			vsActualsContext: $vsActualsContext,
			projectionContext: $projectionContext
		);
		$cumulative = $this->cumulativeFromTrend(trend: $trend, accountType: $accountType);

		$owningLedgerGroupKey = $this->owningLedgerGroupKey(accountNumber: $accountNumber, vsActualsContext: $vsActualsContext);
		$budgeted = [];
		if ($owningLedgerGroupKey !== null) {
			$budgeted = $this->budgetedSeries(
				ledgerGroupKey: $owningLedgerGroupKey,
				months: $months,
				vsActualsContext: $vsActualsContext,
				annualBudgetIdsByYear: $annualBudgetIdsByYear
			);
		}

		return [
			'accountNumber' => $accountNumber,
			'accountType' => $accountType,
			'trend' => $trend,
			'cumulative' => $cumulative,
			'budgeted' => $budgeted,
			'budgetedCumulative' => $this->cumulativeFromFlatSeries(series: $budgeted, months: $months, accountType: $accountType),
		];

	}//end buildAccountEnvelope()

	/**
	 * Combined actual/projected/unprojectable trend for one account
	 * (REQ-BPE-006's seam, REQ-BCH-003's actual-sourcing rule).
	 *
	 * @param string $accountNumber The account number.
	 * @param string $accountType The account's type.
	 * @param list<string> $months The requested months.
	 * @param array<string,mixed> $vsActualsContext The {@see BudgetVsActualsReader} context.
	 * @param array<string,mixed> $projectionContext The {@see BudgetProjectionReader} context.
	 *
	 * @return array<string,array<string,mixed>> Month => typed result (`kind: actual|projected|unprojectable`).
	 */
	private function accountTrend(
		string $accountNumber,
		string $accountType,
		array $months,
		array $vsActualsContext,
		array $projectionContext
	): array {
		$metric = $this->projectionCalculator->projectionMetric(accountType: $accountType);

		// SEAM classification: the projection engine's own window + last
		// actual month, so `actual`/`projected`/`unprojectable` here always
		// matches what BudgetProjectionService would independently report
		// (REQ-BCH-003 scenario 2).
		$window = ($projectionContext['windowByAccount'][$accountNumber] ?? ['months' => [], 'values' => []]);
		$lastActualMonth = ($projectionContext['lastActualMonthByAccount'][$accountNumber] ?? null);
		$projectionSeries = $this->projectionCalculator->metricSeries(orderedNetMovementCents: $window['values'], metric: $metric);
		$actualMetricByMonth = array_combine($window['months'], $projectionSeries);

		$growth = null;
		if (count($projectionSeries) > 0) {
			$growth = $this->projectionCalculator->growthRate(values: $projectionSeries);
		}

		$baseValue = ($projectionSeries[count($projectionSeries) - 1] ?? null);

		// DISPLAYED VALUE for `actual`-kind months: BudgetVsActualsReader's
		// own bucket, reduced through the SAME public metricSeries() so a
		// stock account's actual series is a running closing balance too
		// (REQ-BCH-003: the value equals BudgetVsActualsReader's own figure).
		$actualDisplayByMonth = $this->actualDisplaySeries(
			accountNumber: $accountNumber,
			metric: $metric,
			vsActualsContext: $vsActualsContext
		);

		$trend = [];
		foreach ($months as $month) {
			$hasActual = array_key_exists($month, $actualMetricByMonth);
			$kind = $this->projectionCalculator->seam(hasActual: $hasActual, month: $month, lastActualMonth: $lastActualMonth);

			if ($kind === 'actual') {
				$trend[$month] = [
					'kind' => 'actual',
					'amount' => (int)($actualDisplayByMonth[$month] ?? $actualMetricByMonth[$month]),
				];
				continue;
			}

			if ($kind === 'unprojectable') {
				$trend[$month] = ['kind' => 'unprojectable', 'reason' => 'no-history', 'validSteps' => 0];
				continue;
			}

			if ($growth === null || isset($growth['reason']) === true || $baseValue === null) {
				$trend[$month] = [
					'kind' => 'unprojectable',
					'reason' => ($growth['reason'] ?? 'no-history'),
					'validSteps' => (int)($growth['validSteps'] ?? 0),
				];
				continue;
			}

			$k = $this->projectionCalculator->monthOffset(fromMonth: (string)$lastActualMonth, toMonth: $month);
			if ($k < 1 || $k > BudgetProjectionCalculator::PROJECTION_HORIZON_MONTHS) {
				$trend[$month] = ['kind' => 'unprojectable', 'reason' => 'no-history', 'validSteps' => $growth['validSteps']];
				continue;
			}

			$trend[$month] = [
				'kind' => 'projected',
				'amount' => $this->projectionCalculator->extrapolate(v0: (int)$baseValue, rate: $growth['rate'], k: $k),
				'rate' => $growth['rate'],
				'validSteps' => $growth['validSteps'],
			];
		}//end foreach

		return $trend;

	}//end accountTrend()

	/**
	 * Reduce {@see BudgetVsActualsReader}'s raw net-movement bucket for one
	 * account into a dense, metric-aware series (running closing balance
	 * for stock accounts, unchanged for flow accounts), keyed by month.
	 *
	 * @param string $accountNumber The account number.
	 * @param string $metric Either `closingBalance` or `netMovement`.
	 * @param array<string,mixed> $vsActualsContext The {@see BudgetVsActualsReader} context.
	 *
	 * @return array<string,int> Month => the metric-reduced value, in EUR cents.
	 */
	private function actualDisplaySeries(string $accountNumber, string $metric, array $vsActualsContext): array {
		$bucket = ($vsActualsContext['actualsByAccountMonth'][$accountNumber] ?? []);
		if ($bucket === []) {
			return [];
		}

		$bucketMonths = array_keys($bucket);
		sort($bucketMonths);
		$earliest = $bucketMonths[0];
		$last = $bucketMonths[count($bucketMonths) - 1];

		$denseMonths = [];
		$denseValues = [];
		$cursor = $earliest;
		$guard = 0;
		while ($cursor <= $last && $guard < self::MAX_MONTHS * 10) {
			$denseMonths[] = $cursor;
			$denseValues[] = (int)($bucket[$cursor] ?? 0);
			$cursor = $this->projectionCalculator->nextMonth(month: $cursor);
			$guard++;
		}

		$series = $this->projectionCalculator->metricSeries(orderedNetMovementCents: $denseValues, metric: $metric);

		return array_combine($denseMonths, $series);

	}//end actualDisplaySeries()

	/**
	 * Wrap a flat cents-per-month series (`budgeted`) into the trend shape
	 * {@see BudgetProjectionCalculator::cumulative()} expects, and reuse
	 * that same public method — no re-derived running-sum rule.
	 *
	 * @param array<string,int> $series Month => cents.
	 * @param list<string> $months The requested months, in order.
	 * @param string $accountType The account's type (decides flow vs. stock cumulative rule).
	 *
	 * @return array<string,int> Month => cumulative cents.
	 */
	private function cumulativeFromFlatSeries(array $series, array $months, string $accountType): array {
		if ($series === []) {
			return [];
		}

		$asTrend = [];
		foreach ($months as $month) {
			$asTrend[] = ['kind' => 'actual', 'amount' => (int)($series[$month] ?? 0)];
		}

		$cumulativeSeries = $this->projectionCalculator->cumulative(trend: $asTrend, accountType: $accountType);

		return array_combine($months, $cumulativeSeries);

	}//end cumulativeFromFlatSeries()

	/**
	 * Reduce a typed `trend` series into its cumulative series
	 * (REQ-BPE-008), tolerating `unprojectable` months mid-series (the
	 * calculator's own "contributes 0" rule).
	 *
	 * @param array<string,array<string,mixed>> $trend Month => typed result.
	 * @param string $accountType The account's type.
	 *
	 * @return array<string,int> Month => cumulative cents.
	 */
	private function cumulativeFromTrend(array $trend, string $accountType): array {
		$months = array_keys($trend);
		$cumulativeSeries = $this->projectionCalculator->cumulative(trend: array_values($trend), accountType: $accountType);

		return array_combine($months, $cumulativeSeries);

	}//end cumulativeFromTrend()

	/**
	 * Find the first `LedgerGroup` whose OWN resolved `memberAccountNumbers`
	 * (direct membership) includes this account — the class docblock's
	 * "which verzamelpost owns this account's plan" resolution.
	 *
	 * @param string $accountNumber The account number.
	 * @param array<string,mixed> $vsActualsContext The {@see BudgetVsActualsReader} context.
	 *
	 * @return string|null The owning LedgerGroup's id (preferred) or slug, or null when none owns it.
	 */
	private function owningLedgerGroupKey(string $accountNumber, array $vsActualsContext): ?string {
		foreach ($vsActualsContext['ledgerGroupEntries'] as $entry) {
			if (in_array($accountNumber, ($entry['memberAccountNumbers'] ?? []), true) === true) {
				$id = (string)($entry['id'] ?? '');
				if ($id !== '') {
					return $id;
				}

				$slug = (string)($entry['slug'] ?? '');
				$slugKey = null;
				if ($slug !== '') {
					$slugKey = $slug;
				}

				return $slugKey;
			}
		}

		return null;

	}//end owningLedgerGroupKey()

	/**
	 * Build a `LedgerGroup`'s response envelope: `accountTypes` (for the
	 * frontend's stock/flow Cumulative-toggle rule, REQ-BCH-005), a
	 * `trend` built as the sum of resolved members' own trends
	 * (REQ-BPE-007, via {@see BudgetProjectionCalculator::groupProjected()}),
	 * its own `cumulative`, and `budgeted` (REQ-BCS-008's "own wins over
	 * children" rule, via {@see BudgetVsActualsCalculator::budgetedAmount()}).
	 *
	 * @param array<string,mixed> $entry One `ledgerGroupEntries` row.
	 * @param list<string> $months The requested months.
	 * @param array<string,mixed> $vsActualsContext The {@see BudgetVsActualsReader} context.
	 * @param array<int,string|null> $annualBudgetIdsByYear Fiscal year => resolved AnnualBudget id.
	 * @param array<string,array<string,mixed>> $envelopesByAccount Every already-built account envelope, keyed by account number.
	 *
	 * @return array<string,mixed> The LedgerGroup's envelope.
	 */
	private function buildLedgerGroupEnvelope(
		array $entry,
		array $months,
		array $vsActualsContext,
		array $annualBudgetIdsByYear,
		array $envelopesByAccount
	): array {
		$ledgerGroupKey = (string)($entry['slug'] ?? '');
		$ledgerGroupId = (string)($entry['id'] ?? '');
		if ($ledgerGroupId !== '') {
			$ledgerGroupKey = $ledgerGroupId;
		}
		$memberAccountNumbers = ($entry['memberAccountNumbers'] ?? []);

		$memberEnvelopes = [];
		$accountTypes = [];
		foreach ($memberAccountNumbers as $memberAccountNumber) {
			$memberEnvelope = ($envelopesByAccount[$memberAccountNumber] ?? null);
			if ($memberEnvelope === null) {
				continue;
			}

			$memberEnvelopes[] = $memberEnvelope;
			$accountTypes[(string)$memberEnvelope['accountType']] = true;
		}

		$accountTypes = array_keys($accountTypes);
		$predominantAccountType = ($accountTypes[0] ?? 'expenses');

		$trend = [];
		foreach ($months as $month) {
			$membersForMonth = [];
			foreach ($memberEnvelopes as $memberEnvelope) {
				$membersForMonth[] = $memberEnvelope['trend'][$month];
			}

			$grouped = $this->projectionCalculator->groupProjected(members: $membersForMonth);

			// The groupProjected() method itself only ever returns `unprojectable` or
			// `projected` — it deliberately does not distinguish a month
			// where EVERY member's own kind was `actual` (real GL data)
			// from one where members are a mix, or all `projected`
			// (REQ-BPE-007's own contract: sum members, tag `partial`, no
			// third `actual` case). REQ-BCH-006 needs that distinction at
			// the GROUP level too, so it is added HERE as a thin
			// relabelling over the already-computed sum/partial-tag — the
			// amount itself is untouched, taken verbatim from
			// `groupProjected()`.
			if ($grouped['kind'] === 'projected') {
				$grouped['kind'] = $this->groupMonthKind(membersForMonth: $membersForMonth);
			}

			$trend[$month] = $grouped;
		}

		$cumulative = $this->cumulativeFromTrend(trend: $trend, accountType: $predominantAccountType);

		$budgeted = $this->budgetedSeries(
			ledgerGroupKey: $ledgerGroupKey,
			months: $months,
			vsActualsContext: $vsActualsContext,
			annualBudgetIdsByYear: $annualBudgetIdsByYear
		);

		return [
			'ledgerGroupKey' => $ledgerGroupKey,
			'name' => (string)($entry['name'] ?? $ledgerGroupKey),
			'memberAccountNumbers' => $memberAccountNumbers,
			'accountTypes' => $accountTypes,
			'trend' => $trend,
			'cumulative' => $cumulative,
			'budgeted' => $budgeted,
			'budgetedCumulative' => $this->cumulativeFromFlatSeries(series: $budgeted, months: $months, accountType: $predominantAccountType),
		];

	}//end buildLedgerGroupEnvelope()

	/**
	 * Classify a `LedgerGroup` month as `actual` (every resolved member's
	 * own kind for that month was `actual`) or `projected` (at least one
	 * member was `projected`, or the mix included some `unprojectable`
	 * members alongside real ones) — the split REQ-BCH-006 needs at the
	 * group level, which {@see BudgetProjectionCalculator::groupProjected()}
	 * itself does not report (its own contract only distinguishes
	 * `unprojectable` from a generic `projected` sum).
	 *
	 * @param list<array<string,mixed>> $membersForMonth One resolved member's own typed trend entry per member, for this month.
	 *
	 * @return string Either `actual` or `projected`.
	 */
	private function groupMonthKind(array $membersForMonth): string {
		$contributing = array_filter(
			$membersForMonth,
			static function (array $member): bool {
				return $member['kind'] !== 'unprojectable';
			}
		);

		foreach ($contributing as $member) {
			if ($member['kind'] !== 'actual') {
				return 'projected';
			}
		}

		return 'actual';

	}//end groupMonthKind()

	/**
	 * A `LedgerGroup`'s budgeted cents per month, resolved per fiscal year
	 * against ONLY that year's own `AnnualBudget` — the context's
	 * `budgetLines` may hold more than one fiscal year's rows at once
	 * (loaded in a single batched call, REQ-BCH-008), so each month's
	 * lookup is scoped to a per-year FILTERED view of the same context
	 * rather than letting {@see BudgetVsActualsCalculator::ownBudgetLine()}
	 * match the first same-`ledgerGroupId` row regardless of year.
	 *
	 * @param string $ledgerGroupKey The LedgerGroup's id or slug.
	 * @param list<string> $months The requested months.
	 * @param array<string,mixed> $vsActualsContext The {@see BudgetVsActualsReader} context.
	 * @param array<int,string|null> $annualBudgetIdsByYear Fiscal year => resolved AnnualBudget id.
	 *
	 * @return array<string,int> Month => budgeted cents.
	 */
	private function budgetedSeries(string $ledgerGroupKey, array $months, array $vsActualsContext, array $annualBudgetIdsByYear): array {
		$byYearContext = [];
		$series = [];

		foreach ($months as $month) {
			$year = (int)substr($month, 0, 4);
			$monthNumber = (int)substr($month, 5, 2);
			$annualBudgetId = ($annualBudgetIdsByYear[$year] ?? null);
			if ($annualBudgetId === null) {
				$series[$month] = 0;
				continue;
			}

			if (isset($byYearContext[$year]) === false) {
				$yearContext = $vsActualsContext;
				$yearContext['budgetLines'] = array_values(
					array_filter(
						$vsActualsContext['budgetLines'],
						static function (array $line) use ($annualBudgetId): bool {
							return (string)($line['annualBudgetId'] ?? '') === $annualBudgetId;
						}
					)
				);
				$byYearContext[$year] = $yearContext;
			}

			$series[$month] = $this->vsActualsCalculator->budgetedAmount(
				ledgerGroupKey: $ledgerGroupKey,
				monthNumber: $monthNumber,
				context: $byYearContext[$year]
			);
		}//end foreach

		return $series;

	}//end budgetedSeries()

	/**
	 * Resolve the OpenRegister register slug from app config.
	 *
	 * @return string The register slug, defaulting to `shillinq`.
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;

	}//end register()
}//end class
