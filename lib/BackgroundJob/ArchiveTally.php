<?php

/**
 * Archive Tally
 *
 * The counts of one archive cron run.
 *
 * @category Cron
 * @package  OCA\Shillinq\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-29
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\BackgroundJob;

/**
 * How many filed documents one run archived, skipped and failed to archive.
 *
 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/tasks.md#task-29
 */
final class ArchiveTally {
	/**
	 * Documents archived.
	 *
	 * @var integer
	 */
	public int $archived = 0;

	/**
	 * Documents skipped (unidentified, or retention not elapsed).
	 *
	 * @var integer
	 */
	public int $skipped = 0;

	/**
	 * Documents whose archival failed.
	 *
	 * @var integer
	 */
	public int $failed = 0;
}//end class
