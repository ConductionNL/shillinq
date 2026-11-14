<?php

/**
 * Contract of DebtorCreditService
 *
 * The public surface of DebtorCreditService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of DebtorCreditService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DebtorCreditServiceInterface {

	/**
	 * Pay a new request from the debtor's open credit first, oldest credit first (REQ-ORC-004).
	 *
	 * Called by the leaf after the request is validated and before it is saved.
	 * Each credit used lowers its `remaining` (and reads `used` at zero) and is
	 * booked debit the customer credit account, credit the new request's
	 * revenue account. The request gets one settlement with method `credit` and
	 * is stamped settled when the credit covers it whole. When the credit
	 * cannot be booked (no customer credit account, no revenue account for the
	 * request type) the credit stays open and the request stays payable: the
	 * debtor pays as usual and keeps the credit.
	 *
	 * @param array<string, mixed> $request The request about to be saved.
	 * @param string               $actor   Who raised it: a user id or `app:<appId>`.
	 *
	 * @return array<string, mixed> The request, with the credit settlement when credit was used.
	 *
	 * @throws RuntimeException When OpenRegister answers a save without an id.
	 *
	 * @spec openspec/changes/receivables-object-request-refund-and-credit/specs/object-payment-requests/spec.md (REQ-ORC-004)
	 */
	public function applyOpenCredit(array $request, string $actor): array;
}//end interface
