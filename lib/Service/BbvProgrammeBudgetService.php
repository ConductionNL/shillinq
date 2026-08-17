<?php

/**
 * BBV Provincie Programme Budget Service
 *
 * The `programmeBudgetVsActuals` aggregation the provincies-BBV Compliance
 * Dashboard was written against and which no code ever computed (#866/#862).
 *
 * `src/manifest.d/bookkeeping-provincies-bbv-variant.json` declared the whole
 * dashboard body under `config.dashboard.{kpis,charts,exceptions,filters}` and
 * sourced four of its values from a NAMED OpenRegister aggregation called
 * `programmeBudgetVsActuals`. Neither existed: `CnDashboardPage` has no
 * `dashboard` prop, and OpenRegister has no named-aggregation registry — its
 * ad-hoc aggregation endpoint takes a `metric` + `field`, not a name. So the
 * page mounted an EMPTY dashboard for every visitor while the manifest
 * validated. This service is that aggregation, computed here where the join
 * across four schemas can actually be expressed.
 *
 * ## Where each number comes from, and the one place the spec cannot be met literally
 *
 * REQ-BBC-001 asks for four KPIs. Three map onto declared properties directly:
 *
 *  - **Total budget** — `sum(Budget.totalAmount)` over the administration's
 *    budgets for the fiscal year.
 *  - **Spent** — `sum(GLLine.amount)` for lines whose parent `GLTransaction`
 *    is in state `posted` inside the fiscal-year window, grouped by the line's
 *    `programmeStructure`.
 *  - **Remaining** — `totalBudget - (committed + spent)`, per REQ-BBC-001 §4.
 *
 * **Committed cannot be read the way REQ-BBC-001 words it.** The requirement
 * says "sum of `GLLine.amount` where `status: "committed"`". `GLLine` declares
 * no `status` property at all, and `GLTransaction.state` is
 * `draft|posted|reversed` — there is no `committed` state anywhere in the
 * ledger, so that filter would match nothing for every value, silently (the
 * failure mode {@see CashflowExportService} documents for `filters.id`).
 * Commitments in this application live in the verplichtingenadministratie:
 * `Verplichtingsregel` carries `programme`, `financialYear`, `administrationId`
 * and `remaining_committed`, which is precisely the "active contracts and
 * purchase orders" the same requirement's own prose describes. Committed is
 * therefore summed from there. The deviation is recorded rather than hidden:
 * implementing the literal wording would have produced a permanent zero that
 * looked like a working KPI.
 *
 * ## Scoping
 *
 * Every read is confined to administrations the caller holds a valid
 * `AdministrationMembership` for (REQ-MA-001), resolved server-side through
 * {@see AdministrationContextService}. The only caller-supplied inputs are the
 * three REQ-BBC-002 filters (programme, fiscal year, budget status); none of
 * them is an object identifier, so no per-object authorisation guard appears in
 * this class — there is nothing addressable to guard. A `fiscalYear` the caller
 * supplies is used only as a value filter over already-scoped rows.
 *
 * Every OpenRegister lookup filters on a DECLARED schema property. None filters
 * on `id`.
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
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Computes programme budget-vs-actuals for the provincies-BBV dashboard.
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) ADR-084 injects the
 *     OpenRegister contract alongside the two shillinq context services.
 */
class BbvProgrammeBudgetService {
	/**
	 * Budget schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_BUDGET = 'Budget';

	/**
	 * GL transaction (header) schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_GL_TRANSACTION = 'GLTransaction';

	/**
	 * GL line schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_GL_LINE = 'GLLine';

	/**
	 * Commitment-line schema slug (verplichtingenadministratie).
	 *
	 * @var string
	 */
	public const SCHEMA_COMMITMENT_LINE = 'Verplichtingsregel';

	/**
	 * Chart-of-accounts schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_ACCOUNT = 'Account';

	/**
	 * The seven BBV programme codes REQ-BBC-002 enumerates.
	 *
	 * @var array<int,string>
	 */
	public const PROGRAMMES = [
		'ruimte',
		'mobiliteit',
		'water',
		'milieu',
		'cultuur',
		'economie',
		'bestuur',
	];

