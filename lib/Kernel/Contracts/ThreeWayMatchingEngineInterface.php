<?php

/**
 * Contract of ThreeWayMatchingEngine
 *
 * The public surface of ThreeWayMatchingEngine that other components call, so they depend
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
 * Contract of ThreeWayMatchingEngine.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ThreeWayMatchingEngineInterface {

	/**
	 * Evaluate a 3-way match for a SupplierInvoice.
	 *
	 * Main entry point for the engine. Loads the invoice + resolves PO /
	 * GRN candidates, scores divergence line-by-line, applies the
	 * applicable ToleranceProfile, writes the ThreeWayMatch record and
	 * transitions the SupplierInvoice lifecycle (received → matching →
	 * matched / exception). Returns the persisted ThreeWayMatch.
	 *
	 * Server-authoritative: this method derives the administrationId
	 * from the SupplierInvoice record (NOT from the caller) and asserts
	 * the invoice's matchedPoIds resolve under the same administration —
	 * cross-tenant references are masked as exception_missing_po so the
	 * engine never leaks the existence of a foreign PO.
	 *
	 * @param string $administrationId Administration scope (server-resolved
	 *                                 at the controller).
	 * @param string $invoiceId SupplierInvoice id.
	 *
	 * @return array<string,mixed> The persisted ThreeWayMatch record.
	 *
	 * @throws \RuntimeException When the invoice is unknown or cross-tenant.
	 *
	 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
	 */
	public function evaluateMatch(string $administrationId, string $invoiceId): array;
}//end interface
