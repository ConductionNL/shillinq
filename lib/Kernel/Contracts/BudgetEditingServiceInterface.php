<?php

/**
 * Contract of BudgetEditingService
 *
 * The public surface of BudgetEditingService that other components call, so they depend
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

use OCA\Shillinq\Kernel\Exception\BudgetEditRefusedException;

/**
 * Contract of BudgetEditingService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetEditingServiceInterface {

	/**
	 * The ledger groups of an annual budget with their month amounts.
	 *
	 * @param string $administrationId The administration.
	 * @param string $annualBudgetId   The annual budget.
	 *
	 * @return array{budget:array<string,mixed>,rows:list<array<string,mixed>>}
	 *
	 * @throws BudgetEditRefusedException When the budget is not this administration's.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
	 */
	public function lines(string $administrationId, string $annualBudgetId): array;

	/**
	 * Ledger groups against the fiscal years that have an annual budget, and
	 * every budget of the administration (newest year first) to choose from.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fromYear         The first year shown.
	 *
	 * @return array{years:list<array{fiscalYear:int,annualBudgetId:string,name:string,state:string}>,rows:list<array<string,mixed>>,budgets:list<array<string,mixed>>}
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
	 */
	public function multiYear(string $administrationId, int $fromYear): array;

	/**
	 * Save one month of a ledger group's manual line.
	 *
	 * @param string $administrationId The administration.
	 * @param string $annualBudgetId   The annual budget.
	 * @param string $ledgerGroupId    The ledger group.
	 * @param int    $month            1 to 12.
	 * @param int    $amount           The new amount in cents.
	 * @param int    $expected         The amount in cents the person started from.
	 *
	 * @return array<string,mixed> The row as stored.
	 *
	 * @throws BudgetEditRefusedException When the budget is closed, the line derived or the save stale.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.1
	 */
	public function saveCell(string $administrationId, string $annualBudgetId, string $ledgerGroupId, int $month, int $amount, int $expected): array;

	/**
	 * Spread a yearly amount over twelve months, the last taking the remainder.
	 *
	 * @param string          $administrationId The administration.
	 * @param string          $annualBudgetId   The annual budget.
	 * @param string          $ledgerGroupId    The ledger group.
	 * @param int             $yearly           The yearly amount in cents.
	 * @param array<int,int>  $expected         The twelve amounts the person started from.
	 *
	 * @return array<string,mixed> The row as stored.
	 *
	 * @throws BudgetEditRefusedException When the budget is closed, the line derived or the save stale.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-1.2
	 */
	public function spread(string $administrationId, string $annualBudgetId, string $ledgerGroupId, int $yearly, array $expected): array;

	/**
	 * Start next year's budget from a chosen one, its manual lines raised by a percentage.
	 *
	 * @param string $administrationId The administration.
	 * @param string $annualBudgetId   The budget to start from.
	 * @param float  $percentage       The change in percent, for example 3 or -2.5.
	 *
	 * @return array<string,mixed> The new annual budget.
	 *
	 * @throws BudgetEditRefusedException When next year already has a budget.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-budget-editing/tasks.md#task-2.1
	 */
	public function startNextYear(string $administrationId, string $annualBudgetId, float $percentage): array;
}//end interface
