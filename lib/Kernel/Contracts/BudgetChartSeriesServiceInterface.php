<?php

/**
 * Contract of BudgetChartSeriesService
 *
 * The public surface of BudgetChartSeriesService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of BudgetChartSeriesService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BudgetChartSeriesServiceInterface {

	/**
	 * Resolve the trend+cumulative actual/projected/begroot envelope for
	 * every in-scope `Account` and `LedgerGroup` in one administration
	 * (REQ-BCH-003, REQ-BCH-008).
	 *
	 * @param string $administrationId The administration to scope every read to.
	 * @param string $from The first `YYYY-MM` month, inclusive.
	 * @param string $to The last `YYYY-MM` month, inclusive.
	 * @param string|null $annualBudgetId Explicit `AnnualBudget` override; null resolves each fiscal year's `isDefault` budget.
	 *
	 * @return array{
	 *     months: list<string>,
	 *     accounts: list<array<string,mixed>>,
	 *     ledgerGroups: list<array<string,mixed>>,
	 * } The response envelope `BudgetChartsController::series()` returns verbatim.
	 *
	 * @throws InvalidArgumentException When `$from`/`$to` are malformed or `$from` is after `$to`.
	 *
	 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
	 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-008
	 */
	public function resolveSeries(string $administrationId, string $from, string $to, ?string $annualBudgetId = null): array;
}//end interface
