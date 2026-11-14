<?php

/**
 * Contract of InnovatieboxSbrExportService
 *
 * The public surface of InnovatieboxSbrExportService that other components call, so they depend
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
 * Contract of InnovatieboxSbrExportService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InnovatieboxSbrExportServiceInterface {

	/**
	 * Render the same data shaped for the docudesk PDF template
	 * `vpb-aangifte-innovatiebox-sectie`.
	 *
	 * Provides the same totals + per-asset rows but with display-friendly
	 * field names (naam, winst_voor_nexus, nexus_percent, ...). The
	 * pre-formatting (locale, EUR signs) is left to the docudesk renderer
	 * itself per ADR-024.
	 *
	 * @param array<string,mixed> $aggregation The InnovatieboxAggregationService::aggregate result.
	 * @param string $administrationId Administration scope.
	 * @param int $financialYear Fiscal year.
	 * @param string $method Election method.
	 *
	 * @return array<string,mixed> The docudesk template context.
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
	 */
	public function toPdfRenderContext(
		array $aggregation,
		string $administrationId,
		int $financialYear,
		string $method = 'per_asset_afpelmethode',
	): array;

	/**
	 * Convert an InnovatieboxAggregationService result into an SBR/XBRL
	 * instance hand-off payload.
	 *
	 * @param array<string,mixed> $aggregation The InnovatieboxAggregationService::aggregate result.
	 *                                         Expected shape: {data: list<row>, totals: assoc}.
	 * @param string $administrationId Administration scope (server-resolved, REQ-IBA-008).
	 * @param int $financialYear Fiscal year.
	 * @param string $method Election: 'per_asset_afpelmethode' (default)
	 *                       or 'flat_rate_25pct'.
	 *
	 * @return array<string,mixed> The SBR/XBRL instance hand-off payload.
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-006
	 */
	public function toSbrInstancePayload(
		array $aggregation,
		string $administrationId,
		int $financialYear,
		string $method = 'per_asset_afpelmethode',
	): array;
}//end interface
