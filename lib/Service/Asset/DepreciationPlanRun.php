<?php

/**
 * The inputs of one DepreciationPlanner plan.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\Asset
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\Asset;

/**
 * The first month, book and residual value, months left, method and rate
 * shared by the steps of one plan. Built and used only by DepreciationPlanner.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
final class DepreciationPlanRun {
	/**
	 * Constructor.
	 *
	 * @param string $fromMonth     The first month, YYYY-MM.
	 * @param int    $bookCents     The book value at the start of that month.
	 * @param int    $residualCents The residual value the plan stops at.
	 * @param int    $months        The months left.
	 * @param string $method        linear, degressive or units-of-production.
	 * @param float  $rate          The annual degressive rate as a fraction.
	 */
	public function __construct(
		public readonly string $fromMonth,
		public readonly int $bookCents,
		public readonly int $residualCents,
		public readonly int $months,
		public readonly string $method,
		public readonly float $rate,
	) {

	}//end __construct()

	/**
	 * The amount the plan depreciates: book value above the residual value.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function depreciableCents(): int {
		return max(0, ($this->bookCents - $this->residualCents));

	}//end depreciableCents()
}//end class
