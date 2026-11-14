<?php

/**
 * Contract of BBVComplianceWidget
 *
 * The public surface of BBVComplianceWidget that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of BBVComplianceWidget.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BBVComplianceWidgetInterface {

	/**
	 * Build the dashboard JSON envelope for the BBV compliance page.
	 *
	 * Queries the BBVProgramme + BudgetBBVMapping registers via OR
	 * ObjectService (real find/findAll API — see
	 * [[or-objectservice-api]]), reads the slice-02 materialised
	 * aggregation fields off each programme record, and assembles:
	 *
	 *   - `programmes[]`  — full programme rows (status buckets + table)
	 *   - `mappings[]`    — BudgetBBVMapping rows (used by the detail
	 *                       drill-down and the chart hover panel)
	 *   - `counts`        — status-bucket histogram (pie chart)
	 *   - `summary`       — totals across the active fiscal year
	 *   - `widgets[]`     — the slice-05 widget declarations
	 *   - `generatedAt`   — UTC ISO-8601 timestamp
	 *
	 * @param int|null $fiscalYear Optional fiscal-year filter.
	 * @param string|null $administrationId Optional administration scope.
	 *
	 * @return array{
	 *   widgets: array<int,array<string,mixed>>,
	 *   programmes: array<int,array<string,mixed>>,
	 *   mappings: array<int,array<string,mixed>>,
	 *   counts: array<string,int>,
	 *   summary: array<string,int|float>,
	 *   generatedAt: string
	 * }
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function buildEnvelope(?int $fiscalYear = null, ?string $administrationId = null): array;
}//end interface
