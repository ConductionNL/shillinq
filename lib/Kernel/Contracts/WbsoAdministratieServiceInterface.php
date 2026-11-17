<?php

/**
 * Contract of WbsoAdministratieService
 *
 * The public surface of WbsoAdministratieService that other components call, so they depend
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
 * Contract of WbsoAdministratieService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WbsoAdministratieServiceInterface {

	/**
	 * Compute the WBSO realisatie summary for one administration (REQ-WBSO-010).
	 *
	 * Returns one row per WbsoBeschikking carrying the granted hours, the realised
	 * hours (sum of confirmed + locked SoUurregistratie hours), the remaining
	 * headroom and a boolean exceeded flag. Hours are summed in integer-tenths to
	 * avoid IEEE-754 float drift, then returned as floats.
	 *
	 * @param string $administrationId Administration scope (server-resolved, REQ-WBSO-004).
	 *
	 * @return array{data: array<int,array<string,mixed>>, total: int}
	 *
	 * @spec openspec/changes/bookkeeping-wbso-sno-administratie/specs.md
	 */
	public function realisatieSummary(string $administrationId): array;
}//end interface
