<?php

/**
 * Budget Grid Reader
 *
 * The OpenRegister half of the begroting grid (`budget-grid-view`,
 * REQ-BGV-001/002/003/009) — the year-basis screen rows-from-`LedgerGroup`,
 * columns-from-a-period-range view the user asked for. This class resolves
 * the row tree, the requested column range, and the past/future boundary per
 * `FiscalPeriod`; every `BudgetLine`↔`LedgerGroup`↔GL-activity value
 * resolution is delegated to `budget-core-schema`'s own
 * {@see BudgetVsActualsReader} — this class does not re-open that join and
 * does not read `TrialBalanceLine` (design.md §0/§1c amendment: no
 * `TrialBalanceLine` row is ever persisted).
 *
 * ## Query budget
 *
 * This reader's OWN reads:
 *
 *  1. `LedgerGroup.findAll([administrationId])` — the row tree
 *     ({@see self::rowsFor()}), once, unfiltered by period/column.
 *  2. `FiscalPeriod.findAll([administrationId])` — the past/future boundary
 *     ({@see self::pastColumns()}), once, unfiltered by column.
 *  3. `AnnualBudget.findAll([administrationId])` — resolves each fiscal year
 *     the displayed range touches to its `isDefault: true` `AnnualBudget`,
 *     once (design.md §2b's fiscal-year-crossing rule — this read is not
 *     itemised in design.md §1c's own summary table, which lists only
 *     LedgerGroup/FiscalPeriod/BudgetLine as this reader's "own" reads; a
 *     default-`AnnualBudget`-per-fiscal-year lookup is unavoidable for
 *     REQ-BGV-001 to work at all — there is no other code-answerable way to
 *     know which `AnnualBudget` id a calendar month's `BudgetLine`s belong
 *     to. Documented here rather than silently reproducing the gap: the
 *     provably-flat, row/column-independent total this class achieves is 8
 *     `findAll()` calls, not the design's stated 7).
 *  4. `BudgetLine.findAll([annualBudgetId: {in: [...]}])` — once, the
 *     `SpendAnalyticsService.php:183` `in`-filter precedent, scoped to every
 *     resolved default `AnnualBudget` id touched by the displayed range.
 *
 * Delegated to {@see BudgetVsActualsReader::loadContext()} (called with an
 * EMPTY `$annualBudgetIds` so its own 5th, BudgetLine, call is skipped — this
 * reader already loaded BudgetLine itself in (4) above):
 *
 *  5-8. `Account`, `GLTransaction`, `GLLine`, `LedgerGroup` (a second,
 *     deliberately redundant fetch — design.md §1c's own noted trade-off,
 *     accepted rather than forcing an interface change onto
 *     `budget-core-schema`'s already-spec'd reader).
 *
 * **Total: 8 `findAll()` calls, a flat constant independent of the number of
 * `LedgerGroup` rows, the number of rows expanded, or the number of
 * displayed columns** (REQ-BGV-009's core property — GL activity is fetched
 * once, unfiltered by period, and bucketed by calendar month in memory, so
 * adding more columns costs nothing further).
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
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-009
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
 * Resolves the begroting grid's row tree, column list, and past/future
 * boundary, batched to a flat 8 `findAll()` calls (REQ-BGV-009).
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-009
 */
class BudgetGridReader {
	/**
	 * Ledger group schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_LEDGER_GROUP = 'LedgerGroup';

	/**
	 * Fiscal period schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_FISCAL_PERIOD = 'FiscalPeriod';

	/**
	 * Annual budget schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_ANNUAL_BUDGET = 'AnnualBudget';

	/**
	 * Budget line schema slug.
	 *
	 * @var string
	 */
	public const SCHEMA_BUDGET_LINE = 'BudgetLine';

	/**
	 * `FiscalPeriod` states that mean "these books are final" (REQ-BGV-003,
	 * design.md §2c). `open`/`closing` never count as past.
	 *
	 * @var array<string,bool>
	 */
	private const PAST_STATES = ['closed' => true, 'audit-locked' => true];

