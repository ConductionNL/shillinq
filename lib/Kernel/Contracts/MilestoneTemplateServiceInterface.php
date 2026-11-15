<?php

/**
 * Contract of MilestoneTemplateService
 *
 * The public surface of MilestoneTemplateService that other components call, so they depend
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
 * Contract of MilestoneTemplateService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MilestoneTemplateServiceInterface {

	/**
	 * Generate a milestone plan for a contract term (REQ-003).
	 *
	 * Each template milestone is placed at looptijdStart + fractionOfTerm × term,
	 * clamped to the contract end, and assigned a stable mijlpaalId. Every
	 * generated milestone starts in the 'planned' status with no invoice number.
	 *
	 * @param string $assignmentType Contract type used to select the template.
	 * @param string $termStart Contract start date (ISO 8601, e.g. "2026-02-01").
	 * @param string $termEnd Contract end date (ISO 8601).
	 *
	 * @return array<int, array<string, mixed>> Ordered mijlpalen ready for the Commitment.
	 *
	 * @throws \InvalidArgumentException When the dates are unparseable or end is not after start.
	 * @throws \RuntimeException When the templates file is missing or malformed.
	 *
	 * @spec openspec/changes/bookkeeping-tenderned-integratie/tasks.md#task-3
	 */
	public function generatePlan(string $assignmentType, string $termStart, string $termEnd): array;
}//end interface
