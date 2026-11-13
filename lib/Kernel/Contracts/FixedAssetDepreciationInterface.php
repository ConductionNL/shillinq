<?php

/**
 * Contract of FixedAssetDepreciation
 *
 * The public surface of FixedAssetDepreciation that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec openspec/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

use DomainException;

/**
 * Contract of FixedAssetDepreciation.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FixedAssetDepreciationInterface {

	/**
	 * Book an extra depreciation from the transition inputs.
	 *
	 * @param array<string,mixed> $asset The asset with extraDepreciationAmount, extraDepreciationDate and extraDepreciationReason.
	 *
	 * @return array<string,mixed> The asset to save.
	 *
	 * @throws DomainException When an input is missing or the schedule refuses the amount.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function extra(array $asset): array;

	/**
	 * Apply a revision from the transition inputs.
	 *
	 * @param array<string,mixed> $asset The asset with revisionDate, revisedMethod, revisedUsefulLifeMonths and revisionReason.
	 *
	 * @return array<string,mixed> The asset to save.
	 *
	 * @throws DomainException When the date or reason is missing, or the schedule refuses the revision.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function revise(array $asset): array;
}//end interface
