<?php

/**
 * Budget Grid Calculator
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The arithmetic half of the begroting grid (`budget-grid-view`,
 * REQ-BGV-003/004/005/008). No OpenRegister calls — every input is a
 * {@see BudgetGridReader::loadGrid()} bundle plus
 * `budget-core-schema`'s own {@see BudgetVsActualsCalculator}, mirroring
 * that class's own reader/calculator split.
 *
 * ## The sign convention (design.md §2d) — the task brief's own explicit
 * warning: "getting this wrong inverts the whole screen"
 *
 * Deviation is resolved per `LedgerGroup`, from that group's OWN resolved
 * member accounts' `accountType` (never a `LedgerGroup`-level field —
 * `LedgerGroup` carries none):
 *
 *  - `revenue`: `actual − budget`. Positive = favorable (exceeded budget).
 *  - `expenses`: `budget − actual`. Positive = favorable (under budget).
 *  - `assets`/`liabilities`/`equity`/mixed/unresolved: the raw
 *    `actual − budget` difference is still computed, but no
 *    favorable/unfavorable framing is applied (§9.1's open question — a
 *    balance-sheet stock, or a genuinely mixed-type group, does not carry
 *    the same directional semantics a `BudgetLine`'s monthly-phased amount
 *    implies for a P&L flow).
 *
 * A parent `LedgerGroup`'s deviation is the sum of its children's own
 * already-correctly-signed deviations when it has no own resolved accounts
 * (the common case — every seeded parent is a pure roll-up); when a
 * `LedgerGroup` DOES have its own resolved member accounts (a leaf, or the
 * rare "both children and own accounts" case flagged as moot for day-one
 * data by design.md §9.2), its own portion is signed from its own accounts'
 * derived type and then combined with any children's own deviations.
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
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-004
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure begroting-grid arithmetic: per-column deviation with the
 * accountType-driven sign convention, the TOTAAL cumulative pair, and the
 * computed-row formula evaluator.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-004
 */
class BudgetGridCalculator {

	/**
	 * Construct the calculator.
	 *
	 * @param BudgetVsActualsCalculator $inner The `budget-core-schema` arithmetic delegate.
	 */
	public function __construct(private readonly BudgetVsActualsCalculator $inner) {
	}//end __construct()

	/**
	 * Evaluate one `LedgerGroup` row for one column (a set of calendar
	 * months, e.g. one month, one quarter's three months, or one year's
	 * twelve — design.md §2a), applying the REQ-BGV-004 sign convention.
	 *
	 * @param string $ledgerGroupKey The row's id or slug.
	 * @param array{monthKeys:list<string>,fiscalYears:list<int>} $column The column descriptor (from {@see BudgetGridReader::columnsFor()}).
	 * @param boolean $isPast Whether this column is past (REQ-BGV-003) — actuals only render for past columns.
	 * @param array<string,mixed> $bvaContext The {@see BudgetVsActualsReader::loadContext()} bundle.
	 * @param array<int,list<array<string,mixed>>|null> $budgetLinesByFiscalYear Per-fiscal-year BudgetLine slices;
	 *                                                                           `null` = no default AnnualBudget for that year.
	 * @param array<string,string> $accountTypeByNumber Account number => accountType.
	 *
	 * @return array{budget:?int,actual:?int,deviation:?int,favorable:?bool,hasBudget:bool}
	 *
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-003
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-004
	 */
	public function evaluateColumn(
		string $ledgerGroupKey,
		array $column,
		bool $isPast,
		array $bvaContext,
		array $budgetLinesByFiscalYear,
		array $accountTypeByNumber
	): array {
		$run = new BudgetGridCellRun(
			ledgerGroupKey: $ledgerGroupKey,
			bvaContext: $bvaContext,
			budgetLinesByFiscalYear: $budgetLinesByFiscalYear,
			accountTypeByNumber: $accountTypeByNumber
		);
		$budget = $this->budgetTotal(monthKeys: $column['monthKeys'], run: $run);

		$actualTotal = null;
		$sign = ['deviation' => null, 'favorable' => null];
		if ($isPast === true) {
			$actualTotal = $this->actualTotal(monthKeys: $column['monthKeys'], run: $run);
			$sign = $this->signFor(totals: ['budget' => $budget, 'actual' => $actualTotal], run: $run);
		}

		return [
			'budget' => $budget,
			'actual' => $actualTotal,
			'deviation' => $sign['deviation'],
			'favorable' => $sign['favorable'],
			'hasBudget' => ($budget !== null),
		];

	}//end evaluateColumn()

