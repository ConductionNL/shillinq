<?php

/**
 * Project Close Guard
 *
 * Lifecycle precondition for the Project schema's `close` transition
 * (active -> closed, lib/Settings/shillinq_register.json).
 *
 * shillinq#425: class did not exist prior to this change; the `close`
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
 * Guards Project `close` — a project with an open WIP (work-in-progress)
 * balance may only close if the operator has recorded a justification
 * (register.d: "Open WIP balance surfaces warning requiring operator
 * justification"). A zero WIP balance closes without further gating.
 *
 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
 */
class ProjectCloseGuard {
	/**
	 * Precondition for `close`: `wipBalance` must be zero, or a non-empty
	 * `closureJustification` must be recorded when it is not.
	 *
	 * @param array<string, mixed> $project The Project object being transitioned.
	 *
	 * @return bool True when the project may be closed.
	 *
	 * @spec openspec/changes/missing-lifecycle-guards/tasks.md#task-2
	 */
	public function requireWipJustificationOrZero(array $project): bool {
		$wipBalance = (float)($project['wipBalance'] ?? 0);
		if (abs($wipBalance) < 0.005) {
			// Effectively zero (within cent-rounding tolerance).
			return true;
		}

		return trim((string)($project['closureJustification'] ?? '')) !== '';
	}//end requireWipJustificationOrZero()
}//end class
