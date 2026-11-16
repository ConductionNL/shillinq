<?php

/**
 * Interest Allocation Action
 *
 * The executor of the `calculate` and `post` transitions an
 * InterestAllocationRun declares (public-sector-reserves-and-interest,
 * REQ-PSRI-003). The declared `step` parameter names which: calculate fills
 * the run's lines and totals, post books them in one journal entry and links
 * it. A refusal aborts the transition.
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
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\InterestAllocationServiceInterface;

/**
 * Calculates or posts an interest allocation run.
 *
 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
 */
class InterestAllocationAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param InterestAllocationServiceInterface $interest Calculates and posts the run.
	 */
	public function __construct(
		private readonly InterestAllocationServiceInterface $interest,
	) {
	}//end __construct()

	/**
	 * Run the declared step on the run.
	 *
	 * @param LifecycleActionContext $context The transition; its objectData is the run after the transition. Its
	 *                                        parameters are the declared actionParameters, with `step`.
	 *
	 * @return array<string,mixed> The run, calculated or posted.
	 *
	 * @spec openspec/specs/bookkeeping-programmabegroting/spec.md
	 */
	public function execute(LifecycleActionContext $context): array {
		$objectData = $context->objectData;
		$parameters = $context->parameters;

		if (($parameters['step'] ?? '') === 'post') {
			return $this->interest->post(run: $objectData);
		}

		return $this->interest->calculate(run: $objectData);

	}//end execute()
}//end class
