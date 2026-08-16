<?php

/**
 * Iv3 Submission Guard
 *
 * Lifecycle precondition for the Iv3Export schema's `submit` transition
 * (validated -> submitted, lib/Settings/shillinq_register.json). ADR-031
 * exception-path PHP guard.
 *
 * shillinq#425: class did not exist prior to this change; the `submit`
 * transition hard-failed (RuntimeException from LifecycleGuardRegistry).
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
 * Guards Iv3Export `submit` — an already-submitted export (submittedAt set)
 * must never be resubmitted, and a validated export must actually carry its
 * generated XML before being sent to CBS via digipoort/cbs-iv3.
 *
 * The Iv3Export schema has no dedicated "approver" field (verified against
 * lib/Settings/shillinq_register.json — no approvedBy/approvalState
 * property exists), so this guard enforces the two preconditions the
 * schema's own data CAN support: no double-submission, and completeness of
 * the artefact being sent. It does not invent an approval-workflow field
 * that isn't declared anywhere.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class Iv3SubmissionGuard {
	/**
	 * Precondition for `submit`.
	 *
	 * @param array<string, mixed> $export The Iv3Export object being transitioned.
	 *
	 * @return bool True when the export may be submitted to CBS.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireApproval(array $export): bool {
		if (($export['submittedAt'] ?? null) !== null) {
			// Already submitted once — never resubmit the same export record.
			return false;
		}

		$xmlUri = trim((string)($export['xmlAttachmentUri'] ?? ''));
		if ($xmlUri === '') {
			return false;
		}

		$buckets = ($export['buckets'] ?? null);
		if (is_array($buckets) === false || $buckets === []) {
			return false;
		}

		return true;
	}//end requireApproval()
}//end class
