<?php

/**
 * Extra Depreciation Action
 *
 * Runs on the FixedAsset `depreciateExtra` transition (REQ-AMCR-003):
 * posts one extra depreciation line at once and replans the remaining months from the lower book value.
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
use OCA\Shillinq\Service\Asset\FixedAssetDepreciation;

/**
 * The `depreciateExtra` transition's executor.
 *
 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
 */
class ExtraDepreciationAction implements LifecycleActionInterface {

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
	 * Post the extra line and return the asset with its new monthly amount and book value.
	 *
	 * @param array<string,mixed> $objectData   The asset with the transition's inputs merged in.
	 * @param array<string,mixed> $previousData The asset before.
	 * @param array<string,mixed> $parameters   Declared parameters (none).
	 * @param string              $actionName   The action name.
	 *
	 * @return array<string,mixed> The asset to save.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is LifecycleActionInterface's.
	 */
	public function execute(array $objectData, array $previousData, array $parameters, string $actionName): array {
		return $this->depreciation->extra(asset: $objectData);

	}//end execute()
}//end class
