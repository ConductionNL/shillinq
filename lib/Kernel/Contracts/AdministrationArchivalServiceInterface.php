<?php

/**
 * Contract of AdministrationArchivalService
 *
 * The public surface of AdministrationArchivalService that other components call, so they depend
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
 * Contract of AdministrationArchivalService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdministrationArchivalServiceInterface {

	/**
	 * Storage-backed write-block assertion (REQ-MA-007).
	 *
	 * Resolves the administration by id via the real ObjectService API
	 * (`find` / `findAll`) and rejects when the record is missing or in a
	 * read-only state. Used by services/controllers that only carry an
	 * administrationId at the call site.
	 *
	 * @param string $administrationId The administration id to check.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the administration is missing or read-only.
	 *
	 * @spec openspec/changes/bookkeeping-multi-administratie/tasks.md#task-17
	 */
	public function assertWritableById(string $administrationId): void;
}//end interface
