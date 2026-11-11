<?php

/**
 * Cashflow report
 *
 * The content of one 13-week cashflow report: the forecast horizon and its
 * weeks, and the optional scenario overlay, top customers and recurring cost
 * breakdown the report adds as sections.
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
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * What a cashflow report shows.
 *
 * @spec openspec/specs/bookkeeping-cashflow-13wk/spec.md#req-cf-016
 */
final class CashflowReport {
	/**
	 * Optional CashflowScenario to overlay.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $scenario = null;

	/**
	 * Top-5 customers by AR balance with betalingsgedrag offsets.
	 *
	 * @var list<array<string,mixed>>
	 */
	public array $topCustomers = [];

	/**
	 * CashflowRecurring rows expanded for the horizon.
	 *
	 * @var list<array<string,mixed>>
	 */
	public array $recurringBreakdown = [];

	/**
	 * Start a report for a horizon.
	 *
	 * @param array<string,mixed>       $horizon CashflowForecastHorizon as array.
	 * @param list<array<string,mixed>> $weeks   13 CashflowWeek records ordered by weeknummer.
	 */
	public function __construct(
		public readonly array $horizon,
		public readonly array $weeks,
	) {
	}//end __construct()
}//end class
