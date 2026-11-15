<?php

/**
 * Contract of FidoQuarter
 *
 * The public surface of FidoQuarter that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of FidoQuarter.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FidoQuarterInterface {

	/**
	 * Compute and keep the Fido figures of a quarter.
	 *
	 * @param string $organisationId The organisation.
	 * @param string $year           The audit year, for example "2026".
	 * @param int    $quarter        The quarter, 1 to 4.
	 *
	 * @return array{cashStatus: array<string,mixed>, renteRiskStatus: array<string,mixed>} The snapshots for the quarterly report.
	 *
	 * @throws InvalidArgumentException When the quarter is invalid or the year has no cash limit record.
	 *
	 * @spec openspec/changes/public-sector-quarterly-returns/specs/bookkeeping-wet-fido-treasury/spec.md
	 */
	public function compute(string $organisationId, string $year, int $quarter): array;
}//end interface