	/**
	 * Resolve the TOTAAL cumulative pair (design.md §3 / REQ-BGV-005): the
	 * begroot cumulative sums EVERY displayed column's budget unconditionally
	 * (future months included); the werkelijk cumulative sums only the
	 * columns that are past.
	 *
	 * @param string $ledgerGroupKey The row's id or slug.
	 * @param list<array{monthKeys:list<string>,isPast:bool}> $columns The already-generated, isPast-flagged columns.
	 * @param array<string,mixed> $bvaContext The BudgetVsActualsReader context bundle.
	 * @param array<int,list<array<string,mixed>>|null> $budgetLinesByFiscalYear Per-fiscal-year BudgetLine slices.
	 * @param array<string,string> $accountTypeByNumber Account number => accountType.
	 *
	 * @return array{budget:?int,actual:int,deviation:?int,favorable:?bool}
	 *
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-005
	 */
	public function cumulative(
		string $ledgerGroupKey,
		array $columns,
		array $bvaContext,
		array $budgetLinesByFiscalYear,
		array $accountTypeByNumber
	): array {
		$run = new BudgetGridCellRun(
			ledgerGroupKey: $ledgerGroupKey,
			bvaContext: $bvaContext,
			budgetLinesByFiscalYear: $budgetLinesByFiscalYear,
			accountTypeByNumber: $accountTypeByNumber
		);
		$monthKeys = self::columnMonthKeys(columns: $columns);
		$budget = $this->budgetTotal(monthKeys: $monthKeys['all'], run: $run);
		$actualTotal = $this->actualTotal(monthKeys: $monthKeys['past'], run: $run);
		$sign = $this->signFor(totals: ['budget' => $budget, 'actual' => $actualTotal], run: $run);

		return ['budget' => $budget, 'actual' => $actualTotal, 'deviation' => $sign['deviation'], 'favorable' => $sign['favorable']];

	}//end cumulative()

	/**
	 * Every month key of the columns, and those of the past columns, in order.
	 *
	 * @param array<int,array<string,mixed>> $columns The columns.
	 *
	 * @return array{all: list<string>, past: list<string>}
	 */
	private static function columnMonthKeys(array $columns): array {
		$keys = ['all' => [], 'past' => []];
		foreach ($columns as $column) {
			foreach ($column['monthKeys'] as $monthKey) {
				$keys['all'][] = $monthKey;
				if ($column['isPast'] === true) {
					$keys['past'][] = $monthKey;
				}
			}
		}

		return $keys;

	}//end columnMonthKeys()

	/**
	 * The budgeted total over the months, or null when no month's fiscal year
	 * has a default AnnualBudget (§2b: an explicit empty state, not zero).
	 *
	 * @param list<string>      $monthKeys The months (`yyyy-mm`).
	 * @param BudgetGridCellRun $run       The row's inputs.
	 *
	 * @return int|null
	 */
	private function budgetTotal(array $monthKeys, BudgetGridCellRun $run): ?int {
		$total = null;
		foreach ($monthKeys as $monthKey) {
			[$year, $monthNumber] = $this->splitMonthKey(monthKey: $monthKey);
			$lines = ($run->budgetLinesByFiscalYear[$year] ?? null);
			if ($lines !== null) {
				$total = (($total ?? 0) + $this->budgetedAmountForYear(
					ledgerGroupKey: $run->ledgerGroupKey,
					monthNumber: $monthNumber,
					budgetLines: $lines,
					bvaContext: $run->bvaContext
				));
			}
		}

		return $total;

	}//end budgetTotal()

	/**
	 * The actual total over the months.
	 *
	 * @param list<string>      $monthKeys The months (`yyyy-mm`).
	 * @param BudgetGridCellRun $run       The row's inputs.
	 *
	 * @return int
	 */
	private function actualTotal(array $monthKeys, BudgetGridCellRun $run): int {
		$total = 0;
		foreach ($monthKeys as $monthKey) {
			$total += $this->inner->actualAmount(ledgerGroupKey: $run->ledgerGroupKey, monthKey: $monthKey, context: $run->bvaContext);
		}

		return $total;

	}//end actualTotal()

