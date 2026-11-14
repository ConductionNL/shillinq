<?php

/**
 * Contract of OrderFulfilmentGuard
 *
 * The public surface of OrderFulfilmentGuard that other components call, so they depend
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
 * Contract of OrderFulfilmentGuard.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OrderFulfilmentGuardInterface {

	/**
	 * Precondition for the voltooien (in-progress → completed) transition.
	 *
	 * REQ-004: the delivery can only be completed when at least one bewijsstuk
	 * (proof of delivery) is attached. A bewijsstuk is considered valid when it
	 * carries a non-empty documentId, so an empty placeholder object cannot
	 * satisfy the gate.
	 *
	 * Fail-closed: returns false on any exception (denies completion) per CWE-863.
	 *
	 * @param array<string, mixed> $assignment OrderFulfilment object array supplied by OR.
	 *
	 * @return bool True when the delivery may be marked completed.
	 *
	 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-8
	 */
	public function canVoltooien(array $assignment): bool;
}//end interface
