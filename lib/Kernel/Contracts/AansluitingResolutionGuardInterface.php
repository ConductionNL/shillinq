<?php

/**
 * Contract of AansluitingResolutionGuard
 *
 * The public surface of AansluitingResolutionGuard that other components call, so they depend
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
 * Contract of AansluitingResolutionGuard.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AansluitingResolutionGuardInterface {

	/**
	 * Returns true iff the AansluitingResult carries a non-blank explanation
	 * (REQ-AANS-006).
	 *
	 * Fail-closed: returns false when explanationReasonText is missing,
	 * blank, or the record is not in the `explained` status.
	 *
	 * @param array<string,mixed> $result The AansluitingResult record.
	 *
	 * @return bool True when resolution may proceed.
	 *
	 * @spec openspec/specs/bookkeeping-aansluitingen/spec.md
	 */
	public function canResolve(array $result): bool;
}//end interface
