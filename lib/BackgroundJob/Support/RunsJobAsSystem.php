<?php

/**
 * Runs Job As System
 *
 * A background job has no user session. Once a schema carries an
 * `authorization` block, OpenRegister reads such a caller as anonymous and
 * returns nothing and refuses every write, so a job that maintains shillinq's
 * own objects runs its work inside ObjectService::runAsSystem(), the way the
 * repair steps do.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob\Support;

use Throwable;

/**
 * Wraps a job's work in OpenRegister's system context. The using class holds a
 * `$container` (Psr\Container\ContainerInterface).
 *
 * @spec openspec/changes/enforced-schema-authorization/specs/schema-authorization/spec.md
 */
trait RunsJobAsSystem {

	/**
	 * Run the work as the system, or plainly when OpenRegister is unavailable.
	 *
	 * @param callable $work The job's work.
	 *
	 * @return void
	 */
	private function asSystem(callable $work): void {
		$objectService = null;
		try {
			$objectService = $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (Throwable $e) {
			$objectService = null;
		}

		if ($objectService !== null && method_exists($objectService, 'runAsSystem') === true) {
			$objectService->runAsSystem(static function () use ($work): void {
				$work();
			});
			return;
		}

		$work();
	}//end asSystem()
}//end trait
