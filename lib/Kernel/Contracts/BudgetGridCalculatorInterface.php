<?php

/**
 * Contract of BudgetGridCalculator
 *
 * The public surface of BudgetGridCalculator that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of BudgetGridCalculator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetGridCalculatorInterface {

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
	): array;

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
	): array;

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
	public function evaluateComputedRows(array $computedRows, array $rowValuesByCode): array;
}//end interface
