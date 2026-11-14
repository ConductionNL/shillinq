<?php

/**
 * Contract of InboundPayableService
 *
 * The public surface of InboundPayableService that other components call, so they depend
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

use InvalidArgumentException;
use OCA\Shillinq\Event\PayableCancelRequestedEvent;
use OCA\Shillinq\Event\PayableRaisedEvent;

/**
 * Contract of InboundPayableService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InboundPayableServiceInterface {

	/**
	 * Approve a requested payable: book the expense against the payables account.
	 *
	 * @param string $payableId The payable's uuid.
	 *
	 * @return array<string, mixed> The payable, now approved.
	 *
	 * @throws InvalidArgumentException When it is not requested, or an account is missing.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-002)
	 */
	public function approve(string $payableId): array;

	/**
	 * Answer a cancel command from the raising app: a requested payable is
	 * withdrawn, nothing is booked and no callback is sent (the app asked).
	 *
	 * Asking again for a payable already withdrawn answers it again. A payable
	 * finance approved, paid or rejected is refused and keeps its state: an
	 * approved payout has its expense booked, so only finance can undo it.
	 *
	 * @param PayableCancelRequestedEvent $event The command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
	 */
	public function cancel(PayableCancelRequestedEvent $event): void;

	/**
	 * Mark an approved payable paid: clear the payables account against the bank.
	 *
	 * @param string $payableId     The payable's uuid.
	 * @param string $bankReference The reference of the bank payment.
	 * @param string $bankAccount   The ledger account of the bank it was paid from.
	 * @param string $paidOn        The date it left the bank (Y-m-d), today when empty.
	 *
	 * @return array<string, mixed> The payable, now paid.
	 *
	 * @throws InvalidArgumentException When it is not approved, or a value is missing.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-003)
	 */
	public function markPaid(string $payableId, string $bankReference, string $bankAccount, string $paidOn = ''): array;

	/**
	 * Answer a payout command: store it as requested, or refuse and write nothing.
	 *
	 * @param PayableRaisedEvent $event The command.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-001)
	 */
	public function raise(PayableRaisedEvent $event): void;

	/**
	 * Reject a requested payable: nothing is booked, the app hears why.
	 *
	 * @param string $payableId The payable's uuid.
	 * @param string $reason    Why.
	 *
	 * @return array<string, mixed> The payable, now rejected.
	 *
	 * @throws InvalidArgumentException When it is not requested, or no reason is given.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-003)
	 */
	public function reject(string $payableId, string $reason): array;
}//end interface
