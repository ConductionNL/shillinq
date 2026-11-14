<?php

/**
 * Contract of StandardsPolicyService
 *
 * The public surface of StandardsPolicyService that other components call, so they depend
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
 * Contract of StandardsPolicyService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StandardsPolicyServiceInterface {

	/**
	 * The administration's own policy: its object id and frameworks.
	 *
	 * @param string $administrationId The administration.
	 *
	 * @return array{id:?string,frameworks:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/specs/accounting-standards-policy/spec.md#requirement-req-asp-002-administrator-can-declare-and-order-frameworks
	 */
	public function policyFor(string $administrationId): array;

	/**
	 * Save the administration's frameworks, creating its policy on first save.
	 *
	 * The caller has checked that the user manages the administration.
	 *
	 * @param string $administrationId The administration.
	 * @param array<int,mixed> $frameworks The frameworks: `{key, enabled, precedence}` each.
	 *
	 * @return array{id:?string,frameworks:array<int,array<string,mixed>>}
	 *
	 * @throws InvalidArgumentException When an entry is malformed or a key repeats.
	 *
	 * @spec openspec/specs/accounting-standards-policy/spec.md#requirement-req-asp-002-administrator-can-declare-and-order-frameworks
	 */
	public function savePolicy(string $administrationId, array $frameworks): array;
}//end interface
