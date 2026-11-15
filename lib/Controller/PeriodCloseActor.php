<?php

/**
 * Period Close Actor
 *
 * Who changes a period's close state, in which administration.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-19
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * The authenticated user and the administration they may act in.
 *
 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-19
 */
final class PeriodCloseActor {
	/**
	 * Construct the actor.
	 *
	 * @param string $userId The user.
	 * @param string $administrationId The administration the user may access.
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $administrationId,
	) {
	}//end __construct()
}//end class
