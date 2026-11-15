<?php

/**
 * Contract of SuspenseAgeingService
 *
 * The public surface of SuspenseAgeingService that other components call, so they depend
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
 * Contract of SuspenseAgeingService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SuspenseAgeingServiceInterface {

	/**
	 * Whether any unmatched / routed-to-suspense item remains for an administration.
	 *
	 * This is the CONTROL path used by the period-close blocker: it does NOT
	 * swallow errors — a failure propagates so the caller can FAIL CLOSED (block
	 * the close) rather than mistake an unreadable worklist for an empty one.
	 *
	 * @param string $administrationId The administration scope.
	 * @param string $asOf ISO date to age against; '' means today.
	 *
	 * @return bool True when the suspense worklist is non-empty.
	 *
	 * @throws \Throwable When the worklist cannot be computed (caller fails closed).
	 *
	 * @spec openspec/specs/payment-control-guards/spec.md (REQ-PCG-003)
	 */
	public function hasUnresolvedItems(string $administrationId, string $asOf = ''): bool;
}//end interface
