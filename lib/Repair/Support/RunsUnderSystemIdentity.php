<?php

/**
 * Shillinq repair-step system-identity helper.
 *
 * @category Repair
 * @package  OCA\Shillinq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Shillinq\Repair\Support;

/**
 * Runs a repair step's work under OpenRegister's system identity.
 *
 * WHY EVERY WRITING REPAIR STEP NEEDS THIS. A repair step executes during
 * `occ upgrade`, where there is no session. OpenRegister then resolves the
 * actor as 'Anonymous' and REFUSES `create` — measured directly against this
 * instance, the refusal is not app-specific: probing `decidiq`, `shillinq`,
 * `trust-configuration`, `stackiq` and `dossiq` with a deliberately invalid
 * payload, every one answered "User 'Anonymous' does not have permission to
 * 'create'" before validation was even reached.
 *
 * The failure is silent. Steps report it with `$output->warning()`, which does
 * NOT fail an upgrade, so the upgrade prints "Update successful" while nothing
 * was written. On a seeded instance it is quieter still: the step skips because
 * the data already exists, so it never attempts the create that would fail.
 *
 * @spec openspec/specs/app-administration/spec.md
 */
trait RunsUnderSystemIdentity {
	/**
	 * Run $work under a system identity when one can be established.
	 *
	 * FALLS THROUGH rather than refusing. If OpenRegister cannot be resolved,
	 * the work still runs — each step already handles its own write failures,
	 * and adding an identity must not cost the degradation behaviour that was
	 * there before. (Learned the hard way: an earlier version resolved eagerly
	 * and aborted every seeder when resolution failed; three existing tests
	 * caught it.)
	 *
	 * @param object|null $objectService OpenRegister's ObjectService, or null.
	 * @param callable $work The step's actual work.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-administration/spec.md
	 */
	private function withSystemIdentity(?object $objectService, callable $work): void {
		if ($objectService !== null && method_exists($objectService, 'runAsSystem') === true) {
			$objectService->runAsSystem(static function () use ($work): void {
				$work();
			});

			return;
		}

		$work();
	}//end withSystemIdentity()
}//end trait
