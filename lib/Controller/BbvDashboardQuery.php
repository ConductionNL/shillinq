<?php

/**
 * BBV Dashboard Query
 *
 * The fiscal year, programmes and budget statuses one programme
 * budget-vs-actuals dashboard is filtered on.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Controller;

/**
 * The validated dashboard filters; null means "no filter".
 *
 * @spec openspec/specs/bookkeeping-provincies-bbv-variant/spec.md
 */
final class BbvDashboardQuery {
	/**
	 * The fiscal year, null for all years.
	 *
	 * @var integer|null
	 */
	public readonly ?int $fiscalYear;

	/**
	 * The programmes, null for all.
	 *
	 * @var array<int, string>|null
	 */
	public readonly ?array $programmes;

	/**
	 * The budget statuses, null for all.
	 *
	 * @var array<int, string>|null
	 */
	public readonly ?array $statuses;

	/**
	 * State the filters.
	 *
	 * @param int|null                $fiscalYear The fiscal year, null for all years.
	 * @param array<int, string>|null $programmes The programmes, null for all.
	 * @param array<int, string>|null $statuses   The budget statuses, null for all.
	 */
	public function __construct(?int $fiscalYear, ?array $programmes, ?array $statuses) {
		$this->fiscalYear = $fiscalYear;
		$this->programmes = $programmes;
		$this->statuses = $statuses;

	}//end __construct()
}//end class
