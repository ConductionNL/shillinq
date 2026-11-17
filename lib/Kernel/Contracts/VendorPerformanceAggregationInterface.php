<?php

/**
 * Contract of VendorPerformanceAggregation
 *
 * The public surface of VendorPerformanceAggregation that other components call, so they depend
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
 * Contract of VendorPerformanceAggregation.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface VendorPerformanceAggregationInterface {

	/**
	 * Aggregate every supplier with activity in the administration for the
	 * given period. Used by the monthly cron entry point.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $period Period code (YYYY-MM).
	 *
	 * @return array<int,array<string,mixed>> The persisted scorecards.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function aggregateAdministrationForPeriod(string $administrationId, string $period): array;
}//end interface
