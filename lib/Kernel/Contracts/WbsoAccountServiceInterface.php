<?php

/**
 * Contract of WbsoAccountService
 *
 * The public surface of WbsoAccountService that other components call, so they depend
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

/**
 * Contract of WbsoAccountService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WbsoAccountServiceInterface {

	/**
	 * Create an account, enforcing hierarchy + circular-ref rules (REQ-WBSO-001).
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $payload Account fields (accountNumber, name, accountType, etc.).
	 *
	 * @return array<string,mixed> The persisted record.
	 *
	 * @throws InvalidArgumentException When validation fails.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function createAccount(string $administrationId, array $payload): array;

	/**
	 * Return one account with its immediate parent + child links populated.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $accountNumber Account number to resolve.
	 *
	 * @return array<string,mixed>|null The account with `parent` + `children`, or null when missing.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function getAccountByNumber(string $administrationId, string $accountNumber): ?array;

	/**
	 * Return the chart-of-accounts as a hierarchical tree (REQ-WBSO-006).
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int,array<string,mixed>> Root accounts, each carrying a recursive `children` array.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function getAccountHierarchy(string $administrationId): array;

	/**
	 * Return every Account record for an administration (REQ-WBSO-006).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function getAccountsByAdministration(string $administrationId): array;

	/**
	 * Update an account's mutable properties (REQ-WBSO-001).
	 *
	 * Allowed fields: name, description, status (active/blocked), vatApplicable,
	 * and parentAccountNumber subject to hierarchy/circular-ref checks. The
	 * accountNumber is immutable once created.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $accountNumber Account to update.
	 * @param array<string,mixed> $payload Update payload.
	 *
	 * @return array<string,mixed> The merged + persisted record.
	 *
	 * @throws InvalidArgumentException When validation fails.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function updateAccount(string $administrationId, string $accountNumber, array $payload): array;
}//end interface
