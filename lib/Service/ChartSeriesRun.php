<?php

/**
 * The months, contexts and budgets one BudgetChartSeriesService request composes.
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
 * The requested months, both reader contexts, the AnnualBudget per fiscal
 * year and — once built — every account envelope, shared by the steps of one
 * chart-series request. Built and used only by BudgetChartSeriesService.
 *
 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
 */
final class ChartSeriesRun {
	/**
	 * Constructor.
	 *
	 * @param list<string>                      $months                The requested months.
	 * @param array<string,mixed>               $vsActualsContext      The {@see BudgetVsActualsReader} context.
	 * @param array<string,mixed>               $projectionContext     The {@see BudgetProjectionReader} context.
	 * @param array<int,string|null>            $annualBudgetIdsByYear Fiscal year => resolved AnnualBudget id.
	 * @param array<string,array<string,mixed>> $envelopesByAccount    Every built account envelope, keyed by account number.
	 */
	public function __construct(
		public readonly array $months,
		public readonly array $vsActualsContext,
		public readonly array $projectionContext,
		public readonly array $annualBudgetIdsByYear,
		public readonly array $envelopesByAccount = [],
	) {

	}//end __construct()

	/**
	 * This run with the account envelopes built.
	 *
	 * @param array<string,array<string,mixed>> $envelopesByAccount The envelopes, keyed by account number.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/budget-charts/specs/budget-charts/spec.md#req-bch-003
	 */
	public function withAccountEnvelopes(array $envelopesByAccount): self {
		return new self(
			months: $this->months,
			vsActualsContext: $this->vsActualsContext,
			projectionContext: $this->projectionContext,
			annualBudgetIdsByYear: $this->annualBudgetIdsByYear,
			envelopesByAccount: $envelopesByAccount,
		);

	}//end withAccountEnvelopes()
}//end class