	/**
	 * Construct the reader.
	 *
	 * @param IAppConfig $appConfig App config (OpenRegister register slug).
	 * @param LoggerInterface $logger Logger — never receives a record body.
	 * @param ObjectServiceInterface $objectService OpenRegister object service (ADR-083/084).
	 * @param BudgetVsActualsReader $budgetVsActualsReader Delegate for the BudgetLine/LedgerGroup/GL-activity join.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly BudgetVsActualsReader $budgetVsActualsReader,
	) {
	}//end __construct()

	/**
	 * Load everything one grid render needs: the row tree, the column list
	 * for the requested range/granularity, which columns are past, and the
	 * delegated budget-vs-actuals context — batched to 8 `findAll()` calls
	 * total (REQ-BGV-009).
	 *
	 * @param string $administrationId The administration to scope every read to.
	 * @param string $startPeriod First displayed calendar month, `YYYY-MM`.
	 * @param string $endPeriod Last displayed calendar month, `YYYY-MM`, inclusive.
	 * @param string $granularity `month` (default), `quarter`, or `year`.
	 *
	 * @return array{
	 *     rowTree: array{
	 *         entries: list<array{id:string,slug:string,code:string,name:string,order:int,parentRef:?string}>,
	 *         keyToIndex: array<string,int>,
	 *         childrenByIndex: array<int,list<int>>,
	 *         rootIndexes: list<int>,
	 *     },
	 *     columns: list<array{
	 *         key:string,label:string,granularity:string,startDate:string,endDate:string,
	 *         monthKeys:list<string>,isPast:bool,fiscalYears:list<int>
	 *     }>,
	 *     bvaContext: array<string,mixed>,
	 *     accountTypeByNumber: array<string,string>,
	 *     accountByNumber: array<string,array<string,mixed>>,
	 *     annualBudgetIdByYear: array<int,?string>,
	 * }
	 *
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-001
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-003
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-009
	 */
	public function loadGrid(string $administrationId, string $startPeriod, string $endPeriod, string $granularity): array {
		$rowTree = $this->rowsFor(administrationId: $administrationId);
		$columns = $this->columnsFor(range: ['start' => $startPeriod, 'end' => $endPeriod], granularity: $granularity);

		$fiscalPeriods = $this->query(schema: self::SCHEMA_FISCAL_PERIOD, filters: ['administrationId' => $administrationId]);
		$pastKeys = $this->pastColumnKeys(columns: $columns, fiscalPeriods: $fiscalPeriods);
		foreach ($columns as $index => $column) {
			$columns[$index]['isPast'] = (($pastKeys[$column['key']] ?? false) === true);
		}

		$fiscalYears = [];
		foreach ($columns as $column) {
			foreach ($column['fiscalYears'] as $year) {
				$fiscalYears[$year] = true;
			}
		}

		$annualBudgets = $this->query(schema: self::SCHEMA_ANNUAL_BUDGET, filters: ['administrationId' => $administrationId]);
		$annualBudgetIdByYear = $this->resolveDefaultAnnualBudgets(annualBudgets: $annualBudgets, fiscalYears: array_keys($fiscalYears));

		$relevantAnnualBudgetIds = array_values(array_filter($annualBudgetIdByYear, static fn ($id) => $id !== null));

		$budgetLinesByAnnualId = [];
		if ($relevantAnnualBudgetIds !== []) {
			$budgetLines = $this->query(
				schema: self::SCHEMA_BUDGET_LINE,
				filters: ['annualBudgetId' => ['in' => $relevantAnnualBudgetIds]]
			);
			foreach ($budgetLines as $line) {
				$ref = (string)($line['annualBudgetId'] ?? '');
				if ($ref === '') {
					continue;
				}

				$budgetLinesByAnnualId[$ref][] = $line;
			}
		}

		$bvaContext = $this->budgetVsActualsReader->loadContext(
			administrationId: $administrationId,
			annualBudgetIds: [],
			includeLedgerGroups: true
		);

		$accountByNumber = [];
		$accountTypeByNumber = [];
		foreach (($bvaContext['accounts'] ?? []) as $account) {
			$number = (string)($account['accountNumber'] ?? '');
			if ($number === '') {
				continue;
			}

			$accountByNumber[$number] = $account;
			$type = (string)($account['accountType'] ?? '');
			if ($type !== '') {
				$accountTypeByNumber[$number] = $type;
			}
		}

		// BudgetLinesByFiscalYear: for each fiscal year touched by the range,
		// the slice of budgetLines belonging to THAT year's default
		// AnnualBudget only. Kept separate per year so a range crossing a
		// fiscal-year boundary never lets one year's BudgetLine shadow
		// another's for the same LedgerGroup (design.md §2b).
		$budgetLinesByFiscalYear = [];
		foreach ($annualBudgetIdByYear as $year => $annualBudgetId) {
			if ($annualBudgetId === null) {
				$budgetLinesByFiscalYear[$year] = null;
				continue;
			}

			$budgetLinesByFiscalYear[$year] = ($budgetLinesByAnnualId[$annualBudgetId] ?? []);
		}

		return [
			'rowTree' => $rowTree,
			'columns' => $columns,
			'bvaContext' => $bvaContext,
			'accountTypeByNumber' => $accountTypeByNumber,
			'accountByNumber' => $accountByNumber,
			'annualBudgetIdByYear' => $annualBudgetIdByYear,
			'budgetLinesByFiscalYear' => $budgetLinesByFiscalYear,
		];

	}//end loadGrid()

