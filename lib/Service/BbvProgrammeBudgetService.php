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
 * It orchestrates and scopes only: {@see BbvProgrammeBudgetReader} does every
 * OpenRegister read and {@see BbvProgrammeBudgetCalculator} every sum,
 * threshold and series.
 *
 * ## Where each number comes from, and the one place the spec cannot be met literally
 *
 * REQ-BBC-001 asks for four KPIs. Three map onto declared properties directly:
 *
 *  - **Total budget** — `sum(BbvProgrammeBudget.totalAmount)` over the administration's
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
 * `CommitmentLine` carries `programme`, `financialYear`, `administrationId`
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

/**
 * Assembles the provincies-BBV dashboard envelopes (REQ-BBC-001..003, REQ-BBL-001).
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
class BbvProgrammeBudgetService {
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
	 * Construct the service.
	 *
	 * @param AdministrationContextService $adminContext Membership guard (REQ-MA-001).
	 * @param FiscalYearContextService $fiscalYearContext Active fiscal-year window resolver.
	 * @param BbvProgrammeBudgetReader $reader Every OpenRegister read the dashboards need.
	 * @param BbvProgrammeBudgetCalculator $calculator The REQ-BBC-001..003 arithmetic.
	 */
	public function __construct(
		private readonly AdministrationContextService $adminContext,
		private readonly FiscalYearContextService $fiscalYearContext,
		private readonly BbvProgrammeBudgetReader $reader,
		private readonly BbvProgrammeBudgetCalculator $calculator = new BbvProgrammeBudgetCalculator(),
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
		$administrationIds = $this->adminContext->accessibleAdministrationIds();
		if ($administrationIds === []) {
			return $this->emptyEnvelope();
		}

		$window = $this->resolveWindow(administrationIds: $administrationIds, fiscalYear: $fiscalYear);
		if ($window === null) {
			return $this->emptyEnvelope();
		}

		$budgetByProgramme = $this->reader->budgetByProgramme(
			budgets: $this->reader->budgetsFor(
				administrationIds: $administrationIds,
				fiscalYear: $window['fiscalYear'],
				statuses: $statuses
			)
		);
		$spend = $this->reader->spendByProgramme(
			administrationIds: $administrationIds,
			window: $window
		);
		$committed = $this->reader->committedByProgramme(
			administrationIds: $administrationIds,
			fiscalYear: $window['fiscalYear']
		);

		$selected = $this->calculator->selectedProgrammes(
			universe: self::PROGRAMMES,
			requested: $programmes,
			seen: array_merge(
				array_keys($budgetByProgramme),
				array_keys($spend['byProgramme']),
				array_keys($committed)
			)
		);

		$rows = $this->calculator->buildRows(
			selected: $selected,
			budgetByProgramme: $budgetByProgramme,
			spendByProgramme: $spend['byProgramme'],
			committedByProgramme: $committed
		);
		$totals = $this->calculator->totalsFor(rows: $rows);

		return [
			'scope' => [
				'administrationIds' => array_values($administrationIds),
				'fiscalYear' => $window['fiscalYear'],
				'startDate' => $window['startDate'],
				'endDate' => $window['endDate'],
				'currency' => 'EUR',
			],
			'totals' => $totals,
			'programmes' => $this->calculator->seriesFor(rows: $rows),
			'trend' => $this->calculator->trendFor(
				startDate: $window['startDate'],
				endDate: $window['endDate'],
				monthlySpend: $spend['byMonth'],
				anyProgrammeSelected: ($selected !== []),
				totalBudget: (float)$totals['totalBudget']
			),
			'exceptions' => $this->calculator->exceptionsFor(rows: $rows),
			'fiscalYears' => $this->reader->fiscalYearsFor(administrationIds: $administrationIds),
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
	 *  - **Assignment status** offers `mapped` only, and that is a MEASURED
	 *    limitation rather than an oversight — see below.
	 *
	 * ## Why `unmapped` is not offered
	 *
	 * "Unmapped" means the GL line has NO `programmeStructure`. OpenRegister
	 * stores only the properties an object actually carries, so an unassigned
	 * line has no such key at all — and the filter grammar cannot address an
	 * ABSENT key. Measured on a live instance, with a positive control that
	 * proves the operator family is alive:
	 *
	 *     Account?vatApplicable[null]=false   -> 115   (a key every row HAS)
	 *     Account?description[null]=false     ->   0
	 *     Account?description[null]=true      ->   0   (a key no row has)
	 *     GLLine?programmeStructure[empty]=true  -> 0
	 *     GLLine?programmeStructure[empty]=false -> 0
	 *
	 * BOTH truth values answering zero is the tell: the clause is unsatisfiable,
	 * not selective. `[ne]` and the `field[]=` IN form both work, so the facet
	 * offers the half that can be expressed — `mapped`, as an IN over the seven
	 * declared programme codes — rather than an `unmapped` option that would
	 * render, be clickable, and quietly return nothing. Restoring it needs an
	 * OpenRegister filter for an absent property; that is a different repo and
	 * a separate decision.
	 *
	 * @return array<string,mixed> The facet envelope; empty facets when the
	 *         caller has no accessible administration.
	 *
	 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
	 */
	public function glLineFacets(): array {
		$administrationIds = $this->adminContext->accessibleAdministrationIds();
		if ($administrationIds === []) {
			return ['accountTypes' => [], 'programmes' => [], 'assignmentStatuses' => []];
		}

		$byType = [];
		foreach ($this->reader->accountsFor(administrationIds: $administrationIds) as $account) {
			$type = (string)($account['accountType'] ?? '');
			$number = (string)($account['accountNumber'] ?? '');
			if ($type === '' || $number === '') {
				continue;
			}

			$byType[$type][$number] = true;
		}

		ksort($byType);
		$accountTypes = [];
		foreach ($byType as $type => $numbers) {
			$accountTypes[] = [
				'value' => (string)$type,
				'label' => $this->calculator->humanise(code: (string)$type),
				// `array_keys()` hands back INTEGERS here: PHP coerces a
				// numeric-string array key to int, so a chart of accounts
				// numbered `4100` would serialise as JSON numbers while
				// `accountNumber` is a declared STRING property. The filter
				// still worked by luck (the client stringifies on the way into
				// the query), but the endpoint's own contract would have been
				// wrong, and a leading-zero account like `0100` survives only
				// because the key was never numeric in the first place.
				'accountNumbers' => array_map(
					static function (int|string $number): string {
						return (string)$number;
					},
					array_keys($numbers)
				),
			];
		}

		$programmes = [];
		foreach (self::PROGRAMMES as $code) {
			$programmes[] = ['value' => $code, 'label' => $this->calculator->humanise(code: $code)];
		}

		return [
			'accountTypes' => $accountTypes,
			'programmes' => $programmes,
			'assignmentStatuses' => [
				['value' => 'mapped', 'label' => 'Mapped'],
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
			// SHAPE but shift it, so a 1 April fiscal-year start stays a
			// 1 April start. Recomputing it as a calendar year would quietly
			// report the wrong twelve months for every non-calendar province.
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
}//end class
