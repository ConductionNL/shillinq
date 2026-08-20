<?php

/**
 * Write-Off Reason Guard
 *
 * Lifecycle precondition for the ARInvoice schema's `writeOff` transition
 * (overdue|disputed -> written-off, lib/Settings/shillinq_register.json).
 *
 * shillinq#425: class did not exist prior to this change; the `writeOff`
 * transition hard-failed (RuntimeException from LifecycleGuardRegistry).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * Guards ARInvoice `writeOff` — declaring an invoice uncollectible requires
 * a non-empty `writeOffReason` for the audit trail.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class WriteOffReasonGuard {
	/**
	 * Precondition for `writeOff`: `writeOffReason` must be set.
	 *
	 * @param array<string, mixed> $invoice The ARInvoice object being transitioned.
	 *
	 * @return bool True when the write-off may proceed.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireReason(array $invoice): bool {
		return trim((string)($invoice['writeOffReason'] ?? '')) !== '';
	}//end requireReason()
}//end class