	/**
	 * Remaining-share above which a programme is green (REQ-BBC-001).
	 *
	 * @var float
	 */
	private const GREEN_THRESHOLD = 0.15;

	/**
	 * Construct the service.
	 *
	 * @param IAppConfig $appConfig App config (OpenRegister register slug).
	 * @param LoggerInterface $logger Logger — never receives a record body.
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-083/084).
	 * @param AdministrationContextService $administrationContext Membership guard (REQ-MA-001).
	 * @param FiscalYearContextService $fiscalYearContext Active fiscal-year window resolver.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly AdministrationContextService $administrationContext,
		private readonly FiscalYearContextService $fiscalYearContext,
	) {

	}//end __construct()

	/**
	 * Build the programme budget-vs-actuals envelope (REQ-BBC-001..003).
	 *
	 * @param integer|null $fiscalYear Fiscal-year filter; null = the caller's active year.
	 * @param array<int,string>|null $programmes Programme filter; null = every programme.
	 *                                           An EMPTY array means "none selected",
	 *                                           which REQ-BBC-002 requires to show no
	 *                                           data rather than everything.
	 * @param array<int,string>|null $statuses Budget-status filter; null = every status.
	 *
	 * @return array<string,mixed> The dashboard envelope; an empty envelope when the
	 *         caller has no accessible administration.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function programmeBudgetVsActuals(
		?int $fiscalYear = null,
		?array $programmes = null,
		?array $statuses = null,
	): array {
		$administrationIds = $this->administrationContext->accessibleAdministrationIds();
		if ($administrationIds === []) {
			return $this->emptyEnvelope();
		}

		$window = $this->resolveWindow(administrationIds: $administrationIds, fiscalYear: $fiscalYear);
		if ($window === null) {
			return $this->emptyEnvelope();
		}

		$budgets = $this->budgetsFor(
			administrationIds: $administrationIds,
			fiscalYear: $window['fiscalYear'],
			statuses: $statuses
		);
		$spend = $this->spendByProgramme(administrationIds: $administrationIds, window: $window);
		$committed = $this->committedByProgramme(
			administrationIds: $administrationIds,
			fiscalYear: $window['fiscalYear']
		);

		$selected = $this->selectedProgrammes(
			requested: $programmes,
			seen: array_merge(
				array_keys($this->budgetByProgramme(budgets: $budgets)),
				array_keys($spend['byProgramme']),
				array_keys($committed)
			)
		);

		$rows = $this->buildRows(
			selected: $selected,
			budgetByProgramme: $this->budgetByProgramme(budgets: $budgets),
			spendByProgramme: $spend['byProgramme'],
			committedByProgramme: $committed
		);

		return [
			'scope' => [
				'administrationIds' => array_values($administrationIds),
				'fiscalYear' => $window['fiscalYear'],
				'startDate' => $window['startDate'],
				'endDate' => $window['endDate'],
				'currency' => 'EUR',
			],
			'totals' => $this->totalsFor(rows: $rows),
			'programmes' => $this->seriesFor(rows: $rows),
			'trend' => $this->trendFor(
				window: $window,
				monthlySpend: $spend['byMonth'],
				selected: $selected,
				totalBudget: $this->sumOf(rows: $rows, key: 'totalBudget')
			),
			'exceptions' => $this->exceptionsFor(rows: $rows),
			'fiscalYears' => $this->fiscalYearsFor(administrationIds: $administrationIds),
			'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];

	}//end programmeBudgetVsActuals()

	/**
	 * Build the GL-line filter facets the Budget-to-Programme Linker renders
	 * (REQ-BBL-001, "Filter bar: account type, programme, assignment status").
	 *
	 * Each facet resolves to a filter over a property `GLLine` actually
	 * DECLARES, so selecting one narrows the list instead of matching nothing:
	 *
	 *  - **Account type** is a property of `Account`, not of `GLLine`. The facet
	 *    therefore carries the administration's account NUMBERS per type, and
	 *    the client filters `accountNumber[]` — a declared GL-line property.
	 *  - **Programme** filters `programmeStructure` directly.
	 *  - **Assignment status** maps onto OpenRegister's `empty` operator over
	 *    `programmeStructure`, which is what "unmapped" means.
	 *
	 * @return array<string,mixed> The facet envelope; empty facets when the
	 *         caller has no accessible administration.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function glLineFacets(): array {
		$administrationIds = $this->administrationContext->accessibleAdministrationIds();
		if ($administrationIds === []) {
			return ['accountTypes' => [], 'programmes' => [], 'assignmentStatuses' => []];
		}

		$byType = [];
		foreach ($administrationIds as $administrationId) {
			$accounts = $this->query(
				schema: self::SCHEMA_ACCOUNT,
				filters: ['administrationId' => $administrationId]
			);
			foreach ($accounts as $account) {
				$type = (string)($account['accountType'] ?? '');
				$number = (string)($account['accountNumber'] ?? '');
				if ($type === '' || $number === '') {
					continue;
				}

				$byType[$type][$number] = true;
			}
		}

		$accountTypes = [];
		foreach ($byType as $type => $numbers) {
			$accountTypes[] = [
				'value' => $type,
				'label' => $this->humanise(code: $type),
				'accountNumbers' => array_keys($numbers),
			];
		}

		usort(
			$accountTypes,
			static function (array $left, array $right): int {
				return strcmp((string)$left['value'], (string)$right['value']);
			}
		);

		$programmes = [];
		foreach (self::PROGRAMMES as $code) {
			$programmes[] = ['value' => $code, 'label' => $this->humanise(code: $code)];
		}

		return [
			'accountTypes' => $accountTypes,
			'programmes' => $programmes,
			'assignmentStatuses' => [
				['value' => 'mapped', 'label' => 'Mapped'],
				['value' => 'unmapped', 'label' => 'Unmapped'],
			],
		];

	}//end glLineFacets()

	/**
	 * Resolve the fiscal-year window to report on.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 * @param integer|null $fiscalYear Requested fiscal year, or null for the active one.
	 *
	 * @return array{fiscalYear:int,startDate:string,endDate:string}|null The window.
	 */
	private function resolveWindow(array $administrationIds, ?int $fiscalYear): ?array {
		foreach ($administrationIds as $administrationId) {
			$window = $this->fiscalYearContext->resolveActiveWindow(administrationId: $administrationId);
			if ($window === null) {
				continue;
			}

			if ($fiscalYear === null || $fiscalYear === (int)$window['fiscalYear']) {
				return [
					'fiscalYear' => (int)$window['fiscalYear'],
					'startDate' => (string)$window['startDate'],
					'endDate' => (string)$window['endDate'],
				];
			}

			// A prior year was asked for: keep the administration's window
			// shape but shift it, so a 1 April start stays a 1 April start.
			$shift = ($fiscalYear - (int)$window['fiscalYear']);
			return [
				'fiscalYear' => $fiscalYear,
				'startDate' => $this->shiftYear(date: (string)$window['startDate'], years: $shift),
				'endDate' => $this->shiftYear(date: (string)$window['endDate'], years: $shift),
			];
		}

		return null;

	}//end resolveWindow()

