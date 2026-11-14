<?php

/**
 * Contract of BudgetScenarioEvaluator
 *
 * The public surface of BudgetScenarioEvaluator that other components call, so they depend
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
 * Contract of BudgetScenarioEvaluator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetScenarioEvaluatorInterface {

	/**
	 * Evaluate one scenario: base vs. scenario vs. delta, per
	 * `(ledgerGroupId, month)` cell, for one fiscal year.
	 *
	 * @param list<array<string,mixed>> $baseBudgetLines Real BudgetLine rows already scoped to the AnnualBudget(s) in view.
	 * @param list<array<string,mixed>> $ledgerGroups Real LedgerGroup rows for the administration.
	 * @param list<array<string,mixed>> $cashflowRecurringRows Real CashflowRecurring rows for the administration.
	 * @param list<array<string,mixed>> $modifiers This scenario's own BudgetScenarioModifier rows.
	 * @param int $fiscalYear The fiscal year to evaluate.
	 *
	 * @return array<string,array{month:string,ledgerGroupId:string,base:int,scenario:int,delta:int}> Keyed by `"{ledgerGroupId}:{YYYY-MM}"`.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-005
	 */
	public function evaluate(
		array $baseBudgetLines,
		array $ledgerGroups,
		array $cashflowRecurringRows,
		array $modifiers,
		int $fiscalYear
	): array;
}//end interface
