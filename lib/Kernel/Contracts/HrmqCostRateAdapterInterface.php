<?php

/**
 * Contract of HrmqCostRateAdapter
 *
 * The public surface of HrmqCostRateAdapter that other components call, so they depend
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
 * Contract of HrmqCostRateAdapter.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HrmqCostRateAdapterInterface {

	/**
	 * The register slug that answers on this instance.
	 *
	 * Probed rather than assumed, because getting it wrong costs nothing
	 * visible: a read against a register that is not there throws, the caller
	 * catches, the person is omitted, and the aggregate simply reports the
	 * cost as unavailable. That is indistinguishable from an employee who
	 * genuinely has no rate.
	 *
	 * Public because SubjectCostService reads the hour rows out of the same
	 * register and there must be exactly one place that knows where humaniq
	 * lives. Two copies of a slug list drift, and the drift is silent.
	 *
	 * @return string The register slug, defaulting to the newest name.
	 *
	 * @spec openspec/specs/subject-cost-aggregation/spec.md
	 */
	public function registerSlug(): string;
}//end interface
