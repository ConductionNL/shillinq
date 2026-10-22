<?php

/**
 * Budget Grid Scenario Overlay
 *
 * Swaps the begroting grid's budget column to one scenario's figures
 * (Q-shillinq-1, answered 9 Oct): for every fiscal year in view that has a
 * default AnnualBudget, the year's real BudgetLines plus the scenario's own
 * modifiers are run through {@see BudgetScenarioEvaluator} (the same
 * arithmetic the standalone comparison page shows), and the year's
 * BudgetLine slice is replaced by one synthetic line per LedgerGroup that
 * carries the evaluator's resolved scenario amount for each month.
 *
 * Read-only and non-destructive: nothing is written, the synthetic lines
 * exist only inside one grid response. A year without a default
 * AnnualBudget stays empty (a scenario is base plus modifiers; with no base
 * there is nothing to modify).
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
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-011
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Pure overlay of one scenario onto the grid's per-year BudgetLine slices.
 *
 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-011
 */
class BudgetGridScenarioOverlay {

	/**
	 * Construct the overlay.
	 *
	 * @param BudgetScenarioEvaluator $evaluator The base-vs-scenario evaluator.
	 */
	public function __construct(private readonly BudgetScenarioEvaluator $evaluator) {
	}//end __construct()

	/**
	 * Find one scenario in a {@see BudgetScenarioReader::loadContext()} bundle.
	 *
	 * The bundle is already scoped to one administration, so a scenario of
	 * another administration is simply absent (the caller answers 404).
	 *
	 * @param array<string,mixed> $scenarioContext The reader's context bundle.
	 * @param string $scenarioId The BudgetScenario id.
	 *
	 * @return array<string,mixed>|null The scenario row, or null when it is not in the bundle.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-011
	 */
	public function findScenario(array $scenarioContext, string $scenarioId): ?array {
		foreach (($scenarioContext['scenarios'] ?? []) as $scenario) {
			$id = (string)($scenario['id'] ?? $scenario['@self']['id'] ?? '');
			if ($id !== '' && $id === $scenarioId) {
				return $scenario;
			}
		}

		return null;

	}//end findScenario()

	/**
	 * Replace every fiscal year's BudgetLine slice by the scenario's
	 * resolved figures.
	 *
	 * @param array<int,list<array<string,mixed>>|null> $budgetLinesByFiscalYear The grid's per-year slices; null = no default AnnualBudget.
	 * @param array<string,mixed> $scenarioContext The {@see BudgetScenarioReader::loadContext()} bundle.
	 * @param string $scenarioId The BudgetScenario id to overlay.
	 *
	 * @return array<int,list<array<string,mixed>>|null> The swapped slices, same keys.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-011
	 */
	public function apply(array $budgetLinesByFiscalYear, array $scenarioContext, string $scenarioId): array {
		$modifiers = ($scenarioContext['modifiersByScenarioId'][$scenarioId] ?? []);
		$ledgerGroups = ($scenarioContext['ledgerGroups'] ?? []);
		$recurring = ($scenarioContext['cashflowRecurringRows'] ?? []);

		$swapped = [];
		foreach ($budgetLinesByFiscalYear as $fiscalYear => $lines) {
			if ($lines === null) {
				$swapped[$fiscalYear] = null;
				continue;
			}

			$cells = $this->evaluator->evaluate(
				baseBudgetLines: $lines,
				ledgerGroups: $ledgerGroups,
				cashflowRecurringRows: $recurring,
				modifiers: $modifiers,
				fiscalYear: (int)$fiscalYear
			);

			$swapped[$fiscalYear] = $this->linesFromCells(cells: $cells);
		}

		return $swapped;

	}//end apply()

	/**
	 * Fold the evaluator's `(ledgerGroupId, month)` cells into one synthetic
	 * BudgetLine per LedgerGroup carrying the resolved scenario amounts.
	 *
	 * Every group gets its own line holding its RESOLVED amount (own value or
	 * children rollup, as the evaluator decided), so the grid's own
	 * "own line, else children" rule reads the same number back for every row.
	 *
	 * @param array<string,array{month:string,ledgerGroupId:string,base:int,scenario:int,delta:int}> $cells The evaluator output.
	 *
	 * @return list<array<string,mixed>> The synthetic BudgetLine rows.
	 */
	private function linesFromCells(array $cells): array {
		$byGroup = [];
		foreach ($cells as $cell) {
			$groupId = $cell['ledgerGroupId'];
			$field = 'month' . substr($cell['month'], 5, 2) . 'Amount';
			if (isset($byGroup[$groupId]) === false) {
				$byGroup[$groupId] = ['ledgerGroupId' => $groupId, 'source' => 'scenario'];
			}

			$byGroup[$groupId][$field] = $cell['scenario'];
		}

		return array_values($byGroup);

	}//end linesFromCells()
}//end class
