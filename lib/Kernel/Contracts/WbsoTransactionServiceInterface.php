<?php

/**
 * Contract of WbsoTransactionService
 *
 * The public surface of WbsoTransactionService that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of WbsoTransactionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WbsoTransactionServiceInterface {

	/**
	 * Create a draft transaction (REQ-WBSO-002).
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $payload Transaction fields.
	 *
	 * @return array<string,mixed> Persisted record.
	 *
	 * @throws InvalidArgumentException When validation fails.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function createTransaction(string $administrationId, array $payload): array;

	/**
	 * Fetch a single transaction by id within an administration scope.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $transactionId Transaction id or transactionNumber.
	 *
	 * @return array<string,mixed>|null
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function getTransaction(string $administrationId, string $transactionId): ?array;

	/**
	 * List transactions for an administration, optionally filtered.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $filters Optional filters: status, type, dateFrom, dateTo.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function listTransactions(string $administrationId, array $filters = []): array;

	/**
	 * Post a draft transaction (REQ-WBSO-008 Posting).
	 *
	 * GL line-item posting is deferred to tier-2; this method only flips the
	 * state to `posted` so the audit trail and immutability rules engage.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $transactionId Transaction to post.
	 *
	 * @return array<string,mixed> The posted record.
	 *
	 * @throws InvalidArgumentException|RuntimeException
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function postTransaction(string $administrationId, string $transactionId): array;

	/**
	 * Reverse a posted transaction (REQ-WBSO-008 Reversal).
	 *
	 * Creates a new Transaction record with `status=reversed`, the same
	 * amount (the audit trail records that this is a counter-entry), a
	 * description prefixed by `"Reversal of "`, and `reversalOfTransactionId`
	 * pointing to the original. The original record remains untouched in
	 * `posted` per REQ-WBSO-004 (immutability).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $transactionId Original transaction id.
	 * @param string $reason Free-text reason captured at reverse-time.
	 *
	 * @return array<string,mixed> The newly-created reversal record.
	 *
	 * @throws InvalidArgumentException|RuntimeException
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function reverseTransaction(string $administrationId, string $transactionId, string $reason): array;
}//end interface
