<?php

/**
 * Shillinq SoftCloseRun
 *
 * One nightly soft-close run of SoftCloseExecutor: the administration and
 * period it closes and the run timestamp its postings, alerts and period
 * status carry. Never leaves the executor.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-20
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;

/**
 * The administration, period and timestamp of one soft-close run.
 *
 * @spec openspec/changes/bookkeeping-soft-close-flux/tasks.md#task-20
 */
final class SoftCloseRun {
	/**
	 * Hold the run.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $periodId The yyyy-mm period.
	 * @param DateTimeImmutable $asOf The run timestamp.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $periodId,
		public readonly DateTimeImmutable $asOf,
	) {
	}//end __construct()
}//end class
