<?php

/**
 * Contract of BudgetScenarioReader
 *
 * The public surface of BudgetScenarioReader that other components call, so they depend
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
 * Contract of BudgetScenarioReader.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetScenarioReaderInterface {

	/**
	 * Load every read `BudgetScenarioEvaluator` needs for one
	 * `administrationId`, batched to exactly 5 `findAll()` calls total.
	 *
	 * @param string $administrationId The administration to scope every read to.
	 * @param list<string> $annualBudgetIds The AnnualBudget ids to load BudgetLines for; empty = none loaded.
	 *
	 * @return array{
	 *     scenarios: list<array<string,mixed>>,
	 *     modifiersByScenarioId: array<string,list<array<string,mixed>>>,
	 *     cashflowRecurringRows: list<array<string,mixed>>,
	 *     budgetLines: list<array<string,mixed>>,
	 *     ledgerGroups: list<array<string,mixed>>,
	 * } The assembled context {@see BudgetScenarioEvaluator} consumes.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-007
	 */
	public function loadContext(string $administrationId, array $annualBudgetIds): array;

	/**
	 * Resolve every AnnualBudget id for one administration + fiscal year —
	 * a helper for `BudgetScenarioController::evaluate()` to build the
	 * `$annualBudgetIds` argument `loadContext()` needs. A SEPARATE query
	 * from `loadContext()`'s own 5-call budget (REQ-BSC-007 scopes that
	 * bound to the reads `BudgetScenarioEvaluator` itself consumes, not to
	 * resolving which AnnualBudget ids are in scope in the first place).
	 *
	 * @param string $administrationId The administration to scope the read to.
	 * @param int $fiscalYear The fiscal year to scope the read to.
	 *
	 * @return list<string> The matching AnnualBudget ids.
	 *
	 * @spec openspec/changes/budget-scenarios/specs/budget-scenarios/spec.md#req-bsc-008
	 */
	public function resolveAnnualBudgetIds(string $administrationId, int $fiscalYear): array;
}//end interface
