<?php

/**
 * Contract of TaxReportService
 *
 * The public surface of TaxReportService that other components call, so they depend
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
 * Contract of TaxReportService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TaxReportServiceInterface {

	/**
	 * Roll the four quarters of a fiscal year into an annual summary (REQ-VPB-012).
	 *
	 * @param string $administrationId Administration scope.
	 * @param int $fiscalYear Fiscal year.
	 *
	 * @return array<string,mixed> The annual summary: administrationId,
	 *                             fiscalYear, quarters (4 statements), revenue,
	 *                             operatingExpenses, nonOperating,
	 *                             specialDeductions, netTaxableIncome,
	 *                             untaggedCount and estimatedLiability.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-25
	 */
	public function computeAnnual(string $administrationId, int $fiscalYear): array;

	/**
	 * Compute the quarterly tax statement for an administration + fiscal period.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-VPB-003).
	 * @param int $fiscalYear Fiscal year.
	 * @param int $quarter Quarter (1-4).
	 *
	 * @return array<string,mixed> The quarterly statement: administrationId,
	 *                             fiscalYear, quarter, periodId, revenue,
	 *                             operatingExpenses, nonOperating,
	 *                             specialDeductions, netTaxableIncome,
	 *                             untaggedCount and breakdown.
	 *
	 * @spec openspec/changes/bookkeeping-vpb-corporate-tax/tasks.md#task-22
	 */
	public function computeQuarter(string $administrationId, int $fiscalYear, int $quarter): array;
}//end interface
