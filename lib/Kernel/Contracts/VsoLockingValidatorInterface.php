<?php

/**
 * Contract of VsoLockingValidator
 *
 * The public surface of VsoLockingValidator that other components call, so they depend
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
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of VsoLockingValidator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VsoLockingValidatorInterface {

	/**
	 * Whether the supplied administration + boekjaar is VSO-locked.
	 *
	 * A year is locked when at least one IBProfitAttribution row for that
	 * administration + boekjaar carries `vso_locked: true`. The check is
	 * filter-scoped to an O(1)-indexed lookup.
	 *
	 * @param string $administrationId Administration scope.
	 * @param int $financialYear Fiscal year.
	 *
	 * @return bool TRUE when the year is VSO-locked.
	 *
	 * @spec openspec/specs/bookkeeping-innovatiebox-administratie/spec.md#req-iba-008
	 */
	public function isYearLocked(string $administrationId, int $financialYear): bool;
}//end interface
