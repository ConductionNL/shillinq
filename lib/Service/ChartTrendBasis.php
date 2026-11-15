<?php

/**
 * What one account's chart trend is classified and projected from.
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
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * One account's actual metric per window month, the displayed actual per
 * month, its last actual month, growth rate and base value. Built and used
 * only by BudgetChartSeriesService.
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
 */
final class ChartTrendBasis {
	/**
	 * Constructor.
	 *
	 * @param array<string,int|float>  $actualMetricByMonth  The projection engine's actual metric per window month.
	 * @param array<string,int>        $actualDisplayByMonth The displayed actual per month (BudgetVsActualsReader's own figure).
	 * @param string|null              $lastActualMonth      The last month with an actual, if any.
	 * @param array<string,mixed>|null $growth               The growth rate (or reason), null without history.
	 * @param int|float|null           $baseValue            The last actual metric value, null without history.
	 */
	public function __construct(
		public readonly array $actualMetricByMonth,
		public readonly array $actualDisplayByMonth,
		public readonly string|null $lastActualMonth,
		public readonly array|null $growth,
		public readonly int|float|null $baseValue,
	) {

	}//end __construct()
}//end class
