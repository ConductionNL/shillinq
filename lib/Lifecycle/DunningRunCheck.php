<?php

/**
 * One DunningRunExecuteGuard::canExecute request.
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
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * The DunningRun being executed and the register it lives in.
 *
 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-16
 */
final class DunningRunCheck {

	/**
	 * Construct the check.
	 *
	 * @param string $runId The DunningRun.id.
	 * @param string $register The register slug.
	 */
	public function __construct(
		public readonly string $runId,
		public readonly string $register,
	) {

	}//end __construct()
}//end class
