<?php

/**
 * Contract of RequisitionConversionGuard
 *
 * The public surface of RequisitionConversionGuard that other components call, so they depend
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
 * Contract of RequisitionConversionGuard.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RequisitionConversionGuardInterface {

	/**
	 * Precondition for the `convertToPO` transition: is the requisition
	 * approved (REQ-REQ-005)?
	 *
	 * Fail-closed: returns false on any exception or when the requisition is
	 * missing or not in status 'approved'.
	 *
	 * @param string $requisitionId The requisition identifier (lifecycle-engine call parity).
	 * @param array<string,mixed>|null $object The Requisition object being transitioned.
	 *
	 * @return bool True when the requisition may be converted.
	 *
	 * @spec openspec/specs/purchase-requisition/spec.md
	 */
	public function canConvert(string $requisitionId, ?array $object = null): bool;
}//end interface
