<?php

/**
 * Project Activation Guard
 *
 * Lifecycle precondition for the Project schema's `activate` transition
 * (offerte -> active, lib/Settings/shillinq_register.json).
 *
 * shillinq#425: class did not exist prior to this change; the `activate`
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
 * Guards Project `activate` — moving a project from quotation (offerte) to
 * active execution requires `startDate` to be set.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class ProjectActivationGuard {
	/**
	 * Precondition for `activate`: `startDate` must be set.
	 *
	 * @param array<string, mixed> $project The Project object being transitioned.
	 *
	 * @return bool True when the project may be activated.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireStartDate(array $project): bool {
		return trim((string)($project['startDate'] ?? '')) !== '';
	}//end requireStartDate()
}//end class
