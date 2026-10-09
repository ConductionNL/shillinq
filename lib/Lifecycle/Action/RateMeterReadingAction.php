<?php

/**
 * Rate Meter Reading Action
 *
 * The `rate` transition of MeterReading: prices the reading against its rate
 * plan and keeps the amount on it (sales-usage-billing, REQ-USB-001).
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
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\Usage\MeterReadingRating;

/**
 * The `rate` transition's executor.
 *
 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
 */
class RateMeterReadingAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param MeterReadingRating $rating Prices a reading against its plan.
	 */
	public function __construct(
		private readonly MeterReadingRating $rating,
	) {
	}//end __construct()

	/**
	 * Return the reading with its rated amount; a reading without a plan is refused.
	 *
	 * @param array<string,mixed> $objectData   The reading with the transition applied.
	 * @param array<string,mixed> $previousData The reading before.
	 * @param array<string,mixed> $parameters   Declared parameters (none).
	 * @param string              $actionName   The action name.
	 *
	 * @return array<string,mixed> The reading to save.
	 *
	 * @spec openspec/changes/sales-usage-billing/specs/usage-metered-billing/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		return $this->rating->rate(reading: $objectData);

	}//end execute()
}//end class
