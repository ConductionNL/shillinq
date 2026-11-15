<?php

/**
 * Contract of ProgrammabegrotingService
 *
 * The public surface of ProgrammabegrotingService that other components call, so they depend
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
 * Contract of ProgrammabegrotingService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ProgrammabegrotingServiceInterface {

	/**
	 * Produce the iv3 taakveld-aggregated rows for one begroting (REQ-012).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $budgetId The Programmabegroting.id to export.
	 *
	 * @return array<int,array{taskFieldCode:string,revenue:float,expenses:float}> The iv3 rows.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-28
	 */
	public function iv3Export(string $administrationId, string $budgetId): array;

	/**
	 * Produce the OpenCatalogi JSON export for one begroting (REQ-012).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $budgetId The Programmabegroting.id to export.
	 *
	 * @return array<string,mixed> The JSON-serialisable export shape.
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-30
	 */
	public function jsonExport(string $administrationId, string $budgetId): array;

	/**
	 * Return the computed sluitend-status for one begroting (REQ-008, REQ-011).
	 *
	 * Fetches the begroting and its meerjarenraming jaren scoped to the
	 * administration, evaluates each year and the overall flags via
	 * SluitendCalculator, and derives the toezichtregime.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $budgetId The Programmabegroting.id to evaluate.
	 *
	 * @return array{budgetId:string,structurallyBalanced:bool,sluitendReëel:bool,supervisionRegime:string,jaren:array<int,array<string,mixed>>}
	 *
	 * @spec openspec/changes/bookkeeping-programmabegroting/tasks.md#task-19
	 */
	public function sluitendStatus(string $administrationId, string $budgetId): array;
}//end interface