	/**
	 * The deviation and its favourability, or both null without a budget.
	 *
	 * @param array{budget: int|null, actual: int} $totals The budget and actual totals.
	 * @param BudgetGridCellRun                    $run    The row's inputs.
	 *
	 * @return array{deviation: mixed, favorable: mixed}
	 */
	private function signFor(array $totals, BudgetGridCellRun $run): array {
		if ($totals['budget'] === null) {
			return ['deviation' => null, 'favorable' => null];
		}

		$sign = $this->deviationFor(
			ledgerGroupKey: $run->ledgerGroupKey,
			bvaContext: $run->bvaContext,
			accountTypeByNumber: $run->accountTypeByNumber,
			budget: $totals['budget'],
			actual: $totals['actual']
		);
		return ['deviation' => $sign['deviation'], 'favorable' => $sign['favorable']];

	}//end signFor()

	/**
	 * Evaluate a page-config `computedRows` waterfall (design.md §4 /
	 * REQ-BGV-008): each entry's `formula` is `<code> [+|-] <code> …` over a
	 * single flat codespace where an operand resolves to either a root
	 * `LedgerGroup`'s own `code` (looked up in `$rowValuesByCode`) or another
	 * `computedRows` entry's own `code` (resolved in declaration order, so a
	 * formula may reference an earlier computed row).
	 *
	 * @param list<array{code:string,label:string,formula:string,favorableDirection?:string,asPercent?:bool}> $computedRows The page-config definitions.
	 * @param array<string,int|float|null> $rowValuesByCode Root `LedgerGroup` `code` => this column's numeric value.
	 *
	 * @return array<string,int|float|null> computedRow `code` => its evaluated value for this column.
	 *
	 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-008
	 */
	public function evaluateComputedRows(array $computedRows, array $rowValuesByCode): array {
		$values = $rowValuesByCode;
		$results = [];

		foreach ($computedRows as $row) {
			$code = (string)($row['code'] ?? '');
			if ($code === '') {
				continue;
			}

			$value = $this->evaluateFormula(row: $row, values: $values);
			$values[$code] = $value;
			$results[$code] = $value;
		}

		return $results;

	}//end evaluateComputedRows()

	/**
	 * Evaluate one computed row's `<code> [+|-] <code> …` (or, for a percent
	 * row, `<code> / <code>`) formula against the already-resolved value map.
	 *
	 * @param array{formula?:string,asPercent?:bool} $row    The computed-row definition.
	 * @param array<string,int|float|null>           $values code => value (root LedgerGroup codes + earlier computedRows).
	 *
	 * @return int|float|null Null when any operand is unresolved (missing code, or a null budget-year gap).
	 */
	private function evaluateFormula(array $row, array $values): int|float|null {
		$formula = trim((string)($row['formula'] ?? ''));
		if ($formula === '') {
			return null;
		}

		if ((bool)($row['asPercent'] ?? false) === true) {
			return $this->ratio(formula: $formula, values: $values);
		}

		return $this->signedSum(formula: $formula, values: $values);

	}//end evaluateFormula()

	/**
	 * A single `a / b` percent formula; null when it is not two operands, an
	 * operand is unresolved, or the divisor is the integer 0.
	 *
	 * @param string                       $formula The trimmed formula.
	 * @param array<string,int|float|null> $values  code => value.
	 *
	 * @return int|float|null The ratio, or null.
	 */
	private function ratio(string $formula, array $values): int|float|null {
		$parts = array_map('trim', explode('/', $formula, 2));
		if (count($parts) !== 2) {
			return null;
		}

		$numerator = ($values[$parts[0]] ?? null);
		$denominator = ($values[$parts[1]] ?? null);
		if ($numerator === null || $denominator === null || $denominator === 0) {
			return null;
		}

		return ($numerator / $denominator);

	}//end ratio()

