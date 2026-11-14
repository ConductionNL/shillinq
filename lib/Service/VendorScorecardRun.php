<?php

/**
 * Vendor Scorecard Run
 *
 * The state of one VendorPerformanceAggregation::calculateMonthlyScore()
 * call: the administration and supplier it scores, the period and its
 * date window, and the supplier's period facts once they are read.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Per-call state of a monthly vendor scorecard calculation.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
 */
final class VendorScorecardRun {

	/**
	 * Period code (YYYY-MM or YYYY-Qn).
	 *
	 * @var string
	 */
	public string $period = '';

	/**
	 * First day of the period window, YYYY-MM-DD.
	 *
	 * @var string
	 */
	public string $from = '';

	/**
	 * Last day of the period window, YYYY-MM-DD.
	 *
	 * @var string
	 */
	public string $to = '';

	/**
	 * The supplier's purchase orders keyed by id.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	public array $poIds = [];

	/**
	 * The supplier's period invoice ids (id => true).
	 *
	 * @var array<string,true>
	 */
	public array $invoiceIds = [];

	/**
	 * The period GRNs against the supplier's purchase orders.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $grns = [];

	/**
	 * The period three-way matches against the supplier's invoices.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $matches = [];

	/**
	 * Constructor.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $supplierId       Supplier (vendor) id.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-10-vendor-performance/tasks.md
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $supplierId,
	) {

	}//end __construct()
}//end class
