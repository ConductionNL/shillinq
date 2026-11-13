<?php

/**
 * Contract of TransactionalGuard
 *
 * The public surface of TransactionalGuard that other components call, so they depend
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
 * Contract of TransactionalGuard.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TransactionalGuardInterface {

	/**
	 * Begin a transaction. Idempotent — calling twice is a no-op.
	 *
	 * @return void
	 */
	public function beginTransaction(): void;

	/**
	 * Commit the outer transaction. Idempotent when no transaction is open.
	 *
	 * @return void
	 */
	public function commit(): void;

	/**
	 * Is the guard currently inside a transaction?
	 *
	 * @return bool
	 */
	public function inTransaction(): bool;

	/**
	 * Roll back the outer transaction. Idempotent when no transaction is open.
	 *
	 * @return void
	 */
	public function rollBack(): void;
}//end interface
