<?php

/**
 * Contract of BcfCompensationCalculator
 *
 * The public surface of BcfCompensationCalculator that other components call, so they depend
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
 * Contract of BcfCompensationCalculator.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BcfCompensationCalculatorInterface {

	/**
	 * Decide whether a draft claim may transition to submitted (REQ-BCF-003).
	 *
	 * The submit precondition requires a non-empty claim (totalCompensableAmount
	 * strictly greater than zero) AND a closed claim quarter (period lock from
	 * T2 period-close). An empty or open-quarter claim is rejected fail-closed.
	 *
	 * @param mixed $compensableTotal The claim's computed total (float|int|numeric-string|null).
	 * @param bool $quarterClosed Whether the claim quarter's fiscal period is closed.
	 *
	 * @return bool True when both submit preconditions hold.
	 *
	 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-006
	 */
	public function canSubmit(mixed $compensableTotal, bool $quarterClosed): bool;
}//end interface