	/**
	 * Shift an ISO date by a whole number of years.
	 *
	 * @param string $date ISO-8601 date (`YYYY-MM-DD`, optionally with a time part).
	 * @param integer $years Number of years to add (may be negative).
	 *
	 * @return string The shifted date, or the input when it is unparseable.
	 */
	private function shiftYear(string $date, int $years): string {
		if (preg_match('/^(\d{4})(-\d{2}-\d{2}.*)$/', $date, $matches) !== 1) {
			return $date;
		}

		return sprintf('%04d%s', ((int)$matches[1] + $years), $matches[2]);

	}//end shiftYear()

	/**
	 * Load the budgets in scope for the fiscal year.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 * @param integer $fiscalYear Fiscal year.
	 * @param array<int,string>|null $statuses Budget-status filter; null = every status.
	 *
	 * @return list<array<string,mixed>> The matching budgets.
	 */
	private function budgetsFor(array $administrationIds, int $fiscalYear, ?array $statuses): array {
		$rows = [];
		foreach ($administrationIds as $administrationId) {
			foreach ($this->query(schema: self::SCHEMA_BUDGET, filters: ['administrationId' => $administrationId]) as $budget) {
				if ((int)($budget['fiscalYear'] ?? 0) !== $fiscalYear) {
					continue;
				}

				if ($statuses !== null && in_array((string)($budget['status'] ?? ''), $statuses, true) === false) {
					continue;
				}

				$rows[] = $budget;
			}
		}

		return $rows;

	}//end budgetsFor()

