<?php

/**
 * Cutoff Run
 *
 * One {@see RevenueCutoffService::compute()} run: the scope, the period end,
 * the billed amounts, and the recognised and allocated sums it derives.
 *
 * @category Service
 * @package  OCA\Shillinq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-17
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * State of one revenue cut-off.
 *
 * @spec openspec/changes/bookkeeping-ifrs15-revenue/tasks.md#task-17
 */
class CutoffRun {

	/**
	 * Administration scope (server-resolved).
	 *
	 * @var string
	 */
	public string $administrationId = '';

	/**
	 * Period-end date the cut-off covers.
	 *
	 * @var string
	 */
	public string $periodEnd = '';

	/**
	 * contractId => cumulative billed amount (from the AR module).
	 *
	 * @var array<string,float>
	 */
	public array $billedByContract = [];

	/**
	 * contractId => recognised amounts.
	 *
	 * @var array<string,array{cumulative:float,period:float}>
	 */
	public array $recognised = [];

	/**
	 * contractId => total allocated price.
	 *
	 * @var array<string,float>
	 */
	public array $allocated = [];
}//end class
