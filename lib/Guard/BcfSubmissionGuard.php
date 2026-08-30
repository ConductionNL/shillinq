<?php

/**
 * BCF Submission Guard
 *
 * Lifecycle precondition for the BcfClaim schema's `submit` transition
 * (draft -> submitted, lib/Settings/register.d/
 * add-shillinq-bookkeeping-operations.json, REQ-BCF-009).
 *
 * shillinq#425: class did not exist prior to this change; the `submit`
 * transition hard-failed (RuntimeException from LifecycleGuardRegistry).
 * This was the second guard the initial (incorrect) triage flagged as a
 * possible silent financial-control bypass; confirmed instead to be a
 * hard-fail (see shillinq#425 investigation notes).
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
 * Guards BcfClaim `submit` — a claim whose `totalClaimAmount` exceeds the
 * administration-configured `approvalThreshold` may not be submitted
 * (REQ-BCF-009).
 *
 * Same schema-gap situation as VatSubmissionGuard (see its docblock): no
 * approval-evidence field exists on BcfClaim yet, so this guard enforces
 * the documented threshold rule literally and fails closed rather than
 * inventing an evidence field. Filed alongside shillinq#435.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class BcfSubmissionGuard {
	/**
	 * Precondition for `submit`: `totalClaimAmount` must not exceed
	 * `approvalThreshold`.
	 *
	 * @param array<string, mixed> $claim The BcfClaim object being transitioned.
	 *
	 * @return bool True when the claim may be submitted.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireApproval(array $claim): bool {
		$threshold = ($claim['approvalThreshold'] ?? null);
		if ($threshold === null) {
			return true;
		}

		$amount = (float)($claim['totalClaimAmount'] ?? 0);
		return $amount <= (float)$threshold;
	}//end requireApproval()
}//end class