	/**
	 * Sum budget amounts per programme.
	 *
	 * @param list<array<string,mixed>> $budgets The budgets in scope.
	 *
	 * @return array<string,float> Programme code => budgeted amount.
	 */
	private function budgetByProgramme(array $budgets): array {
		$totals = [];
		foreach ($budgets as $budget) {
			$programme = (string)($budget['programmeStructure'] ?? '');
			if ($programme === '') {
				continue;
			}

			$totals[$programme] = (($totals[$programme] ?? 0.0) + (float)($budget['totalAmount'] ?? 0));
		}

		return $totals;

	}//end budgetByProgramme()

	/**
	 * Sum POSTED GL spend per programme, and per month for the trend chart.
	 *
	 * Only `GLTransaction.state === 'posted'` transactions inside the window
	 * count: a draft journal is not spend, and a reversed one has a
	 * counter-posting of its own.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 * @param array{fiscalYear:int,startDate:string,endDate:string} $window Fiscal-year window.
	 *
	 * @return array{byProgramme:array<string,float>,byMonth:array<string,float>} The sums.
	 */
	private function spendByProgramme(array $administrationIds, array $window): array {
		$monthOfTransaction = [];
		foreach ($administrationIds as $administrationId) {
			$transactions = $this->query(
				schema: self::SCHEMA_GL_TRANSACTION,
				filters: ['administrationId' => $administrationId, 'state' => 'posted']
			);
			foreach ($transactions as $transaction) {
				$postingDate = (string)($transaction['postingDate'] ?? '');
				if ($postingDate === '' || $postingDate < $window['startDate'] || $postingDate > $window['endDate']) {
					continue;
				}

				// A GL line's `transactionId` references its parent by EITHER
				// the OpenRegister object id or the human transactionNumber,
				// depending on which writer created it. Keying both is the
				// idiom {@see FinancialSeriesCalculator::postedLinesByMonth()}
				// already established here; keying only one silently drops
				// every line written by the other path, which reads as
				// "this programme spent nothing".
				$month = substr($postingDate, 0, 7);
				$objectId = (string)($transaction['@self']['id'] ?? $transaction['id'] ?? '');
				if ($objectId !== '') {
					$monthOfTransaction[$objectId] = $month;
				}

				$number = (string)($transaction['transactionNumber'] ?? '');
				if ($number !== '') {
					$monthOfTransaction[$number] = $month;
				}
			}
		}

		$byProgramme = [];
		$byMonth = [];
		if ($monthOfTransaction === []) {
			return ['byProgramme' => $byProgramme, 'byMonth' => $byMonth];
		}

		foreach ($this->query(schema: self::SCHEMA_GL_LINE, filters: []) as $line) {
			$transactionId = (string)($line['transactionId'] ?? '');
			if (isset($monthOfTransaction[$transactionId]) === false) {
				continue;
			}

			$programme = (string)($line['programmeStructure'] ?? '');
			if ($programme === '') {
				continue;
			}

			$amount = $this->signedAmount(line: $line);
			$byProgramme[$programme] = (($byProgramme[$programme] ?? 0.0) + $amount);
			$month = $monthOfTransaction[$transactionId];
			$byMonth[$month] = (($byMonth[$month] ?? 0.0) + $amount);
		}

		return ['byProgramme' => $byProgramme, 'byMonth' => $byMonth];

	}//end spendByProgramme()

	/**
	 * Resolve a GL line's contribution to programme spend.
	 *
	 * A debit on an expense programme increases spend; a credit reduces it
	 * (a correction or a refund). Returning the raw amount for both would make
	 * a reversal look like more spend, not less.
	 *
	 * @param array<string,mixed> $line The GL line.
	 *
	 * @return float The signed amount.
	 */
	private function signedAmount(array $line): float {
		$amount = (float)($line['amount'] ?? 0);
		if ((string)($line['side'] ?? 'debit') === 'credit') {
			return -$amount;
		}

		return $amount;

	}//end signedAmount()

