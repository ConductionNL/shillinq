<?php

/**
 * Contract of PeriodCloseService
 *
 * The public surface of PeriodCloseService that other components call, so they depend
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

use OCA\Shillinq\Kernel\Exception\PeriodCloseException;

/**
 * Contract of PeriodCloseService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PeriodCloseServiceInterface {

	/**
	 * Thrown-status sentinel for an authorization failure.
	 *
	 * @var string
	 */
	public const ERR_FORBIDDEN = 'forbidden';

	/**
	 * Thrown-status sentinel for a missing record.
	 *
	 * @var string
	 */
	public const ERR_NOT_FOUND = 'not-found';

	/**
	 * Thrown-status sentinel for a validation failure.
	 *
	 * @var string
	 */
	public const ERR_VALIDATION = 'validation';

	/**
	 * Close a period: closing → closed (REQ-PC-002, REQ-PC-008).
	 *
	 * Enforces the period-closer role, the closing state, and the mandatory
	 * checklist (AP / AR items resolved). Stamps closedAt / closedBy.
	 *
	 * @param string $periodId The PeriodClose id or business periodId.
	 * @param string $administrationId The caller's administration scope.
	 * @param string $userId The acting user id.
	 *
	 * @return array<string,mixed> The updated record.
	 *
	 * @throws PeriodCloseException On a forbidden / not-found / invalid-state / validation error.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function closePeriod(string $periodId, string $administrationId, string $userId): array;

	/**
	 * Fetch a PeriodClose record by id, scoped to an administration (REQ-PC-005).
	 *
	 * @param string $periodId The PeriodClose record id (uuid) or business periodId.
	 * @param string $administrationId The administration the caller is scoped to (REQ-PC-008).
	 *
	 * @return array<string,mixed>|null The record, or null when not found / out of scope.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function getPeriodForClose(string $periodId, string $administrationId): ?array;

	/**
	 * Audit-lock a closed period: closed → audit-locked, irreversible (REQ-PC-002, REQ-PC-008).
	 *
	 * Requires the auditor role and the closed state. Stamps auditLockedAt /
	 * auditLockedBy. The audit-locked state is terminal.
	 *
	 * @param string $periodId The PeriodClose id or business periodId.
	 * @param string $administrationId The caller's administration scope.
	 * @param string $userId The acting auditor's user id.
	 *
	 * @return array<string,mixed> The updated record.
	 *
	 * @throws PeriodCloseException On a forbidden / not-found / invalid-state error.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function lockForAudit(string $periodId, string $administrationId, string $userId): array;

	/**
	 * Reopen a closed period: closed → open (REQ-PC-006, REQ-PC-008).
	 *
	 * Requires the period-closer role and a non-empty close reason. Appends the
	 * original close timestamp + actor to reopenedHistory and clears closedAt /
	 * closedBy. An audit-locked period cannot be reopened.
	 *
	 * @param string $periodId The PeriodClose id or business periodId.
	 * @param string $administrationId The caller's administration scope.
	 * @param string $closeReason The audit-trailed reopen reason (non-empty).
	 * @param string $userId The acting user id.
	 *
	 * @return array<string,mixed> The updated record.
	 *
	 * @throws PeriodCloseException On a forbidden / not-found / invalid-state / validation error.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function reopenPeriod(string $periodId, string $administrationId, string $closeReason, string $userId): array;

	/**
	 * Start the guided close for a period: open → closing (REQ-PC-002, REQ-PC-008).
	 *
	 * @param string $periodId The PeriodClose id or business periodId.
	 * @param string $administrationId The caller's administration scope.
	 * @param string $userId The acting user id.
	 *
	 * @return array<string,mixed> The updated record.
	 *
	 * @throws PeriodCloseException On a forbidden / not-found / invalid-state error.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-7
	 */
	public function startClose(string $periodId, string $administrationId, string $userId): array;
}//end interface
