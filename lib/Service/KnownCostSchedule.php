<?php

/**
 * Known-Cost Schedule
 *
 * The resolved window and per-occurrence amount of one `CashflowRecurring`
 * row for one fiscal year, shared by every frequency expansion of
 * {@see KnownCostScheduleExpander}.
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
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;

/**
 * One recurring row's schedule for one fiscal year.
 *
 * @spec openspec/changes/budget-known-costs/specs/budget-known-costs/spec.md#req-bkc-003
 */
class KnownCostSchedule {

	/**
	 * The row's validFrom (also the first WEEKLY/FORTNIGHTLY occurrence).
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $validFrom;

	/**
	 * The row's validTo, or null when indefinite.
	 *
	 * @var DateTimeImmutable|null
	 */
	public ?DateTimeImmutable $validTo = null;

	/**
	 * The fiscal year being computed.
	 *
	 * @var integer
	 */
	public int $fiscalYear = 0;

	/**
	 * `standardAmount × indexation factor`, in EUR, for one occurrence.
	 *
	 * @var float
	 */
	public float $perOccurrence = 0.0;
}//end class