	/**
	 * Sum outstanding commitments per programme.
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 * @param integer $fiscalYear Fiscal year.
	 *
	 * @return array<string,float> Programme code => committed amount.
	 */
	private function committedByProgramme(array $administrationIds, int $fiscalYear): array {
		$totals = [];
		foreach ($administrationIds as $administrationId) {
			$lines = $this->query(
				schema: self::SCHEMA_COMMITMENT_LINE,
				filters: ['administrationId' => $administrationId]
			);
			foreach ($lines as $line) {
				if ((int)($line['financialYear'] ?? 0) !== $fiscalYear) {
					continue;
				}

				$programme = (string)($line['programme'] ?? '');
				if ($programme === '') {
					continue;
				}

				$totals[$programme] = (($totals[$programme] ?? 0.0) + (float)($line['remaining_committed'] ?? 0));
			}
		}

		return $totals;

	}//end committedByProgramme()

	/**
	 * Decide which programmes the envelope reports on.
	 *
	 * @param array<int,string>|null $requested The REQ-BBC-002 programme filter.
	 * @param array<int,string> $seen Programme codes present in the data.
	 *
	 * @return list<string> The programme codes, in the REQ-BBC-002 order.
	 */
	private function selectedProgrammes(?array $requested, array $seen): array {
		// REQ-BBC-002: "Selecting no programme MUST show no data (not all
		// programmes)." An EMPTY array is a selection of none; null is no
		// filter at all.
		if ($requested !== null && $requested === []) {
			return [];
		}

		$universe = self::PROGRAMMES;
		foreach ($seen as $code) {
			if ($code !== '' && in_array($code, $universe, true) === false) {
				$universe[] = $code;
			}
		}

		if ($requested === null) {
			return array_values($universe);
		}

		return array_values(array_filter($universe, static fn (string $code): bool => in_array($code, $requested, true)));

	}//end selectedProgrammes()

	/**
	 * Build one row per selected programme.
	 *
	 * @param list<string> $selected Programme codes to report on.
	 * @param array<string,float> $budgetByProgramme Budget sums.
	 * @param array<string,float> $spendByProgramme Spend sums.
	 * @param array<string,float> $committedByProgramme Commitment sums.
	 *
	 * @return list<array<string,mixed>> The per-programme rows.
	 */
	private function buildRows(
		array $selected,
		array $budgetByProgramme,
		array $spendByProgramme,
		array $committedByProgramme,
	): array {
		$rows = [];
		foreach ($selected as $code) {
			$totalBudget = (float)($budgetByProgramme[$code] ?? 0.0);
			$spent = (float)($spendByProgramme[$code] ?? 0.0);
			$committed = (float)($committedByProgramme[$code] ?? 0.0);
			$remaining = ($totalBudget - ($committed + $spent));

			$rows[] = [
				'programmeStructure' => $code,
				'programme' => $this->humanise(code: $code),
				'totalBudget' => $totalBudget,
				'committed' => $committed,
				'spent' => $spent,
				'remaining' => $remaining,
				'overspent' => max(0.0, -$remaining),
				'remainingRatio' => $this->ratio(part: $remaining, whole: $totalBudget),
				'utilisation' => $this->ratio(part: ($committed + $spent), whole: $totalBudget),
				'status' => $this->trafficLight(remaining: $remaining, totalBudget: $totalBudget),
			];
		}

		return $rows;

	}//end buildRows()

	/**
	 * Apply the REQ-BBC-001 traffic-light rule.
	 *
	 * @param float $remaining Remaining budget in EUR.
	 * @param float $totalBudget Total budget in EUR.
	 *
	 * @return string One of `green`, `yellow`, `red`.
	 */
	private function trafficLight(float $remaining, float $totalBudget): string {
		if ($remaining < 0.0) {
			return 'red';
		}

		if ($totalBudget <= 0.0) {
			// No budget and no overspend: nothing to be amber about.
			return 'green';
		}

		if (($remaining / $totalBudget) >= self::GREEN_THRESHOLD) {
			return 'green';
		}

		return 'yellow';

	}//end trafficLight()

