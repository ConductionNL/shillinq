<?php

/**
 * Cost Rate Run
 *
 * One {@see HrmqCostRateAdapter::ratesFor()} call: hrmq's cost-rate service,
 * the costing period and shillinq's additions, plus — on the per-person copy —
 * the person being priced and their Employee record.
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
 * @spec openspec/specs/subject-cost-aggregation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * State of one cost-rate resolution.
 *
 * @spec openspec/specs/subject-cost-aggregation/spec.md
 */
class CostRateRun {

	/**
	 * Hrmq's cost-rate service (typed loosely: hrmq may not be installed).
	 *
	 * @var mixed
	 */
	public mixed $service = null;

	/**
	 * Costing period `YYYY-MM`.
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * Shillinq's ledger-derived additions per ADR-081.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $additions = [];

	/**
	 * The person being priced.
	 *
	 * @var string
	 */
	public string $personId = '';

	/**
	 * That person's hrmq Employee record.
	 *
	 * @var array<string, mixed>
	 */
	public array $employee = [];
}//end class