	/**
	 * The current administration's `LedgerGroup` tree, fetched once
	 * (design.md §1c/§1a). Every field the UI needs to render a row
	 * (`code`/`name`/`order`) is kept — unlike
	 * {@see BudgetVsActualsReader}'s own internal index, which drops them
	 * since its own callers only need arithmetic, not display.
	 *
	 * @param string $administrationId The administration to scope the read to.
	 *
	 * @return array{
	 *     entries: list<array{id:string,slug:string,code:string,name:string,order:int,parentRef:?string}>,
	 *     keyToIndex: array<string,int>,
	 *     childrenByIndex: array<int,list<int>>,
	 *     rootIndexes: list<int>,
	 * }
	 *
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
	 */
	public function rowsFor(string $administrationId): array {
		$rows = $this->query(schema: self::SCHEMA_LEDGER_GROUP, filters: ['administrationId' => $administrationId]);

		$entries = [];
		$keyToIndex = [];
		foreach ($rows as $row) {
			$id = (string)($row['@self']['id'] ?? $row['id'] ?? '');
			$slug = (string)($row['@self']['slug'] ?? $row['slug'] ?? '');
			$index = count($entries);

			$parentRef = null;
			if (($row['parentLedgerGroupId'] ?? null) !== null) {
				$parentRef = (string)$row['parentLedgerGroupId'];
			}

			$entries[] = [
				'id' => $id,
				'slug' => $slug,
				'code' => (string)($row['code'] ?? ''),
				'name' => (string)($row['name'] ?? ''),
				'order' => (int)($row['order'] ?? 0),
				'parentRef' => $parentRef,
			];

			if ($id !== '') {
				$keyToIndex[$id] = $index;
			}

			if ($slug !== '' && $slug !== $id) {
				$keyToIndex[$slug] = $index;
			}
		}

		$childrenByIndex = [];
		$rootIndexes = [];
		foreach ($entries as $index => $entry) {
			$parentRef = $entry['parentRef'];
			if ($parentRef === null || $parentRef === '') {
				$rootIndexes[] = $index;
				continue;
			}

			$parentIndex = ($keyToIndex[$parentRef] ?? null);
			if ($parentIndex === null) {
				// Unresolved parent reference — treated as a root rather than
				// silently dropped from the tree (same convention as
				// BudgetVsActualsReader::buildLedgerGroupIndex()).
				$rootIndexes[] = $index;
				continue;
			}

			$childrenByIndex[$parentIndex][] = $index;
		}

		usort(
			$rootIndexes,
			fn (int $a, int $b): int => ($entries[$a]['order'] <=> $entries[$b]['order'])
		);
		foreach ($childrenByIndex as $parentIndex => $children) {
			usort(
				$children,
				fn (int $a, int $b): int => ($entries[$a]['order'] <=> $entries[$b]['order'])
			);
			$childrenByIndex[$parentIndex] = $children;
		}

		return ['entries' => $entries, 'keyToIndex' => $keyToIndex, 'childrenByIndex' => $childrenByIndex, 'rootIndexes' => $rootIndexes];

	}//end rowsFor()

