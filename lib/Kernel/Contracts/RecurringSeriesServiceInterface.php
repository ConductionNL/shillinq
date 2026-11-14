<?php

/**
 * Contract of RecurringSeriesService
 *
 * The public surface of RecurringSeriesService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of RecurringSeriesService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecurringSeriesServiceInterface {

	/**
	 * Plan a recurring series: expand the rule and, for each occurrence, reuse
	 * SlotService to decide whether the occurrence's slot is available.
	 *
	 * Returns `{generated: [...appointment payloads...], skipped: [...]}`.
	 * Available occurrences become individual Appointment payloads tagged with
	 * `seriesId` + `recurrenceIndex`; earlier-generated occurrences are folded
	 * back into the existing-appointment set so a later occurrence cannot double
	 * -book the same slot. Unavailable occurrences (out of hours or overlapping)
	 * are skipped with a reason.
	 *
	 * @param array<string, mixed> $seriesDef Series definition — `seriesId`,
	 *                                        `serviceId`, `resourceId`,
	 *                                        `administrationId`, `customerId`
	 *                                        (optional), `startTime` (ISO UTC),
	 *                                        `durationMinutes`, `recurrenceRule`,
	 *                                        `openingTime` (HH:MM),
	 *                                        `closingTime` (HH:MM),
	 *                                        `existingAppointments`
	 *                                        (list of {startTime,endTime}),
	 *                                        `allowOverlap` (bool).
	 *
	 * @return array{generated: array<int, array<string, mixed>>, skipped: array<int, array<string, string>>}
	 *
	 * @throws InvalidArgumentException When the series definition is incomplete or the rule is invalid.
	 *
	 * @spec openspec/specs/bookings-recurring-series/spec.md
	 */
	public function planSeries(array $seriesDef): array;
}//end interface
