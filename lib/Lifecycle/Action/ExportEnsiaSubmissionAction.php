<?php

/**
 * Export ENSIA Submission Action
 *
 * Produces the submission XML of an ENSIA cycle when it is submitted (REQ-ENSIA-012).
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
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\EnsiaCycleStepsInterface;

/**
 * Produces the submission XML of an ENSIA cycle when it is submitted (REQ-ENSIA-012).
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
class ExportEnsiaSubmissionAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param EnsiaCycleStepsInterface $steps What the cycle's transitions produce.
	 */
	public function __construct(
		private readonly EnsiaCycleStepsInterface $steps,
	) {
	}//end __construct()

	/**
	 * Render the submission XML and keep it on the cycle; a cycle without its statement is refused, so the transition fails.
	 *
	 * @param LifecycleActionContext $context The transition; its objectData is the cycle after the transition.
	 *
	 * @return array<string, mixed> The cycle with its submission XML.
	 *
	 * @throws \DomainException When the cycle has no college statement.
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function execute(LifecycleActionContext $context): array {
		$objectData = $context->objectData;

		return $this->steps->withSubmission(cycle: $objectData);
	}//end execute()
}//end class
