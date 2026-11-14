<?php

/**
 * Unavailable Billable Hours
 *
 * The source bound while no app that keeps hours is connected
 * (sales-time-and-expense-billing task 2.1): it reads nothing and says the
 * hours are unavailable, so an invoice is never drawn up as if the project
 * had no hours.
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
 * Answers every request with "hours unavailable".
 *
 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.1
 */
class UnavailableBillableHours implements BillableHoursSource {

	/**
	 * The reason given while no hours app is connected.
	 */
	public const REASON = 'no-hours-source';

	/**
	 * No hours, and the reason why.
	 *
	 * @param string $projectId The project.
	 * @param string $from      The first day.
	 * @param string $to        The last day.
	 *
	 * @return array{available: bool, reason: string, hours: list<array{id: string, person: string, date: string, hours: float, description: string}>}
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The interface's arguments; this source reads nothing.
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.1
	 */
	public function unbilled(string $projectId, string $from, string $to): array {
		return ['available' => false, 'reason' => self::REASON, 'hours' => []];
	}//end unbilled()
}//end class
