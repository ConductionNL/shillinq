<?php

/**
 * One parsed recurrence rule, anchored on its series start.
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

use DateTimeImmutable;

/**
 * The series start and the INTERVAL, BYDAY, UNTIL and occurrence cap of an
 * RRULE. Built and read by RecurringSeriesService.
 *
 * @spec openspec/specs/bookings-recurring-series/spec.md
 */
final class RecurrenceRule {

	/**
	 * First occurrence start (UTC).
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $seriesStart;

	/**
	 * Recurrence interval, at least 1.
	 *
	 * @var int
	 */
	public int $interval = 1;

	/**
	 * Whether the rule is WEEKLY (else DAILY or MONTHLY).
	 *
	 * @var bool
	 */
	public bool $weekly = false;

	/**
	 * ISO weekday numbers of BYDAY.
	 *
	 * @var array<int, int>
	 */
	public array $byDay = [];

	/**
	 * Inclusive upper bound, or null.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $until = null;

	/**
	 * Max occurrences.
	 *
	 * @var int
	 */
	public int $hardCap = 0;
}//end class
