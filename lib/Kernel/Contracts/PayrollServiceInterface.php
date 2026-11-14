<?php

/**
 * Contract of PayrollService
 *
 * The public surface of PayrollService that other components call, so they depend
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
 * Contract of PayrollService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PayrollServiceInterface {

	/**
	 * Aggregate the period's payslips into an LHAfdracht (REQ-PAY-011).
	 *
	 * Sums loonheffing, employer SV premiums and ZVW across all LoonStrook records
	 * for the period, sets the due date to the last day of the next month, and
	 * leaves the WKR final levy at the value the caller supplies from the WKR app
	 * (default 0). Status starts at VOORBEREID for the SBR app to pick up.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodId Period id.
	 * @param float $finalLeviesWKR WKR final levy from the WKR app (REQ-PAY-011).
	 *
	 * @return array<string,mixed> The LHAfdracht payload.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function berekenLHAfdracht(string $administrationId, string $periodId, float $finalLeviesWKR = 0.0): array;

	/**
	 * Compute a single employee's payslip for a period (REQ-PAY-001, REQ-PAY-010).
	 *
	 * Pure computation: reads the employer, employee and wage-tax table, runs the
	 * calculator, and returns the LoonStrook payload (not persisted). The caller
	 * persists it via persistLoonStrook after closing the period.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $employeeId Employee id.
	 * @param string $periodId Period id.
	 *
	 * @return array<string,mixed> The computed LoonStrook payload.
	 *
	 * @throws \RuntimeException When a required record is missing.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function berekenLoonStrook(string $administrationId, string $employeeId, string $periodId): array;

	/**
	 * Build a balanced GL journal for the period's payroll (REQ-PAY-012).
	 *
	 * Debit lines: gross wages, tax-free allowances, employer social charges,
	 * employer ZVW, employer pension. Credit lines: net payable, wage tax payable,
	 * SV+ZVW payable, pension payable (employer + employee). The journal is
	 * balanced only when sum(debet) == sum(credit) in integer cents; balanced is
	 * set accordingly and an unbalanced journal is refused by
	 * persistLoonjournaalpost.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $periodId Period id.
	 *
	 * @return array<string,mixed> The Loonjournaalpost payload.
	 *
	 * @spec openspec/changes/bookkeeping-payroll-engine-nl/tasks.md
	 */
	public function bouwLoonjournaalpost(string $administrationId, string $periodId): array;
}//end interface
