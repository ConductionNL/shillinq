<?php

/**
 * What one account's projection is computed from.
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
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-010
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * One account's type and metric, its actual metric per month, its last
 * actual month, its growth rate and the base value projections extrapolate
 * from. Built and used only by BudgetProjectionService.
 *
 * @spec openspec/specs/budget-projection-engine/spec.md#req-bpe-010
 */
final class AccountProjectionBasis {
	/**
	 * Constructor.
	 *
	 * @param string                   $accountNumber       The account.
	 * @param string                   $accountType         Its account type.
	 * @param string                   $metric              Its projection metric.
	 * @param array<string,int|float>  $actualMetricByMonth The actual metric per window month.
	 * @param string|null              $lastActualMonth     The last month with an actual, if any.
	 * @param array<string,mixed>|null $growth              The calculator's growth rate (or reason), null without history.
	 * @param int|float|null           $baseValue           The last actual metric value, null without history.
	 */
	public function __construct(
		public readonly string $accountNumber,
		public readonly string $accountType,
		public readonly string $metric,
		public readonly array $actualMetricByMonth,
		public readonly string|null $lastActualMonth,
		public readonly array|null $growth,
		public readonly int|float|null $baseValue,
	) {

	}//end __construct()
}//end class
