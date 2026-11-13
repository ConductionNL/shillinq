<?php

/**
 * Contract of ReinvestmentReserves
 *
 * The public surface of ReinvestmentReserves that other components call, so they depend
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
 * Contract of ReinvestmentReserves.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ReinvestmentReservesInterface {

	/**
	 * Apply a reserve to a replacement asset from the transition inputs.
	 *
	 * @param array<string,mixed> $asset The replacement asset with reinvestmentReserveId and, optionally, reserveAmountApplied.
	 *
	 * @return array<string,mixed> The asset to save, with its fiscal cost basis.
	 *
	 * @throws DomainException When the reserve is not open, belongs to another administration, or the amount does not fit.
	 *
	 * @spec openspec/specs/bookkeeping-fixed-assets-depreciation/spec.md
	 */
	public function apply(array $asset): array;
}//end interface
