<?php

/**
 * Contract of LandedCostAllocationService
 *
 * The public surface of LandedCostAllocationService that other components call, so they depend
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
 * Contract of LandedCostAllocationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface LandedCostAllocationServiceInterface {

	/**
	 * Allocate a receipt's landed cost across its lines and capitalise it.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $receiptReference Receipt document reference (StockMove.referenceDocumentUri).
	 * @param int $landedCostCents Total landed cost in integer cents.
	 * @param string $basis Allocation basis: 'value' (default) or 'quantity'.
	 * @param string $postingDate ISO yyyy-mm-dd; empty defaults to today.
	 *
	 * @return array<string,mixed> Result envelope: 'allocated' bool, 'lines', 'posting', 'totalAllocatedCents'.
	 *
	 * @spec openspec/specs/inventory-accounting-correctness/spec.md
	 */
	public function allocate(
		string $administrationId,
		string $receiptReference,
		int $landedCostCents,
		string $basis = 'value',
		string $postingDate = '',
	): array;
}//end interface