	/**
	 * Divide safely.
	 *
	 * @param float $part The numerator.
	 * @param float $whole The denominator.
	 *
	 * @return float The ratio, or 0.0 when the denominator is zero.
	 */
	private function ratio(float $part, float $whole): float {
		if ($whole == 0.0) {
			return 0.0;
		}

		return ($part / $whole);

	}//end ratio()

	/**
	 * Sum one numeric key across the rows.
	 *
	 * @param list<array<string,mixed>> $rows The per-programme rows.
	 * @param string $key The key to sum.
	 *
	 * @return float The total.
	 */
	private function sumOf(array $rows, string $key): float {
		$total = 0.0;
		foreach ($rows as $row) {
			$total += (float)($row[$key] ?? 0);
		}

		return $total;

	}//end sumOf()

	/**
	 * Aggregate the four REQ-BBC-001 KPI values across the reported programmes.
	 *
	 * @param list<array<string,mixed>> $rows The per-programme rows.
	 *
	 * @return array<string,mixed> The KPI bag.
	 */
	private function totalsFor(array $rows): array {
		$totalBudget = $this->sumOf(rows: $rows, key: 'totalBudget');
		$committed = $this->sumOf(rows: $rows, key: 'committed');
		$spent = $this->sumOf(rows: $rows, key: 'spent');
		$remaining = ($totalBudget - ($committed + $spent));

		return [
			'totalBudget' => $totalBudget,
			'committed' => $committed,
			'spent' => $spent,
			'remaining' => $remaining,
			'remainingRatio' => $this->ratio(part: $remaining, whole: $totalBudget),
			'utilisation' => $this->ratio(part: ($committed + $spent), whole: $totalBudget),
			'status' => $this->trafficLight(remaining: $remaining, totalBudget: $totalBudget),
			'programmeCount' => count($rows),
		];

	}//end totalsFor()

	/**
	 * Shape the per-programme rows as the parallel arrays a chart widget's
	 * `endpointSource` maps onto (`labelsPath` + one `path` per series).
	 *
	 * @param list<array<string,mixed>> $rows The per-programme rows.
	 *
	 * @return array<string,mixed> Labels, three series and the rows themselves.
	 */
	private function seriesFor(array $rows): array {
		$labels = [];
		$budget = [];
		$spent = [];
		$committed = [];
		foreach ($rows as $row) {
			$labels[] = (string)$row['programme'];
			$budget[] = round((float)$row['totalBudget'], 2);
			$spent[] = round((float)$row['spent'], 2);
			$committed[] = round((float)$row['committed'], 2);
		}

		return [
			'labels' => $labels,
			'budget' => $budget,
			'spent' => $spent,
			'committed' => $committed,
			'rows' => $rows,
		];

	}//end seriesFor()

	/**
	 * Build the cumulative monthly spend trend (REQ-BBC-001 "Trend Chart").
	 *
	 * Months with no GL postings appear as the previous cumulative value, never
	 * omitted — the requirement is explicit that a quiet month must not close
	 * the gap in the line.
	 *
	 * @param array{fiscalYear:int,startDate:string,endDate:string} $window Fiscal-year window.
	 * @param array<string,float> $monthlySpend Spend keyed `YYYY-MM`.
	 * @param list<string> $selected Programme codes reported on.
	 * @param float $totalBudget Total budget, drawn as the flat reference line.
	 *
	 * @return array<string,mixed> Months, cumulative spend and the budget reference.
	 */
	private function trendFor(array $window, array $monthlySpend, array $selected, float $totalBudget): array {
		$months = [];
		$cursor = substr($window['startDate'], 0, 7);
		$last = substr($window['endDate'], 0, 7);
		$guard = 0;
		while ($cursor <= $last && $guard < 120) {
			$months[] = $cursor;
			$cursor = $this->nextMonth(month: $cursor);
			$guard++;
		}

		$cumulative = [];
		$reference = [];
		$running = 0.0;
		foreach ($months as $month) {
			if ($selected !== []) {
				$running += (float)($monthlySpend[$month] ?? 0.0);
			}

			$cumulative[] = round($running, 2);
			$reference[] = round($totalBudget, 2);
		}

		return [
			'months' => $months,
			'cumulativeSpend' => $cumulative,
			'budgetReference' => $reference,
		];

	}//end trendFor()

