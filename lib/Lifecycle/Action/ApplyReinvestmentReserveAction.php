<?php

/**
 * Apply Reinvestment Reserve Action
 *
 * Runs on the FixedAsset `applyReinvestmentReserve` transition (REQ-AMCR-005): lowers the asset's fiscal cost basis by the amount applied and debits the reserve account.
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
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle\Action;

use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Service\Asset\ReinvestmentReserves;

/**
 * The `applyReinvestmentReserve` transition's executor.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class ApplyReinvestmentReserveAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param ReinvestmentReserves $reserves The reserves.
	 */
	public function __construct(
		private readonly ReinvestmentReserves $reserves,
	) {
	}//end __construct()

	/**
	 * Apply the reserve and return the asset with its fiscal cost basis.
	 *
	 * @param array<string,mixed> $objectData   The asset with the transition's inputs merged in.
	 * @param array<string,mixed> $previousData The asset before.
	 * @param array<string,mixed> $parameters   Declared parameters (none).
	 * @param string              $actionName   The action name.
	 *
	 * @return array<string,mixed> The asset to save.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		return $this->reserves->apply(asset: $objectData);

	}//end execute()
}//end class
