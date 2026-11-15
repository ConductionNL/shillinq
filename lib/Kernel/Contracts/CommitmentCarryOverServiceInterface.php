<?php

/**
 * Contract of CommitmentCarryOverService
 *
 * The public surface of CommitmentCarryOverService that other components call, so they depend
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
 * Contract of CommitmentCarryOverService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CommitmentCarryOverServiceInterface {

	/**
	 * Carry every open line of a year to the next.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fromYear         The year that ends.
	 * @param string $user             Who ran it.
	 *
	 * @return array{fromYear:int,toYear:int,lines:list<array<string,mixed>>,shortfalls:list<array<string,mixed>>,total:int}
	 *   The preview that was carried out.
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function execute(string $administrationId, int $fromYear, string $user): array;

	/**
	 * The open lines of a year and the next year's shortfalls.
	 *
	 * @param string $administrationId The administration.
	 * @param int    $fromYear         The year that ends.
	 *
	 * @return array{fromYear:int,toYear:int,lines:list<array<string,mixed>>,shortfalls:list<array<string,mixed>>,total:int}
	 *
	 * @spec openspec/changes/archive/2026-09-29-planning-commitment-year-end/tasks.md#task-3.1
	 */
	public function preview(string $administrationId, int $fromYear): array;
}//end interface
