<?php

/**
 * Render ENSIA Statement Action
 *
 * Renders the college statement of an ENSIA cycle when it moves to college approval (REQ-ENSIA-011).
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
 * Renders the college statement of an ENSIA cycle when it moves to college approval (REQ-ENSIA-011).
 *
 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
 */
class RenderEnsiaStatementAction implements LifecycleActionInterface {

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
	 * Render the college statement and keep it on the cycle.
	 *
	 * @param LifecycleActionContext $context The transition; its objectData is the cycle after the transition.
	 *
	 * @return array<string, mixed> The cycle with its statement.
	 *
	 * @spec openspec/changes/public-sector-council-and-auditor-statements/specs/bookkeeping-ensia-zelfevaluatie/spec.md
	 */
	public function execute(LifecycleActionContext $context): array {
		$objectData = $context->objectData;

		return $this->steps->withStatement(cycle: $objectData);
	}//end execute()
}//end class
