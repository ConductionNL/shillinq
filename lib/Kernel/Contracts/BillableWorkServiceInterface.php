<?php

/**
 * Contract of BillableWorkService
 *
 * The public surface of BillableWorkService that other components call, so they depend
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
 * Contract of BillableWorkService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BillableWorkServiceInterface {

	/**
	 * The unbilled hours of a project and the unbilled pass-through expense
	 * items of a customer, in one administration.
	 *
	 * `hours.available` false means the hours could not be read at all, with
	 * the reason; it never means "no hours". Every hour and item a `draft` or
	 * `posted` BillableInvoice already references is left out.
	 *
	 * @param string $administrationId The administration, resolved and checked on the server.
	 * @param string $customerId       The customer.
	 * @param string $projectId        One of the customer's projects, or '' for no hours.
	 * @param string $from             The first day, `Y-m-d`, or ''.
	 * @param string $to               The last day, `Y-m-d`, or ''.
	 * @param string $rateCardId       The rate card that prices the hours, or '' for none.
	 *
	 * @return array{hours: array{available: bool, reason: string, entries: list<array<string,mixed>>}, expenses: list<array<string,mixed>>}
	 *
	 * @spec openspec/changes/sales-time-and-expense-billing/tasks.md#task-2.2
	 */
	public function billableWork(
		string $administrationId,
		string $customerId,
		string $projectId,
		string $from,
		string $to,
		string $rateCardId='',
	): array;
}//end interface
