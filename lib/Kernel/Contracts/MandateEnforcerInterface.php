<?php

/**
 * Contract of MandateEnforcer
 *
 * The public surface of MandateEnforcer that other components call, so they depend
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
 * Contract of MandateEnforcer.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MandateEnforcerInterface {

	/**
	 * Precondition for the `aangaan` transition: does a valid mandaat cover this
	 * commitment (REQ-VPL-002)?
	 *
	 * Fail-closed: returns false on any exception or when no mandaat applies.
	 *
	 * @param string $commitmentNumber The verplichting identifier (lifecycle-engine call parity).
	 * @param array<string,mixed>|null $object The Commitment object being transitioned.
	 *
	 * @return bool True when a valid mandaat covers the commitment amount and soort.
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.3
	 */
	public function hasSufficientMandate(string $commitmentNumber, ?array $object = null): bool;

	/**
	 * Resolve the mandaat that validly covers a commitment, or null.
	 *
	 * A mandaat applies when ALL hold (REQ-VPL-002):
	 *   - it is currently valid (geldig_van <= today <= geldig_tot, where set);
	 *   - its soort_verplichting array contains the commitment's soort (empty array = any soort);
	 *   - its maximumbedrag is >= the commitment's totaalbedrag_excl_btw;
	 *   - administrationId matches (tenant isolation).
	 *
	 * Among applicable mandaten the one with the lowest sufficient ceiling is
	 * returned (least-privilege), preferring non-override mandates.
	 *
	 * @param array<string,mixed> $commitment The commitment being signed.
	 *
	 * @return array<string,mixed>|null The applicable mandaat record, or null.
	 *
	 * @spec openspec/changes/bookkeeping-verplichtingenadministratie/tasks.md#task-1.3
	 */
	public function resolveApplicableMandate(array $commitment): ?array;
}//end interface
