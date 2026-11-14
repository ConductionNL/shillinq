<?php

/**
 * Contract of InventoryMobileScannerService
 *
 * The public surface of InventoryMobileScannerService that other components call, so they depend
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
 * Contract of InventoryMobileScannerService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InventoryMobileScannerServiceInterface {

	/**
	 * Return InventoryStock records modified strictly after the supplied
	 * ISO 8601 timestamp. An empty / null timestamp returns the full set
	 * (initial sync) up to the configured limit.
	 *
	 * Records are scoped to the supplied administrationId so multi-tenant
	 * cross-leaks cannot occur (ADR-005 / ADR-022).
	 *
	 * @param string|null $since ISO 8601 UTC timestamp, e.g. "2026-05-21T14:23:00Z".
	 * @param string $administrationId Tenant scope.
	 * @param int $limit Maximum records to return per page.
	 *
	 * @return array{deltas: list<array<string,mixed>>, serverTimestamp: string}
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function downloadDeltas(?string $since, string $administrationId, int $limit = 500): array;

	/**
	 * Apply a batch of warehouse operations from the mobile PWA.
	 *
	 * Each operation is checked for:
	 *   1. Schema validity (type, sku, location, quantity, transactionId).
	 *   2. transactionId deduplication (within DEDUP_WINDOW_SECONDS).
	 *   3. Role-based permission (REQ-PERM-001).
	 *   4. Atomic stock mutation + audit record creation.
	 *
	 * The method MUST be called with a resolved $userId (Nextcloud auth) and
	 * a list of $roles that the user holds. The caller is responsible for
	 * mapping group memberships (Nextcloud groups warehouse_manager /
	 * inventory_operator / counter) to these role strings.
	 *
	 * @param array<int,array<string,mixed>> $operations Client-submitted operations.
	 * @param string $userId Nextcloud user id of the submitter.
	 * @param list<string> $roles Role strings the user holds.
	 * @param string $administrationId Tenant scope.
	 *
	 * @return array{results: list<array<string,mixed>>, serverTimestamp: string}
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function uploadOperations(
		array $operations,
		string $userId,
		array $roles,
		string $administrationId,
	): array;
}//end interface
