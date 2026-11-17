<?php

/**
 * Receipt scope
 *
 * The administration and caller checks a goods or service receipt runs before
 * it writes: the administration must be accessible (masked as not found per
 * ADR-005), the caller must be the authenticated session user, and every
 * referenced purchase order must exist in the administration.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\Contracts\AdministrationContextServiceInterface;
use RuntimeException;

/**
 * Administration, caller and purchase order checks of the receipt services.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class ReceiptScope {

	/**
	 * Purchase order schema slug.
	 *
	 * @var string
	 */
	private const SCHEMA_PO = 'PurchaseOrder';

	/**
	 * Constructor.
	 *
	 * @param AdministrationContextServiceInterface $administrationContext Administration access and session user.
	 * @param SchemaRecordStore                     $records               Register records.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AdministrationContextServiceInterface $administrationContext,
		private readonly SchemaRecordStore $records,
	) {
	}//end __construct()

	/**
	 * Assert the caller can access the requested administration; mask as
	 * not-found per ADR-005.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the administration is not accessible.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function assertAccess(string $administrationId): void {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Administration not found');
		}

	}//end assertAccess()

	/**
	 * Resolve the validated session user — the server-authoritative
	 * actor identity.
	 *
	 * @return string The user id.
	 *
	 * @throws RuntimeException When no session user is available.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function requireSessionUser(): string {
		$userId = (string)$this->administrationContext->currentUserId();
		if ($userId === '') {
			throw new RuntimeException('Authenticated user is required');
		}

		return $userId;

	}//end requireSessionUser()

	/**
	 * Verify the caller can see every supplied PurchaseOrder under the
	 * administration scope. Cross-tenant refs are masked as 404.
	 *
	 * @param string            $administrationId Administration scope.
	 * @param array<int,string> $poIds            PO ids to validate.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When any PO id is missing or cross-tenant.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function assertPurchaseOrdersAccessible(string $administrationId, array $poIds): void {
		foreach ($poIds as $poId) {
			$po = $this->records->findOne(
				schema: self::SCHEMA_PO,
				filters: [
					'id' => $poId,
					'administrationId' => $administrationId,
				]
			);
			if ($po === null) {
				throw new RuntimeException('Purchase order not found');
			}
		}

	}//end assertPurchaseOrdersAccessible()
}//end class
