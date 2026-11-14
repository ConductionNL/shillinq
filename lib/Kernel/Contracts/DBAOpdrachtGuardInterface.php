<?php

/**
 * Contract of DBAOpdrachtGuard
 *
 * The public surface of DBAOpdrachtGuard that other components call, so they depend
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
 * Contract of DBAOpdrachtGuard.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DBAOpdrachtGuardInterface {

	/**
	 * Compute the retention deadline (einddatum + 7 jaar) per AWR art. 52.
	 *
	 * @param string $feitelijkeEindDatum Y-m-d datestring.
	 *
	 * @return string|null The deadline Y-m-d, or null when input cannot be parsed.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function computeRetentieDeadline(string $feitelijkeEindDatum): ?string;
}//end interface
