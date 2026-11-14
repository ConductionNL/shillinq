<?php

/**
 * Contract of ObjectTransitionRunner
 *
 * The public surface of ObjectTransitionRunner that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of ObjectTransitionRunner.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ObjectTransitionRunnerInterface {

	/**
	 * Run one declared transition.
	 *
	 * @param string $objectId The object's uuid.
	 * @param string $action   The transition name as the schema declares it.
	 * @param array<string,mixed> $data Declared transition inputs, if any.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the engine is not available.
	 * @throws \Throwable Whatever the engine throws: a refused guard, a state it cannot leave.
	 *
	 * @spec openspec/specs/bookkeeping-bank-reconciliation/spec.md
	 */
	public function run(string $objectId, string $action, array $data = []): void;

	/**
	 * Run one declared transition as the system, after shillinq checked the caller itself.
	 *
	 * OpenRegister skips its own read and update checks on the subject; the
	 * transition's declared guards still run. Only for callers shillinq has
	 * authorised by other means, such as a redeemed confirmation token.
	 *
	 * @param string $objectId The object's uuid.
	 * @param string $action   The transition name as the schema declares it.
	 * @param array<string,mixed> $data Declared transition inputs, if any.
	 *
	 * @return mixed The saved object as the engine returns it.
	 *
	 * @throws RuntimeException When the engine is not available.
	 * @throws \Throwable Whatever the engine throws: a refused guard, a state it cannot leave.
	 *
	 * @spec openspec/changes/bookings-confirm-flow/tasks.md#task-10
	 */
	public function runAsSystem(string $objectId, string $action, array $data = []): mixed;
}//end interface
