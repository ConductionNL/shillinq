<?php

/**
 * Project Transition Guard
 *
 * Lifecycle precondition for the Project schema's `putOnHold` transition
 * (active -> on-hold, lib/Settings/shillinq_register.json).
 *
 * shillinq#425: class did not exist prior to this change; the `putOnHold`
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
 * Guards Project `putOnHold` — pausing a project requires a recorded reason
 * (the schema's own `closureJustification` field, per the transition's
 * register.d description: "Pause project; closureJustification (reason)
 * field must be set.").
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class ProjectTransitionGuard {
	/**
	 * Precondition for `putOnHold`: `closureJustification` must be set.
	 *
	 * @param array<string, mixed> $project The Project object being transitioned.
	 *
	 * @return bool True when the project may be put on hold.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireReason(array $project): bool {
		return trim((string)($project['closureJustification'] ?? '')) !== '';
	}//end requireReason()
}//end class
