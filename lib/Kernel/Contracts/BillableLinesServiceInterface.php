<?php

/**
 * Contract of BillableLinesService
 *
 * The public surface of BillableLinesService that other components call, so they depend
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
 * Contract of BillableLinesService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BillableLinesServiceInterface {

	/**
	 * The customer's time entries and passed-on expense claims in the period.
	 *
	 * @param string $administrationId The administration, resolved on the server.
	 * @param string $customerId       The customer.
	 * @param string $projectId        One of the customer's projects, or '' for all of them.
	 * @param string $fromDate         The first day (YYYY-MM-DD), or ''.
	 * @param string $toDate           The last day (YYYY-MM-DD), or ''.
	 *
	 * @return array{timeEntries: list<array<string,mixed>>, expenses: list<array<string,mixed>>}
	 *
	 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
	 */
	public function forCustomer(string $administrationId, string $customerId, string $projectId, string $fromDate, string $toDate): array;

	/**
	 * The ids among those given that are not the customer's billable lines in
	 * the administration: unknown, of another administration, or of another
	 * customer.
	 *
	 * @param string       $administrationId The administration, resolved on the server.
	 * @param string       $customerId       The customer.
	 * @param list<string> $timeEntryIds     Time entry ids.
	 * @param list<string> $expenseIds       Expense claim ids.
	 *
	 * @return list<string> The foreign ids, time entries first.
	 *
	 * @spec openspec/changes/invoice-from-time-and-expense/tasks.md#task-15
	 */
	public function foreignLineIds(string $administrationId, string $customerId, array $timeEntryIds, array $expenseIds): array;
}//end interface
