<?php

/**
 * VAT Submission Guard
 *
 * Lifecycle precondition for the VatReturn schema's `submit` transition
 * (draft -> submitted, lib/Settings/register.d/
 * add-shillinq-bookkeeping-operations.json, REQ-VBTW-006).
 *
 * shillinq#425: class did not exist prior to this change; the `submit`
 * transition hard-failed (RuntimeException from LifecycleGuardRegistry).
 * This was one of the two guards the initial (incorrect) triage flagged as
 * a possible silent financial-control bypass; confirmed instead to be a
 * hard-fail (see shillinq#425 investigation notes on
 * LifecycleGuardRegistry::resolve() / LifecycleValidationListener).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Guard
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

namespace OCA\Shillinq\Guard;

/**
 * Guards VatReturn `submit` — a return whose `amount` exceeds the
 * administration-configured `approvalThreshold` may not be submitted
 * (REQ-VBTW-006).
 *
 * The VatReturn schema (lib/Settings/register.d/
 * add-shillinq-bookkeeping-operations.json) has no dedicated
 * approval-evidence field (no `approvedBy`/`approvalState`-equivalent
 * property) — verified against the live schema, not assumed. Rather than
 * invent a field the schema does not declare, this guard enforces the
 * documented rule literally and fails closed: over-threshold returns are
 * rejected outright until a follow-up change adds an approval-evidence
 * field and threads it through here (filed as shillinq#435). Below-threshold
 * returns, and returns with no threshold configured, submit normally.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class VatSubmissionGuard {
	/**
	 * Precondition for `submit`: `amount` must not exceed `approvalThreshold`.
	 *
	 * @param array<string, mixed> $return The VatReturn object being transitioned.
	 *
	 * @return bool True when the return may be submitted.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireApproval(array $return): bool {
		$threshold = ($return['approvalThreshold'] ?? null);
		if ($threshold === null) {
			// No threshold configured for this administration — no gate active.
			return true;
		}

		$amount = (float)($return['amount'] ?? 0);
		return $amount <= (float)$threshold;
	}//end requireApproval()
}//end class
