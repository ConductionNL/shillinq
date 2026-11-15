<?php

/**
 * Spend Aggregation
 *
 * One single-field spend aggregation SpendAnalyticsService runs through
 * OpenRegister's aggregation API: the dimension it answers, the source schema,
 * the summed field, the scoped filter and the field it groups by.
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
 * @spec openspec/specs/spend-analytics/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * One spend view's aggregation.
 *
 * @spec openspec/specs/spend-analytics/spec.md
 */
final class SpendAggregation {

	/**
	 * Numeric field to sum.
	 *
	 * @var string
	 */
	public string $metricField = '';

	/**
	 * OR aggregation filter map (operator-aware), scoped to one administration.
	 *
	 * @var array<string,mixed>
	 */
	public array $filter = [];

	/**
	 * Single top-level scalar field to GROUP BY.
	 *
	 * @var string
	 */
	public string $groupField = '';

	/**
	 * Constructor.
	 *
	 * @param string $dimension Stable dimension identifier (supplier/category/costCentre/period).
	 * @param string $schema    Source schema slug.
	 */
	public function __construct(
		public readonly string $dimension,
		public readonly string $schema,
	) {

	}//end __construct()
}//end class
