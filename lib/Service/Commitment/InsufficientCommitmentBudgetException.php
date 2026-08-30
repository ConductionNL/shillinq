<?php

/**
 * Insufficient Commitment Budget Exception.
 *
 * Thrown by {@see CommitmentMaterialisationService} when a PurchaseOrder
 * approval would auto-materialise a Commitment for which BudgetBlocker
 * denies budget room and no override-mandate applies (REQ-VPL-010). The
 * PO-approval write path (CommitmentMaterialisationListener) lets this
 * exception propagate so the approval itself surfaces the denial rather
 * than silently reserving nothing (fail-closed, CWE-863).
 *
 * @category Exception
 * @package  OCA\Shillinq\Service\Commitment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-verplichtingenadministratie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Commitment;

use RuntimeException;

/**
 * Fail-closed denial for an auto-materialised commitment with insufficient
 * budget room and no override-mandate (REQ-VPL-010).
 *
 * @spec openspec/changes/verplichtingen-commitment-accounting/tasks.md#task-1
 */
class InsufficientCommitmentBudgetException extends RuntimeException {
}//end class