	/**
	 * Advance a `YYYY-MM` bucket by one month.
	 *
	 * @param string $month The bucket.
	 *
	 * @return string The next bucket.
	 */
	private function nextMonth(string $month): string {
		$year = (int)substr($month, 0, 4);
		$index = (int)substr($month, 5, 2);
		$index++;
		if ($index > 12) {
			$index = 1;
			$year++;
		}

		return sprintf('%04d-%02d', $year, $index);

	}//end nextMonth()

	/**
	 * Build the REQ-BBC-003 exception list: overspent programmes, worst first.
	 *
	 * @param list<array<string,mixed>> $rows The per-programme rows.
	 *
	 * @return list<array<string,mixed>> The overspent rows.
	 */
	private function exceptionsFor(array $rows): array {
		$exceptions = array_values(
			array_filter(
				$rows,
				static function (array $row): bool {
					return ((float)$row['remaining'] < 0.0);
				}
			)
		);

		usort(
			$exceptions,
			static function (array $left, array $right): int {
				return ((float)$right['overspent'] <=> (float)$left['overspent']);
			}
		);

		return $exceptions;

	}//end exceptionsFor()

	/**
	 * Discover the fiscal years that have Budget data (REQ-BBC-002 filter options).
	 *
	 * @param array<int,string> $administrationIds Administrations the caller may read.
	 *
	 * @return list<int> The years, newest first.
	 */
	private function fiscalYearsFor(array $administrationIds): array {
		$years = [];
		foreach ($administrationIds as $administrationId) {
			foreach ($this->query(schema: self::SCHEMA_BUDGET, filters: ['administrationId' => $administrationId]) as $budget) {
				$year = (int)($budget['fiscalYear'] ?? 0);
				if ($year > 0) {
					$years[$year] = true;
				}
			}
		}

		$list = array_keys($years);
		rsort($list);

		return array_values($list);

	}//end fiscalYearsFor()

	/**
	 * Turn a lowercase code into a display label.
	 *
	 * @param string $code The code.
	 *
	 * @return string The label.
	 */
	private function humanise(string $code): string {
		return ucfirst($code);

	}//end humanise()

	/**
	 * The envelope returned when there is nothing the caller may read.
	 *
	 * The shape is IDENTICAL to a populated response so the dashboard renders
	 * zeroes rather than crashing, and so a cross-tenant probe cannot tell an
	 * empty administration from an inaccessible one.
	 *
	 * @return array<string,mixed> The empty envelope.
	 */
	private function emptyEnvelope(): array {
		return [
			'scope' => [
				'administrationIds' => [],
				'fiscalYear' => null,
				'startDate' => null,
				'endDate' => null,
				'currency' => 'EUR',
			],
			'totals' => [
				'totalBudget' => 0.0,
				'committed' => 0.0,
				'spent' => 0.0,
				'remaining' => 0.0,
				'remainingRatio' => 0.0,
				'utilisation' => 0.0,
				'status' => 'green',
				'programmeCount' => 0,
			],
			'programmes' => [
				'labels' => [],
				'budget' => [],
				'spent' => [],
				'committed' => [],
				'rows' => [],
			],
			'trend' => [
				'months' => [],
				'cumulativeSpend' => [],
				'budgetReference' => [],
			],
			'exceptions' => [],
			'fiscalYears' => [],
			'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
		];

	}//end emptyEnvelope()

	/**
	 * Run one property-filtered query against the shillinq register.
	 *
	 * A failure is logged and answered as an empty result set: a missing
	 * commitment register must not stop the budget half of the dashboard from
	 * rendering.
	 *
	 * @param string $schema The schema slug.
	 * @param array<string,mixed> $filters Property filters (never `id`).
	 *
	 * @return list<array<string,mixed>> The matching records as plain arrays.
	 */
	private function query(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (Throwable $e) {
			$this->logger->error(
				'BbvProgrammeBudgetService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
				continue;
			}

			if (is_object($row) === true && method_exists($row, 'getObject') === true) {
				$payload = $row->getObject();
				if (is_array($payload) === true) {
					$result[] = $payload;
				}
			}
		}

		return $result;

	}//end query()

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
