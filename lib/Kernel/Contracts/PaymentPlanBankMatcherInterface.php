<?php

/**
 * Contract of PaymentPlanBankMatcher
 *
 * The public surface of PaymentPlanBankMatcher that other components call, so they depend
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
 * Contract of PaymentPlanBankMatcher.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PaymentPlanBankMatcherInterface {

	/**
	 * Book a line to the one plan its remittance names, or leave it.
	 *
	 * @param array<string,mixed> $line A freshly written bank line with its id.
	 *
	 * @return array<string,mixed>|null The confirmed match, or null when the line waits.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
	 */
	public function book(array $line): ?array;

	/**
	 * The plans a line can pay, best first.
	 *
	 * @param array<string,mixed> $line The bank line.
	 *
	 * @return array<int,array{plan:array<string,mixed>,confidence:string,reason:string}>
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
	 */
	public function candidates(array $line): array;

	/**
	 * Match a line to a plan and receive its amount on the plan.
	 *
	 * @param string              $planId The plan id.
	 * @param array<string,mixed> $line   The bank line with its id.
	 * @param string              $actor  The confirming user, or the bank feed.
	 * @param string              $reason Why.
	 *
	 * @return array<string,mixed> The confirmed match with the allocation.
	 *
	 * @throws PaymentPlanRefusedException When the plan cannot take the payment.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
	 */
	public function payFromLine(string $planId, array $line, string $actor, string $reason): array;
}//end interface
