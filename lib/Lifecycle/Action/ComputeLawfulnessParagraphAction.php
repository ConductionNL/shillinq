<?php

/**
 * Compute Lawfulness Paragraph Action
 *
 * The `compute` transition of Rechtmatigheidsparagraaf, and the first action
 * of `vaststellen_college`: computes the year's totals and whether they stay
 * within tolerance, and keeps them on the paragraph (REQ-RV-011).
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle\Action
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\LawfulnessParagraphInterface;

/**
 * Keeps the computed totals on the paragraph.
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
 */
class ComputeLawfulnessParagraphAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param LawfulnessParagraphInterface $paragraphs Computes the totals.
	 */
	public function __construct(
		private readonly LawfulnessParagraphInterface $paragraphs,
	) {
	}//end __construct()

	/**
	 * Compute the paragraph's totals and answer the paragraph with them.
	 *
	 * @param array<string, mixed> $objectData   The paragraph.
	 * @param array<string, mixed> $previousData The paragraph before the transition.
	 * @param array<string, mixed> $parameters   The action parameters (none).
	 * @param string               $actionName   The transition.
	 *
	 * @return array<string, mixed> The paragraph with its totals.
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-rechtmatigheidsverantwoording/spec.md
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		$totals = $this->paragraphs->compute(
			administrationId: (string)($objectData['administrationId'] ?? ''),
			financialYear: (int)($objectData['financialYear'] ?? 0)
		);

		return array_merge($objectData, $totals);
	}//end execute()
}//end class