	/**
	 * Generate the column list for a period range at a granularity
	 * (design.md §2a) — pure, no OpenRegister reads.
	 *
	 * @param array{start:string,end:string} $range `YYYY-MM` start/end, inclusive.
	 * @param string $granularity `month` (default), `quarter`, or `year`.
	 *
	 * @return list<array{key:string,label:string,granularity:string,startDate:string,endDate:string,monthKeys:list<string>,isPast:bool,fiscalYears:list<int>}>
	 *
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-001
	 */
	public function columnsFor(array $range, string $granularity): array {
		$start = $this->parseYearMonth(value: (string)($range['start'] ?? ''));
		$end = $this->parseYearMonth(value: (string)($range['end'] ?? ''));
		if ($start === null || $end === null) {
			return [];
		}

		// 0-based month ordinal (year*12 + (month-1)) — MUST match
		// buildColumn()'s own decode (`$year = intdiv($ordinal, 12); $month =
		// ($ordinal % 12) + 1`), which assumes ordinal 0 = January.
		$startOrdinal = (($start[0] * 12) + ($start[1] - 1));
		$endOrdinal = (($end[0] * 12) + ($end[1] - 1));
		if ($startOrdinal > $endOrdinal) {
			return [];
		}

		$step = match ($granularity) {
			'quarter' => 3,
			'year' => 12,
			default => 1,
		};

		// Align the first column's start to a period boundary so a
		// mid-quarter/mid-year start still produces a whole aligned column
		// (a quarter column is always Q1/Q2/Q3/Q4, never a partial span).
		$alignedStartOrdinal = $startOrdinal;
		if ($step > 1) {
			$offset = ($startOrdinal % $step);
			$alignedStartOrdinal = ($startOrdinal - $offset);
		}

		$columns = [];
		for ($ordinal = $alignedStartOrdinal; $ordinal <= $endOrdinal; $ordinal += $step) {
			$columns[] = $this->buildColumn(startOrdinal: $ordinal, step: $step, granularity: $granularity);
		}

		return $columns;

	}//end columnsFor()

	/**
	 * The set of column keys that are "past" per REQ-BGV-003 / design.md
	 * §2c: an exact-span closed/audit-locked `FiscalPeriod`, OR the column's
	 * calendar span fully contained within a coarser closed/audit-locked
	 * `FiscalPeriod`.
	 *
	 * @param list<array{key:string,startDate:string,endDate:string}> $columns The generated columns.
	 * @param list<array<string,mixed>> $fiscalPeriods The administration's FiscalPeriod rows.
	 *
	 * @return array<string,bool> columnKey => true for every past column.
	 *
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-003
	 */
	public function pastColumnKeys(array $columns, array $fiscalPeriods): array {
		$closedPeriods = [];
		foreach ($fiscalPeriods as $period) {
			$state = (string)($period['state'] ?? '');
			if ((self::PAST_STATES[$state] ?? false) !== true) {
				continue;
			}

			$closedPeriods[] = [
				'startDate' => (string)($period['startDate'] ?? ''),
				'endDate' => (string)($period['endDate'] ?? ''),
			];
		}

		$past = [];
		foreach ($columns as $column) {
			$columnStart = (string)$column['startDate'];
			$columnEnd = (string)$column['endDate'];

			foreach ($closedPeriods as $period) {
				if ($period['startDate'] === '' || $period['endDate'] === '') {
					continue;
				}

				$exactMatch = ($period['startDate'] === $columnStart && $period['endDate'] === $columnEnd);
				$contained = ($period['startDate'] <= $columnStart && $columnEnd <= $period['endDate']);
				if ($exactMatch === true || $contained === true) {
					$past[$column['key']] = true;
					break;
				}
			}
		}

		return $past;

	}//end pastColumnKeys()

