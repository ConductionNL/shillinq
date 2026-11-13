<?php

/**
 * Billable Hours Source
 *
 * Where the invoice generator reads the hours worked on a project that are
 * not yet billed (sales-time-and-expense-billing D1, REQ-STEB-001). Hours are
 * kept in another app (humaniq); shillinq only reads them. Without a bound
 * source the answer says the hours are unavailable, so the page says so
 * instead of showing a project with no hours.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Billing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.1
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Billing;

/**
 * Reads the unbilled hours of a project.
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.1
 */
interface BillableHoursSource {

	/**
	 * The hours on a project between two dates that are not yet billed.
	 *
	 * `available` false means the hours could not be read at all, with the
	 * reason; it never means "no hours". Each entry carries the hours'
	 * `id`, `person`, `date` (`Y-m-d`), `hours` and `description`.
	 *
	 * @param string $projectId The project.
	 * @param string $from      The first day, `Y-m-d`, inclusive.
	 * @param string $to        The last day, `Y-m-d`, inclusive.
	 *
	 * @return array{available: bool, reason: string, hours: list<array{id: string, person: string, date: string, hours: float, description: string}>}
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.1
	 */
	public function unbilled(string $projectId, string $from, string $to): array;
}//end interface
