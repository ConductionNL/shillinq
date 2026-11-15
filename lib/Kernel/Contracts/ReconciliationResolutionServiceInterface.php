<?php

/**
 * Contract of ReconciliationResolutionService
 *
 * The public surface of ReconciliationResolutionService that other components call, so they depend
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
use OutOfBoundsException;

/**
 * Contract of ReconciliationResolutionService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReconciliationResolutionServiceInterface {

	/**
	 * Resolve one ReconciliationMatch by classifying it per REQ-REC-004.
	 *
	 * @param string $reconId The parent BankReconciliation id.
	 * @param string $matchId The ReconciliationMatch id.
	 * @param string $resolutionStatus One of matched/timing/pending/adjustment
	 *                                 (validated by the caller).
	 * @param string $resolutionReason Operator-supplied reason text
	 *                                 (audit-trailed).
	 * @param string $actor Nextcloud UID of the operator.
	 *
	 * @return array<string,mixed> The updated ReconciliationMatch as
	 *                             returned by OR.
	 *
	 * @throws OutOfBoundsException When the match or parent does not exist.
	 * @throws DomainException When the parent reconciliation is
	 *                         closed/cancelled (locked) per REQ-REC-003.
	 * @throws \Throwable On any OR/service error.
	 *
	 * @spec openspec/specs/bookkeeping-reconciliation-reports/spec.md (REQ-REC-004)
	 */
	public function resolveMatch(
		string $reconId,
		string $matchId,
		string $resolutionStatus,
		string $resolutionReason,
		string $actor,
	): array;
}//end interface
