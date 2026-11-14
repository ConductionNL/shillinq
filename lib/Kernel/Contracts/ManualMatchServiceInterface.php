<?php

/**
 * Contract of ManualMatchService
 *
 * The public surface of ManualMatchService that other components call, so they depend
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

use OCA\Shillinq\Kernel\Exception\ManualMatchRefusedException;
use OutOfBoundsException;

/**
 * Contract of ManualMatchService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ManualMatchServiceInterface {

	/**
	 * Book a line to a ledger account.
	 *
	 * @param array<string,mixed> $line   The line, from findLine().
	 * @param array<string,mixed> $ledger `accountNumber`, optional `vatRate` with `vatAccountNumber`, `description`.
	 * @param string              $actor  The confirming user id.
	 *
	 * @return array<string,mixed> The confirmed match, with `journalEntryId`.
	 *
	 * @throws ManualMatchRefusedException When the line cannot be booked.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public function bookToLedger(array $line, array $ledger, string $actor): array;

	/**
	 * Read a bank line by uuid or by its `lineId`.
	 *
	 * @param string $lineId The line's uuid or business id.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws OutOfBoundsException When the line does not exist.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public function findLine(string $lineId): array;

	/**
	 * Match a line to open invoices.
	 *
	 * @param array<string,mixed> $line      The line, from findLine().
	 * @param array<int,string>   $targetIds Invoice uuids.
	 * @param string              $actor     The confirming user id, or `system:bankfeed`.
	 * @param string              $reason    Why the match was made; '' for a match by hand.
	 *
	 * @return array<string,mixed> The confirmed match.
	 *
	 * @throws ManualMatchRefusedException When the line or the selection cannot be matched.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public function matchInvoices(array $line, array $targetIds, string $actor, string $reason = ''): array;

	/**
	 * Match a line to a payment plan (receivables-payment-plans REQ-RPPL-003).
	 *
	 * The match names the plan's invoices and is partial, so the settlement
	 * listener leaves them to PaymentPlanAllocator, which pays the instalments
	 * and moves an invoice to paid once nothing is left due.
	 *
	 * @param array<string,mixed> $line   The line, from findLine().
	 * @param array<string,mixed> $plan   The active plan, with its id.
	 * @param string              $actor  The confirming user id, or `system:bankfeed`.
	 * @param string              $reason Why the match was made.
	 *
	 * @return array<string,mixed> The confirmed match.
	 *
	 * @throws ManualMatchRefusedException When the line cannot be matched.
	 *
	 * @spec openspec/changes/archive/2026-09-29-receivables-payment-plans/tasks.md#task-2.3
	 */
	public function matchPaymentPlan(array $line, array $plan, string $actor, string $reason): array;

	/**
	 * Match a line to the one object payment request it quotes and confirm it
	 * (REQ-ORS-003). The confirm transition is what settles the request.
	 *
	 * @param array<string,mixed> $line    The line, with its id.
	 * @param array<string,mixed> $request The pending PaymentRequest, with its id.
	 * @param string              $actor   The confirming user id, or `system:bank-match`.
	 *
	 * @return array<string,mixed> The confirmed match.
	 *
	 * @throws ManualMatchRefusedException When the line cannot be matched.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
	 */
	public function matchPaymentRequest(array $line, array $request, string $actor): array;

	/**
	 * Propose a line as a candidate for an object payment request, for the
	 * bookkeeper to confirm or reject; nothing is settled (REQ-ORS-003). The
	 * same line and request are proposed once.
	 *
	 * @param array<string,mixed> $line    The line, with its id.
	 * @param array<string,mixed> $request The pending PaymentRequest, with its id.
	 *
	 * @return array<string,mixed> The pending match, as stored.
	 *
	 * @throws ManualMatchRefusedException When the line cannot be matched.
	 *
	 * @spec openspec/changes/receivables-object-request-settlement/specs/object-payment-requests/spec.md (REQ-ORS-003)
	 */
	public function proposePaymentRequest(array $line, array $request): array;
}//end interface
