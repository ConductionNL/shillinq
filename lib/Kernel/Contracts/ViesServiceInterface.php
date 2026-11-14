<?php

/**
 * Contract of ViesService
 *
 * The public surface of ViesService that other components call, so they depend
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
 * Contract of ViesService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ViesServiceInterface {

	/**
	 * Validate a VAT-ID against VIES and persist a ViesValidation record (REQ-ICP-001).
	 *
	 * Normalises the VAT-ID, calls the VIES REST proxy, and stores an immutable
	 * ViesValidation. On a transient outage (HTTP 5xx / network error) the most
	 * recent (< 30 days) valid record for the same VAT-ID is reused and the result
	 * is flagged `outage: true` so the daily retry job (REQ-ICP-009) picks it up.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-ICP-001).
	 * @param string $vatId The buyer VAT-ID to verify (any casing / spacing).
	 * @param string $now ISO-8601 "now" override for deterministic tests.
	 *
	 * @return array{vatId:string,valid:bool,outage:bool,requestId:string,validationTimestamp:string,validUntil:string,name:string,address:string,saved:bool,reusedPrior:bool}
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function validate(string $administrationId, string $vatId, string $now = ''): array;
}//end interface
