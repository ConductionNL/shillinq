<?php

/**
 * One recurring-series planning run.
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
 * @spec openspec/specs/bookings-recurring-series/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The series definition, its opening hours and the appointments planned or
 * skipped so far. Built and read by RecurringSeriesService::planSeries.
 *
 * @spec openspec/specs/bookings-recurring-series/spec.md
 */
final class SeriesPlan {

	/**
	 * The series definition as given.
	 *
	 * @var array<string, mixed>
	 */
	public array $seriesDef = [];

	/**
	 * Duration of one occurrence, in minutes.
	 *
	 * @var int
	 */
	public int $durationMinutes = 0;

	/**
	 * Opening time (HH:MM).
	 *
	 * @var string
	 */
	public string $openingTime = '00:00';

	/**
	 * Closing time (HH:MM).
	 *
	 * @var string
	 */
	public string $closingTime = '23:59';

	/**
	 * Whether occurrences may overlap existing appointments.
	 *
	 * @var bool
	 */
	public bool $allowOverlap = false;

	/**
	 * Existing appointments, the planned occurrences folded in.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $existing = [];

	/**
	 * The appointment payloads generated so far.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $generated = [];

	/**
	 * The occurrences skipped so far.
	 *
	 * @var array<int, array<string, string>>
	 */
	public array $skipped = [];

	/**
	 * The recurrence index of the next occurrence.
	 *
	 * @var int
	 */
	public int $index = 0;
}//end class