	/**
	 * A `<code> [+|-] <code> …` chain; null when any term is empty or
	 * unresolved.
	 *
	 * @param string                       $formula The trimmed formula.
	 * @param array<string,int|float|null> $values  code => value.
	 *
	 * @return int|float|null The sum, or null.
	 */
	private function signedSum(string $formula, array $values): int|float|null {
		$parts = $this->formulaTerms(formula: $formula);
		if ($parts === null) {
			return null;
		}

		$sum = 0;
		$termSign = 1;
		foreach ($parts as $index => $part) {
			// Odd indices are the captured operator delimiters.
			if ($index % 2 === 1) {
				$termSign = $this->operatorSign(operator: $part);
				continue;
			}

			$operand = $this->operandValue(term: $part, values: $values);
			if ($operand === null) {
				return null;
			}

			$sum += ($termSign * $operand);
		}

		return $sum;

	}//end signedSum()

	/**
	 * Split a chain into terms and the operators between them.
	 *
	 * Split on a `+`/`-` operator that is surrounded by whitespace, keeping
	 * the operator itself as a delimiter capture — NOT a character-class
	 * token scan, because a `code` itself is a slug that may contain internal
	 * hyphens (e.g. `kostprijs-van-de-omzet`); only an operator with space on
	 * both sides is unambiguously the `+`/`-` of the grammar, never part of a
	 * code.
	 *
	 * @param string $formula The trimmed formula.
	 *
	 * @return list<string>|null Terms at even indices, operators at odd ones; null when unsplittable.
	 */
	private function formulaTerms(string $formula): array|null {
		$parts = preg_split('/\s+([+-])\s+/', $formula, -1, PREG_SPLIT_DELIM_CAPTURE);
		if ($parts === false || $parts === []) {
			return null;
		}

		return $parts;

	}//end formulaTerms()

	/**
	 * The sign an operator applies to the next term.
	 *
	 * @param string $operator `+` or `-`.
	 *
	 * @return int 1 or -1.
	 */
	private function operatorSign(string $operator): int {
		if ($operator === '-') {
			return -1;
		}

		return 1;

	}//end operatorSign()

	/**
	 * The value of one term, or null when the term is empty or unresolved.
	 *
	 * @param string                       $term   One term of the chain.
	 * @param array<string,int|float|null> $values code => value.
	 *
	 * @return int|float|null The value, or null.
	 */
	private function operandValue(string $term, array $values): int|float|null {
		$code = trim($term);
		if ($code === '') {
			return null;
		}

		return ($values[$code] ?? null);

	}//end operandValue()

	/**
	 * Resolve one `LedgerGroup`'s deviation from a raw (already summed)
	 * budget/actual pair, using the accountType-driven sign convention
	 * derived from the group's OWN resolved member accounts (design.md §2d).
	 * A parent with no own accounts inherits the sign of whichever single
	 * accountType its descendant leaves share (the RJ270-seeded case — every
	 * root's own subtree is type-homogeneous); a genuinely mixed subtree
	 * renders the raw difference with no favorable/unfavorable framing.
	 *
	 * @param string $ledgerGroupKey The row's id or slug.
	 * @param array<string,mixed> $bvaContext The BudgetVsActualsReader context bundle.
	 * @param array<string,string> $accountTypeByNumber Account number => accountType.
	 * @param integer $budget The already-summed raw budget amount.
	 * @param integer $actual The already-summed raw actual amount.
	 *
	 * @return array{deviation:int,favorable:?bool}
	 */
	private function deviationFor(
		string $ledgerGroupKey,
		array $bvaContext,
		array $accountTypeByNumber,
		int $budget,
		int $actual
	): array {
		$accountType = $this->resolveSubtreeAccountType(
			ledgerGroupKey: $ledgerGroupKey,
			bvaContext: $bvaContext,
			accountTypeByNumber: $accountTypeByNumber
		);

		return match ($accountType) {
			'revenue' => ['deviation' => ($actual - $budget), 'favorable' => (($actual - $budget) >= 0)],
			'expenses' => ['deviation' => ($budget - $actual), 'favorable' => (($budget - $actual) >= 0)],
			default => ['deviation' => ($actual - $budget), 'favorable' => null],
		};

	}//end deviationFor()

