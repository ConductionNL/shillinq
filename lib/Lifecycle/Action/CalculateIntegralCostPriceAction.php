<?php

/**
 * Calculate Integral Cost Price Action
 *
 * The executor of the `calculate` transition a CommercialActivity declares
 * (public-sector-task-field-allocation, REQ-WMO-013): the integral cost price
 * of the activity for the year given as the transition's input, from the
 * posted lines, stored as an IntegralCostPrice. A refusal aborts the
 * transition.
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
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\ActivityCostPriceCalculationInterface;

/**
 * Calculates a commercial activity's integral cost price.
 *
 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
 */
class CalculateIntegralCostPriceAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param ActivityCostPriceCalculationInterface $costPrice Calculates and stores the cost price.
	 */
	public function __construct(
		private readonly ActivityCostPriceCalculationInterface $costPrice,
	) {
	}//end __construct()

	/**
	 * Calculate the cost price of the activity.
	 *
	 * @param array<string,mixed> $objectData   The activity after the transition, with costPriceYear.
	 * @param array<string,mixed> $previousData The activity before it.
	 * @param array<string,mixed> $parameters   The declared actionParameters (none).
	 * @param string              $actionName   The declared action name.
	 *
	 * @return array<string,mixed> The activity with integralCostPriceId.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 *
	 * @spec openspec/changes/public-sector-task-field-allocation/specs/bookkeeping-market-government-separation/spec.md
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		return $this->costPrice->calculate(activity: $objectData);

	}//end execute()
}//end class
