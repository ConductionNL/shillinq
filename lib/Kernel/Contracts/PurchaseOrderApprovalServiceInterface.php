<?php

/**
 * Contract of PurchaseOrderApprovalService
 *
 * The public surface of PurchaseOrderApprovalService that other components call, so they depend
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
 * Contract of PurchaseOrderApprovalService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PurchaseOrderApprovalServiceInterface {

	/**
	 * Decision pending: the slot waits for its approver.
	 *
	 * @var string
	 */
	public const DECISION_PENDING = 'pending';

	/**
	 * Record an approver decision on a PurchaseOrder's approval chain.
	 *
	 * The method stamps the next pending entry's `userId` from the
	 * authenticated user session (NEVER from the request body), the
	 * `decision` from the (validated) input and a deterministic
	 * `decidedAt` ISO timestamp. When the chain becomes fully approved
	 * the PO statusCode advances to `approved`; any rejection
	 * advances it to `cancelled`. Delegated does not advance the
	 * lifecycle (the slot remains pending under a different role —
	 * outside slice 11 scope).
	 *
	 * Server-authoritative:
	 *  - administration scope is validated;
	 *  - the poId is re-loaded from OR so a forged POST cannot pivot to
	 *    a cross-tenant record;
	 *  - the decision is validated against {@see ALLOWED_DECISIONS};
	 *  - the PO must currently be in `draft` (waiting for its chain) — calling on an
	 *    already-approved or terminated PO throws a RuntimeException;
	 *  - the chain must carry at least one pending entry — calling on
	 *    a fully-signed chain throws a RuntimeException.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $purchaseOrderId PurchaseOrder id.
	 * @param string $decision One of {@see ALLOWED_DECISIONS}.
	 * @param string|null $comment Optional approver comment.
	 *
	 * @return array<string,mixed> The updated PurchaseOrder record.
	 *
	 * @throws \RuntimeException On cross-tenant access, missing PO,
	 *                           invalid decision, non-pending PO state,
	 *                           or fully-signed chain.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-11-audit-trail-export/tasks.md
	 */
	public function recordApprovalDecision(
		string $administrationId,
		string $purchaseOrderId,
		string $decision,
		?string $comment = null,
	): array;
}//end interface
