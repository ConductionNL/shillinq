<?php

/**
 * Backup Run Outcome
 *
 * When one administration backup started and completed, how it ended and how
 * large it was.
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
 * The outcome of one administration backup.
 *
 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-15
 */
final class BackupRunOutcome {
	/**
	 * Backup start instant.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $startedAt;

	/**
	 * Backup completion instant.
	 *
	 * @var DateTimeImmutable
	 */
	public readonly DateTimeImmutable $completedAt;

	/**
	 * Final backup status (success / failed / snapshot-only).
	 *
	 * @var string
	 */
	public readonly string $status;

	/**
	 * Size of the backup payload, when known.
	 *
	 * @var integer|null
	 */
	public readonly ?int $sizeBytes;

	/**
	 * State the outcome.
	 *
	 * @param DateTimeImmutable $startedAt   Backup start instant.
	 * @param DateTimeImmutable $completedAt Backup completion instant.
	 * @param string            $status      Final backup status (success / failed / snapshot-only).
	 * @param int|null          $sizeBytes   Size of the backup payload, when known.
	 */
	public function __construct(
		DateTimeImmutable $startedAt,
		DateTimeImmutable $completedAt,
		string $status,
		?int $sizeBytes = null,
	) {
		$this->startedAt = $startedAt;
		$this->completedAt = $completedAt;
		$this->status = $status;
		$this->sizeBytes = $sizeBytes;

	}//end __construct()
}//end class
