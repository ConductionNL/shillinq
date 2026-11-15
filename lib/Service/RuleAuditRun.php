<?php

/**
 * Rule Audit Run
 *
 * One {@see RuleAuditService::audit()} run: the evaluation context, the report
 * being filled, the per-rule violation counts, and the object type being
 * audited with its running statistics.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * State of one rule audit.
 *
 * @spec openspec/specs/bookkeeping-rule-engine/spec.md
 */
class RuleAuditRun {

	/**
	 * Evaluation context (e.g. jurisdiction).
	 *
	 * @var array<string, mixed>
	 */
	public array $context = [];

	/**
	 * The report being filled.
	 *
	 * @var array<string, mixed>
	 */
	public array $report = [];

	/**
	 * ruleId => violation count.
	 *
	 * @var array<string, int>
	 */
	public array $byRule = [];

	/**
	 * The object type being audited.
	 *
	 * @var string
	 */
	public string $type = '';

	/**
	 * That type's checked / compliant / withViolations / violations counts.
	 *
	 * @var array<string, int>
	 */
	public array $typeStat = [];
}//end class
