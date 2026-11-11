<?php

/**
 * Budget Grid Run
 *
 * The grid one budget grid response is built from.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * The parts of a loaded budget grid that every row of the response reads.
 *
 * @spec openspec/changes/budget-grid-view/specs/budget-grid-view/spec.md#req-bgv-002
 */
final class BudgetGridRun {
	/**
	 * The period columns.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public readonly array $columns;

	/**
	 * The ledger-group tree: entries, childrenByIndex, rootIndexes.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $rowTree;

	/**
	 * Actuals and ledger-group membership.
	 *
	 * @var array<string,mixed>
	 */
	public readonly array $bvaContext;

	/**
	 * Budget lines per fiscal year.
	 *
	 * @var array<int|string,array<int,array<string,mixed>>>
	 */
	public readonly array $budgetLinesByFiscalYear;

	/**
	 * Account type per account number.
	 *
	 * @var array<int|string,string>
	 */
	public readonly array $accountTypeByNumber;

	/**
	 * Account per account number.
	 *
	 * @var array<int|string,array<string,mixed>>
	 */
	public readonly array $accountByNumber;

	/**
	 * Take the parts of the grid.
	 *
	 * @param array<string,mixed> $grid The grid BudgetGridReader::loadGrid() returns.
	 */
	public function __construct(array $grid) {
		$this->columns = $grid['columns'];
		$this->rowTree = $grid['rowTree'];
		$this->bvaContext = $grid['bvaContext'];
		$this->budgetLinesByFiscalYear = $grid['budgetLinesByFiscalYear'];
		$this->accountTypeByNumber = $grid['accountTypeByNumber'];
		$this->accountByNumber = $grid['accountByNumber'];

	}//end __construct()
}//end class
