<?php

/**
 * Contract of IcpService
 *
 * The public surface of IcpService that other components call, so they depend
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
 * Contract of IcpService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IcpServiceInterface {

	/**
	 * Compute the ICP ledger (aggregated lines + totals) for one period (REQ-ICP-003).
	 *
	 * Fetches the administration's IcpSupply records whose supplyDate falls in the
	 * requested period, aggregates them by (buyerVatId, supplyType), and sums the
	 * goods / services / triangulation totals. Returns the lines, totals, and the
	 * source supply count.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-ICP-001).
	 * @param string $period Filing period (YYYY-Qn or YYYY-MM).
	 *
	 * @return array{period:string,lines:array<int,array<string,mixed>>,total:float,totalGoods:float,totalServices:float,totalTriangulation:float,supplyCount:int}
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function ledger(string $administrationId, string $period): array;

	/**
	 * Decide whether a quarter's goods supplies breach the EUR 50,000 threshold (REQ-ICP-002).
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $quarter Calendar quarter (YYYY-Qn).
	 *
	 * @return array{quarter:string,breached:bool,goodsCumulative:float}
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function periodicityCheck(string $administrationId, string $quarter): array;

	/**
	 * Reconcile a period's ICP ledger total against the BTW-aangifte rubriek 3b (REQ-ICP-004).
	 *
	 * Reads the period's VatReturn (BTW-aangifte) and compares its rubriek 3b value
	 * (the zero-rated intra-community supplies amount) against the computed ICP
	 * total within the EUR 1 tolerance. When no BTW-aangifte exists for the period,
	 * the outcome is flagged missing so the caller can surface icp.btw.missing.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $period Filing period.
	 *
	 * @return array{period:string,icpTotal:float,rubriek3b:float|null,matches:bool,missing:bool,difference:float}
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function reconcile(string $administrationId, string $period): array;
}//end interface
