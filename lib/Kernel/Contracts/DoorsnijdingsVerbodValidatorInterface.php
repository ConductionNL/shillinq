<?php

/**
 * Contract of DoorsnijdingsVerbodValidator
 *
 * The public surface of DoorsnijdingsVerbodValidator that other components call, so they depend
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
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of DoorsnijdingsVerbodValidator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DoorsnijdingsVerbodValidatorInterface {

	/**
	 * Run the doorsnijdingsverbod check for an administration + boekjaar (REQ-IBA-004).
	 *
	 * Fetches the exclusive IBExpenseAllocation rows and the GL deduction lines
	 * (GLLine) for the year, then cross-checks (grootboekrekening, kostenplaats)
	 * pairs. Returns the findings and whether the year-end close may proceed.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param int $financialYear Fiscal year to validate.
	 *
	 * @return array{findings: array<int,array<string,mixed>>, blocking: bool, total: int}
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-004
	 */
	public function validateNoDuplication(string $administrationId, int $financialYear): array;
}//end interface
