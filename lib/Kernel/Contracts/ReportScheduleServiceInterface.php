<?php

/**
 * Contract of ReportScheduleService
 *
 * The public surface of ReportScheduleService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use DateTimeImmutable;
use InvalidArgumentException;
use OutOfBoundsException;

/**
 * Contract of ReportScheduleService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReportScheduleServiceInterface {
	/**
	 * Create a schedule owned by the caller.
	 *
	 * @param string               $ownerId The caller.
	 * @param array<string, mixed> $input   administrationId, reportType, format, frequency, runDay, periodRule, recipients, folderPath, name.
	 * @param DateTimeImmutable    $now     The moment of saving.
	 *
	 * @return array<string, mixed> The saved schedule.
	 *
	 * @throws OutOfBoundsException     When the caller has no access to the administration.
	 * @throws InvalidArgumentException When a field is refused.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function create(string $ownerId, array $input, DateTimeImmutable $now): array;

	/**
	 * Change a schedule the caller owns.
	 *
	 * @param string               $ownerId    The caller.
	 * @param string               $scheduleId The schedule.
	 * @param array<string, mixed> $input      Any of CHANGEABLE.
	 * @param DateTimeImmutable    $now        The moment of saving.
	 *
	 * @return array<string, mixed> The saved schedule.
	 *
	 * @throws OutOfBoundsException     When the schedule does not exist or the caller does not own it.
	 * @throws InvalidArgumentException When a field is refused.
	 *
	 * @spec openspec/changes/reporting-data-delivery/specs/report-delivery/spec.md
	 */
	public function update(string $ownerId, string $scheduleId, array $input, DateTimeImmutable $now): array;
}//end interface
