<?php

/**
 * Contract of FinancialDashboardService
 *
 * The public surface of FinancialDashboardService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of FinancialDashboardService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FinancialDashboardServiceInterface {

	/**
	 * The cash position per bank account and combined, for one administration (REQ-BCON-004).
	 *
	 * @param string $administrationId The administration to scope every record to.
	 *
	 * @return array{accounts:array<int,array<string,mixed>>,other:float,total:float}
	 *
	 * @spec openspec/specs/bookkeeping-treasury-ihb/spec.md
	 */
	public function cashPosition(string $administrationId): array;

	/**
	 * Monthly financial series over the resolved month window: turnover,
	 * costs, margin (EUR + %), billable vs non-billable hours, billable
	 * percentage and realized cashflow, one entry per month key.
	 *
	 * @param string|null $from Optional ISO-8601 lower bound.
	 * @param string|null $to Optional ISO-8601 upper bound.
	 * @param DateTimeImmutable|null $now Reference date (defaults to the current time).
	 *
	 * @return array<string,mixed> The series payload (months + nine parallel arrays).
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function series(?string $from, ?string $to, ?DateTimeImmutable $now = null): array;

	/**
	 * KPI summary over the resolved month window with previous-period trend
	 * values. The range-driven metrics (turnover, margin + marginPct,
	 * billableHours + billablePct) are computed for the current window AND
	 * for the window of the same length immediately before it. The
	 * point-in-time metrics (openDebtors, openCreditors, cashPosition
	 * all-time) do not vary by range — matching the client's
	 * computeKpis/computeRangeKpis split — so they appear under `current`
	 * only.
	 *
	 * @param string|null $from Optional ISO-8601 lower bound.
	 * @param string|null $to Optional ISO-8601 upper bound.
	 * @param DateTimeImmutable|null $now Reference date (defaults to the current time).
	 *
	 * @return array<string,mixed> The summary payload (months, previousMonths, current, previousPeriod).
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function summary(?string $from, ?string $to, ?DateTimeImmutable $now = null): array;

	/**
	 * The figures the Nextcloud dashboard widgets show, for one administration
	 * (reporting-custom-analysis REQ-RCA-001). Computed with the same
	 * calculator as the shillinq dashboard, so the numbers match.
	 *
	 * @param string            $administrationId The administration to scope every record to.
	 * @param DateTimeImmutable $now              The reference date.
	 *
	 * @return array<string,mixed> revenue, receivables, cashPosition, resultByMonth, topCustomers.
	 *
	 * @spec openspec/specs/financial-dashboard-graphs/spec.md
	 */
	public function widgetFigures(string $administrationId, DateTimeImmutable $now): array;
}//end interface