	/**
	 * Resolve the single shared `accountType` of every resolved member
	 * account in a `LedgerGroup`'s own subtree (itself plus every
	 * descendant), or `null` when the subtree resolves no accounts at all or
	 * mixes more than one type.
	 *
	 * @param string $ledgerGroupKey The row's id or slug.
	 * @param array<string,mixed> $bvaContext The BudgetVsActualsReader context bundle.
	 * @param array<string,string> $accountTypeByNumber Account number => accountType.
	 *
	 * @return string|null The shared accountType, or null when absent/mixed.
	 */
	private function resolveSubtreeAccountType(string $ledgerGroupKey, array $bvaContext, array $accountTypeByNumber): ?string {
		$types = [];
		$this->collectSubtreeAccountTypes(
			ledgerGroupKey: $ledgerGroupKey,
			bvaContext: $bvaContext,
			accountTypeByNumber: $accountTypeByNumber,
			types: $types
		);

		$distinct = array_keys($types);
		if (count($distinct) !== 1) {
			return null;
		}

		return $distinct[0];

	}//end resolveSubtreeAccountType()

	/**
	 * Recursively collect every distinct `accountType` found among a
	 * `LedgerGroup`'s own resolved member accounts and its descendants'.
	 *
	 * @param string $ledgerGroupKey The row's id or slug.
	 * @param array<string,mixed> $bvaContext The BudgetVsActualsReader context bundle.
	 * @param array<string,string> $accountTypeByNumber Account number => accountType.
	 * @param array<string,bool> $types Accumulator, passed by reference.
	 *
	 * @return void
	 */
	private function collectSubtreeAccountTypes(string $ledgerGroupKey, array $bvaContext, array $accountTypeByNumber, array &$types): void {
		$index = ($bvaContext['ledgerGroupKeyToIndex'][$ledgerGroupKey] ?? null);
		if ($index === null) {
			return;
		}

		$entry = ($bvaContext['ledgerGroupEntries'][$index] ?? null);
		foreach (($entry['memberAccountNumbers'] ?? []) as $accountNumber) {
			$type = ($accountTypeByNumber[$accountNumber] ?? null);
			if ($type !== null) {
				$types[$type] = true;
			}
		}

		foreach (($bvaContext['ledgerGroupChildrenByIndex'][$index] ?? []) as $childIndex) {
			$childEntry = ($bvaContext['ledgerGroupEntries'][$childIndex] ?? null);
			if ($childEntry === null) {
				continue;
			}

			$childKey = $childEntry['slug'];
			if ($childEntry['id'] !== '') {
				$childKey = $childEntry['id'];
			}
			$this->collectSubtreeAccountTypes(
				ledgerGroupKey: $childKey,
				bvaContext: $bvaContext,
				accountTypeByNumber: $accountTypeByNumber,
				types: $types
			);
		}

	}//end collectSubtreeAccountTypes()

	/**
	 * Resolve a `LedgerGroup`'s budgeted amount for one calendar month
	 * within one specific fiscal year's `BudgetLine` slice — the
	 * fiscal-year-crossing-safe equivalent of
	 * {@see BudgetVsActualsCalculator::budgetedAmount()}, which assumes a
	 * single shared `budgetLines` list and would silently pick whichever
	 * fiscal year's line for this `LedgerGroup` happens to appear first when
	 * a range spans more than one year (design.md §2b).
	 *
	 * @param string $ledgerGroupKey The row's id or slug.
	 * @param integer $monthNumber The calendar month, 1-12.
	 * @param list<array<string,mixed>> $budgetLines This fiscal year's own BudgetLine rows only.
	 * @param array<string,mixed> $bvaContext The BudgetVsActualsReader context bundle (for ledgerGroupKeyToIndex/childrenByIndex).
	 *
	 * @return integer The budgeted amount, in EUR cents.
	 */
	private function budgetedAmountForYear(string $ledgerGroupKey, int $monthNumber, array $budgetLines, array $bvaContext): int {
		$scopedContext = $bvaContext;
		$scopedContext['budgetLines'] = $budgetLines;

		return $this->inner->budgetedAmount(ledgerGroupKey: $ledgerGroupKey, monthNumber: $monthNumber, context: $scopedContext);

	}//end budgetedAmountForYear()

	/**
	 * Split a `YYYY-MM` month key into `[year, monthNumber]`.
	 *
	 * @param string $monthKey The `YYYY-MM` key.
	 *
	 * @return array{0:int,1:int}
	 */
	private function splitMonthKey(string $monthKey): array {
		$year = (int)substr($monthKey, 0, 4);
		$month = (int)substr($monthKey, 5, 2);

		return [$year, $month];

	}//end splitMonthKey()
}//end class
