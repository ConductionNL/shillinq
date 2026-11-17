<?php

/**
 * Contract of PeriodCloseAssistantService
 *
 * The public surface of PeriodCloseAssistantService that other components call, so they depend
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
 * Contract of PeriodCloseAssistantService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PeriodCloseAssistantServiceInterface {

	/**
	 * Run every detector and return the consolidated flag list (REQ-PC-004).
	 *
	 * @param string $administrationId The administration scope (server-resolved, REQ-PC-008).
	 * @param string $periodId The period to analyse.
	 * @param string $endDate The period end date (ISO date) for date filtering.
	 *
	 * @return array<int,array<string,mixed>> Flag objects {id, severity, message, category, detectedAt}.
	 *
	 * @spec openspec/changes/bookkeeping-period-close/tasks.md#task-8
	 */
	public function analyse(string $administrationId, string $periodId, string $endDate = ''): array;
}//end interface
