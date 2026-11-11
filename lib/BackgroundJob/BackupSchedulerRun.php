<?php

/**
 * Backup Scheduler Run
 *
 * The store and the instant one backup-scheduler tick works with.
 *
 * @category BackgroundJob
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

use DateTimeImmutable;

/**
 * One scheduler tick: where it reads and writes, and its single "now".
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
 */
final class BackupSchedulerRun {
	/**
	 * The OpenRegister reads and writes.
	 *
	 * @var AdministrationBackupStore
	 */
	public readonly AdministrationBackupStore $store;

	/**
	 * The tick's instant: what is due is judged by it and it stamps every record.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $now;

	/**
	 * Start a tick.
	 *
	 * @param AdministrationBackupStore $store The store.
	 * @param DateTimeImmutable         $now   The tick's instant.
	 */
	public function __construct(AdministrationBackupStore $store, DateTimeImmutable $now) {
		$this->store = $store;
		$this->now = $now;

	}//end __construct()
}//end class