	/**
	 * Build one column descriptor for a `month`/`quarter`/`year` span
	 * starting at the given 0-based month ordinal (`year*12 + (month-1)`).
	 *
	 * @param integer $startOrdinal The span's first month, as `year*12 + (month-1)`.
	 * @param integer $step Span length in months (1/3/12).
	 * @param string $granularity `month`, `quarter`, or `year`.
	 *
	 * @return array{key:string,label:string,granularity:string,startDate:string,endDate:string,monthKeys:list<string>,isPast:bool,fiscalYears:list<int>}
	 */
	private function buildColumn(int $startOrdinal, int $step, string $granularity): array {
		$monthKeys = [];
		$fiscalYears = [];
		for ($i = 0; $i < $step; $i++) {
			$ordinal = ($startOrdinal + $i);
			$year = intdiv($ordinal, 12);
			$month = (($ordinal % 12) + 1);
			$monthKeys[] = sprintf('%04d-%02d', $year, $month);
			$fiscalYears[$year] = true;
		}

		$firstYear = intdiv($startOrdinal, 12);
		$firstMonth = (($startOrdinal % 12) + 1);
		$lastOrdinal = ($startOrdinal + $step - 1);
		$lastYear = intdiv($lastOrdinal, 12);
		$lastMonth = (($lastOrdinal % 12) + 1);
		$lastDay = (int)date('t', mktime(0, 0, 0, $lastMonth, 1, $lastYear));

		$startDate = sprintf('%04d-%02d-01', $firstYear, $firstMonth);
		$endDate = sprintf('%04d-%02d-%02d', $lastYear, $lastMonth, $lastDay);

		[$key, $label] = match ($granularity) {
			'quarter' => [
				sprintf('%04d-Q%d', $firstYear, intdiv($firstMonth - 1, 3) + 1),
				sprintf('Q%d %04d', intdiv($firstMonth - 1, 3) + 1, $firstYear),
			],
			'year' => [(string)$firstYear, (string)$firstYear],
			default => [
				sprintf('%04d-%02d', $firstYear, $firstMonth),
				date('F Y', mktime(0, 0, 0, $firstMonth, 1, $firstYear)),
			],
		};

		return [
			'key' => $key,
			'label' => $label,
			'granularity' => $granularity,
			'startDate' => $startDate,
			'endDate' => $endDate,
			'monthKeys' => $monthKeys,
			'isPast' => false,
			'fiscalYears' => array_keys($fiscalYears),
		];

	}//end buildColumn()

	/**
	 * Resolve the `isDefault: true` `AnnualBudget` id for every fiscal year
	 * in `$fiscalYears` (design.md §2b). A year with no default (or more
	 * than one, which `AnnualBudgetDefaultGuard` should prevent — the first
	 * match wins defensively) maps to `null`, distinct from `0` — REQ-BGV-001
	 * requires the caller to render an explicit empty/dash state for it.
	 *
	 * @param list<array<string,mixed>> $annualBudgets The administration's AnnualBudget rows.
	 * @param list<int> $fiscalYears The fiscal years to resolve.
	 *
	 * @return array<int,?string> fiscalYear => AnnualBudget id, or null.
	 */
	private function resolveDefaultAnnualBudgets(array $annualBudgets, array $fiscalYears): array {
		$byYear = [];
		foreach ($annualBudgets as $budget) {
			if ((bool)($budget['isDefault'] ?? false) !== true) {
				continue;
			}

			$year = (int)($budget['fiscalYear'] ?? 0);
			if ($year === 0 || isset($byYear[$year]) === true) {
				continue;
			}

			$id = (string)($budget['@self']['id'] ?? $budget['id'] ?? '');
			if ($id === '') {
				continue;
			}

			$byYear[$year] = $id;
		}

		$result = [];
		foreach ($fiscalYears as $year) {
			$result[$year] = ($byYear[$year] ?? null);
		}

		return $result;

	}//end resolveDefaultAnnualBudgets()

	/**
	 * Parse a `YYYY-MM` string into `[year, month]`.
	 *
	 * @param string $value The value to parse.
	 *
	 * @return array{0:int,1:int}|null Null when malformed.
	 */
	private function parseYearMonth(string $value): ?array {
		if (preg_match('/^(\d{4})-(\d{2})$/', $value, $matches) !== 1) {
			return null;
		}

		$month = (int)$matches[2];
		if ($month < 1 || $month > 12) {
			return null;
		}

		return [(int)$matches[1], $month];

	}//end parseYearMonth()

	/**
	 * Run one property-filtered query against the shillinq register.
	 *
	 * A failure is logged and answered as an empty result set: a missing
	 * schema must not stop the grid from rendering whatever it can.
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
				'BudgetGridReader: failed to query OpenRegister',
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
