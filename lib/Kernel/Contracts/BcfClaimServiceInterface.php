<?php

/**
 * Contract of BcfClaimService
 *
 * The public surface of BcfClaimService that other components call, so they depend
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
 * Contract of BcfClaimService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BcfClaimServiceInterface {

	/**
	 * Compute the compensable-VAT claim for one administration + quarter (REQ-BCF-002).
	 *
	 * Returns the quarter total plus a per-account breakdown (accountNumber,
	 * posted amount, compensablePercentage, weighted compensable amount). Only
	 * compensable accounts contribute; non-compensable accounts and accounts
	 * without a mapping are excluded.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-BCF-010).
	 * @param string $claimQuarter Quarter identifier to claim (e.g. 2026-Q1, REQ-BCF-001).
	 *
	 * @return array{administrationId: string, claimQuarter: string, totalCompensableAmount: float, breakdown: array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
	 */
	public function computeClaim(string $administrationId, string $claimQuarter): array;
}//end interface
