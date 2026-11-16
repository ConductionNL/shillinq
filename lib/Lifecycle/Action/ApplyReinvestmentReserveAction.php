<?php

/**
 * Apply Reinvestment Reserve Action
 *
 * Runs on the FixedAsset `applyReinvestmentReserve` transition (REQ-AMCR-005):
 * lowers the asset's fiscal cost basis by the amount applied and debits the reserve account.
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

use OCA\OpenRegister\Lifecycle\LifecycleActionContext;
use OCA\OpenRegister\Lifecycle\LifecycleActionInterface;
use OCA\Shillinq\Kernel\Contracts\ReinvestmentReservesInterface;

/**
 * The `applyReinvestmentReserve` transition's executor.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class ApplyReinvestmentReserveAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param ReinvestmentReservesInterface $reserves The reserves.
	 */
	public function __construct(
		private readonly ReinvestmentReservesInterface $reserves,
	) {
	}//end __construct()

	/**
	 * Apply the reserve and return the asset with its fiscal cost basis.
	 *
	 * @param LifecycleActionContext $context The transition; its objectData is the asset with the transition's inputs
	 *                                        merged in.
	 *
	 * @return array<string,mixed> The asset to save.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function execute(LifecycleActionContext $context): array {
		$objectData = $context->objectData;

		return $this->reserves->apply(asset: $objectData);

	}//end execute()
}//end class
