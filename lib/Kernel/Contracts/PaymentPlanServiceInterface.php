<?php

/**
 * Contract of PaymentPlanService
 *
 * The public surface of PaymentPlanService that other components call, so they depend
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

use OCA\Shillinq\Kernel\Exception\PaymentPlanRefusedException;

/**
 * Contract of PaymentPlanService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentPlanServiceInterface {

	/**
	 * Agree a drafted plan: pause dunning, stamp the invoices, mail the schedule.
	 *
	 * @param string $planId The plan id.
	 * @param string $actor  The user activating it.
	 *
	 * @return array<string,mixed> The plan as stored.
	 *
	 * @throws PaymentPlanRefusedException When the plan is not a draft or its schedule does not add up.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function activate(string $planId, string $actor): array;

	/**
	 * Cancel a draft or active plan and resume its pauses.
	 *
	 * @param string $planId The plan id.
	 * @param string $reason Why.
	 *
	 * @return array<string,mixed> The plan as stored.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function cancel(string $planId, string $reason): array;

	/**
	 * Draw up a plan and its schedule, in draft.
	 *
	 * @param array<string,mixed> $input  administrationId, customerId, invoiceIds, instalmentCount or
	 *                                    instalmentAmount, frequency, firstDueDate, graceDays,
	 *                                    includesCharges, chargesAmount, agreedWith, note.
	 * @param string              $actor  The user agreeing the plan.
	 *
	 * @return array{plan:array<string,mixed>,instalments:array<int,array<string,mixed>>}
	 *
	 * @throws PaymentPlanRefusedException When the invoices or the terms cannot make a plan.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function draft(array $input, string $actor): array;

	/**
	 * The daily pass over every active plan (REQ-RPPL-004, REQ-RPPL-005).
	 *
	 * @return array{due:int,missed:int,broken:int,completed:int}
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-3.1
	 */
	public function monitor(): array;

	/**
	 * A plan by id.
	 *
	 * @param string $planId The plan id.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws PaymentPlanRefusedException When there is no such plan.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.1
	 */
	public function plan(string $planId): array;

	/**
	 * Receive a payment on an active plan.
	 *
	 * @param string $planId   The plan id.
	 * @param float  $amount   The amount received.
	 * @param string $paidDate When it arrived, Y-m-d.
	 * @param string $source   `bank`, `payment-link` or `by-hand`.
	 *
	 * @return array<string,mixed> The allocation, with the plan after it.
	 *
	 * @throws PaymentPlanRefusedException When the plan is not active or the amount is not positive.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.2
	 */
	public function receive(string $planId, float $amount, string $paidDate, string $source): array;
}//end interface
