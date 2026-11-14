<?php

/**
 * Contract of CommitmentMaterialisationService
 *
 * The public surface of CommitmentMaterialisationService that other components call, so they depend
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

use OCA\Shillinq\Kernel\Exception\InsufficientCommitmentBudgetException;

/**
 * Contract of CommitmentMaterialisationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CommitmentMaterialisationServiceInterface {

	/**
	 * Materialise a Commitment from an activated Contract (REQ-VPL-010,
	 * Task 2). Fail-soft: any denial is logged, never thrown — the
	 * Contract's own `activate` transition is a different capability
	 * (contract-lifecycle-management) not modified by this change.
	 *
	 * @param array<string, mixed> $contract Activated Contract payload.
	 *
	 * @return array<string, mixed>|null The materialised (or pre-existing) Commitment, or null.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	public function materialiseFromContract(array $contract): ?array;

	/**
	 * Materialise a Commitment from an approved PurchaseOrder (REQ-VPL-010).
	 *
	 * Fail-closed: throws {@see InsufficientCommitmentBudgetException} when
	 * budget is insufficient and no override-mandate applies, so the caller
	 * (the listener, running synchronously in the approval write path) lets
	 * the approval itself surface the denial.
	 *
	 * @param array<string, mixed> $purchaseOrder Approved PurchaseOrder payload.
	 *
	 * @return array<string, mixed>|null The materialised (or pre-existing) Commitment, or null when there is nothing to materialise.
	 *
	 * @throws InsufficientCommitmentBudgetException When budget denies and no override applies.
	 *
	 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
	 */
	public function materialiseFromPurchaseOrder(array $purchaseOrder): ?array;
}//end interface
