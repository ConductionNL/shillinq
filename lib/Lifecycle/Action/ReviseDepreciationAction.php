<?php

/**
 * Revise Depreciation Action
 *
 * Runs on the FixedAsset `revise` transition (REQ-AMCR-002):
 * replans the unposted months from the revision date and makes the new method and useful life the asset's own.
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
use OCA\Shillinq\Service\Asset\FixedAssetDepreciation;

/**
 * The `revise` transition's executor.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class ReviseDepreciationAction implements LifecycleActionInterface {

	/**
	 * Constructor.
	 *
	 * @param FixedAssetDepreciation $depreciation The asset-level operations.
	 */
	public function __construct(
		private readonly FixedAssetDepreciation $depreciation,
	) {
	}//end __construct()

	/**
	 * Replan and return the asset with its new method, life, monthly amount and book value.
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

		return $this->depreciation->revise(asset: $objectData);

	}//end execute()
}//end class
