<?php

/**
 * Contract of ObjectRequestRefundService
 *
 * The public surface of ObjectRequestRefundService that other components call, so they depend
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
use OCA\Shillinq\Service\RefundStepForbiddenException;
use RuntimeException;

/**
 * Contract of ObjectRequestRefundService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObjectRequestRefundServiceInterface {

	/**
	 * Approve the open refund: reverse the income into refunds payable.
	 *
	 * @param string $paymentRequestId The request's uuid.
	 *
	 * @return array<string, mixed> The request, its refund now `approved`.
	 *
	 * @throws RefundStepForbiddenException When the user is outside the request's administration.
	 * @throws InvalidArgumentException When there is no requested refund, or an account is missing.
	 * @throws RuntimeException When OpenRegister answers a save without an id.
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	public function approve(string $paymentRequestId): array;

	/**
	 * Mark the approved refund paid: clear refunds payable against the bank.
	 *
	 * @param string $paymentRequestId The request's uuid.
	 * @param string $bankReference    The reference of the bank payment.
	 * @param string $bankAccount      The ledger account of the bank it was paid from.
	 *
	 * @return array<string, mixed> The request, now `refunded`.
	 *
	 * @throws RefundStepForbiddenException When the user is outside the request's administration, or approved the refund.
	 * @throws InvalidArgumentException When there is no approved refund, or a value is missing.
	 * @throws RuntimeException When OpenRegister answers a save without an id.
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-002)
	 */
	public function markPaid(string $paymentRequestId, string $bankReference, string $bankAccount): array;
}//end interface
