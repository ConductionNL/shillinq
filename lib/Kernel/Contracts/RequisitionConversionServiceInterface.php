<?php

/**
 * Contract of RequisitionConversionService
 *
 * The public surface of RequisitionConversionService that other components call, so they depend
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
 * Contract of RequisitionConversionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RequisitionConversionServiceInterface {

	/**
	 * Convert an approved requisition into a PurchaseOrder (REQ-REQ-005).
	 *
	 * Server-authoritative:
	 *  - refuses when the requisition is missing, not approved, or has no
	 *    preferredSupplierId (a PurchaseOrder always needs a supplier);
	 *  - maps the requisition + its RequisitionLine items into
	 *    PurchaseOrderService::createPurchaseOrder()'s payload shape
	 *    (costCenter <- programma, glAccount <- glAccountSuggestion), and
	 *    threads `requisitionId` through so the new PurchaseOrder carries a
	 *    traceability link back to this requisition;
	 *  - on success, persists the requisition with statusCode='converted',
	 *    convertedPurchaseOrderId=<new PO id>, convertedAt=now.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $requisitionId Requisition id.
	 *
	 * @return array{requisition:array<string,mixed>,purchaseOrder:array<string,mixed>}
	 *
	 * @throws \RuntimeException When the requisition is missing, not approved,
	 *                           has no preferred supplier, or PO creation fails.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function convertToPurchaseOrder(string $administrationId, string $requisitionId): array;
}//end interface
