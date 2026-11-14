<?php

/**
 * Contract of ComplianceService
 *
 * The public surface of ComplianceService that other components call, so they depend
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
 * Contract of ComplianceService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ComplianceServiceInterface {

	/**
	 * Compute the compliance status envelope for a single programme.
	 *
	 * The maths (TotalBudget, YTDSpend, Utilization, ComplianceStatus)
	 * is declarative on the BBVProgramme schema (slice 02). This method
	 * only reads those materialised values back and shapes them into
	 * the `{utilization, status, budget, ytdSpend}` envelope.
	 *
	 * Accepts either:
	 *
	 *   - a `programmeCode` string (e.g. `"2.3.2"`) — resolved against
	 *     the configured shillinq register;
	 *   - an associative array shaped like a BBVProgramme record with
	 *     `programmeCode` + the materialised aggregation fields already
	 *     populated by the engine (preferred call shape for the widget
	 *     controller, which already paged the list).
	 *
	 * Slice-09 addition (REQ-BBVW-006): the optional `fiscalYear` argument
	 * scopes the aggregation read to a single fiscal year. The fiscal
	 * year is folded into the cache key so a programme that exists across
	 * multiple fiscal years (e.g. "2.3.2" in both FY 2025 and FY 2026)
	 * does not return stale cross-FY envelopes. When the caller does not
	 * supply a fiscalYear (legacy slice-08 call shape) the cache key
	 * elides the FY segment and the lookup uses every fiscal year — that
	 * branch is kept compatible for the existing tests + the cache
	 * invalidation listener.
	 *
	 * Returns a compliance envelope. Empty/unconfigured programmes return
	 * `utilization=0.0`, `status="unconfigured"`, `budget=0` and `ytdSpend=0`.
	 *
	 * @param array<string,mixed>|string $programme Programme record or programmeCode.
	 * @param int|null $fiscalYear Optional fiscal-year scope (REQ-BBVW-006).
	 *
	 * @return array{utilization: float, status: string, budget: int, ytdSpend: int, programmeCode: string}
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function computeComplianceStatus(array|string $programme, ?int $fiscalYear = null): array;

	/**
	 * Clear every cached compliance envelope.
	 *
	 * Called by the GL-transaction listener: a single posted line can
	 * touch any number of mapped programmes (allocations split across
	 * programmes per REQ-BBVW-002), so the cheapest correct response is
	 * to drop every entry under the shillinq compliance namespace and
	 * let the next dashboard render repopulate from the engine.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-waterschappen-bbv-variant/spec.md
	 */
	public function invalidateAll(): void;
}//end interface
