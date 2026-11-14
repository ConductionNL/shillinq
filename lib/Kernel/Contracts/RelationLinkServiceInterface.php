<?php

/**
 * Contract of RelationLinkService
 *
 * The public surface of RelationLinkService that other components call, so they depend
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

use DomainException;

/**
 * Contract of RelationLinkService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RelationLinkServiceInterface {

	/**
	 * Stop suggesting one pair.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The customer.
	 * @param string $payeeId          The supplier.
	 *
	 * @return void
	 *
	 * @throws DomainException When the customer is not found.
	 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function dismiss(string $administrationId, string $customerId, string $payeeId): void;

	/**
	 * Link a customer to a supplier; refused when either side is linked already.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The customer.
	 * @param string $payeeId          The supplier.
	 * @param string $userId           Who confirms the link.
	 * @param string $matchedOn        kvk, vat or manual.
	 *
	 * @return array<string, mixed> The link as written.
	 *
	 * @throws DomainException When a record is not found or a side is linked already.
	 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function link(string $administrationId, string $customerId, string $payeeId, string $userId, string $matchedOn): array;

	/**
	 * The unlinked customer and supplier pairs with an equal KvK or VAT number.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array<int, array<string, string>> customerId, customerName, payeeId, payeeName, matchedOn (kvk or vat), number.
	 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function suggestions(string $administrationId): array;

	/**
	 * Remove a customer's link.
	 *
	 * @param string $administrationId The administration.
	 * @param string $customerId       The customer.
	 *
	 * @return void
	 *
	 * @throws DomainException When the customer is not found.
	 *
 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md
	 */
	public function unlink(string $administrationId, string $customerId): void;
}//end interface
