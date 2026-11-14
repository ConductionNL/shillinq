<?php

/**
 * Contract of BudgetGridReader
 *
 * The public surface of BudgetGridReader that other components call, so they depend
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
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of BudgetGridReader.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetGridReaderInterface {

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
	public function loadGrid(string $administrationId, string $startPeriod, string $endPeriod, string $granularity): array;
}//end interface
