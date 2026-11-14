<?php

/**
 * The month one BillablePeriodInvoiceService request drafts.
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
 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The customer, asking app, reference, month, lines, ledger key and payload
 * hash shared by the steps of one billable-period request. Built and used
 * only by BillablePeriodInvoiceService.
 *
 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
 */
final class BillablePeriodRun {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed>                                                      $customer    The CustomerMaster row.
	 * @param string                                                                    $sourceApp   The asking app.
	 * @param string                                                                    $reference   The asking app's id for the customer.
	 * @param string                                                                    $period      `YYYY-MM`.
	 * @param array<int, array{description:string, quantity:float, unitPriceCents:int}> $lines       The normalised lines.
	 * @param string                                                                    $batchId     `<sourceApp>:<reference>:<period>`.
	 * @param string                                                                    $payloadHash The hash of the lines.
	 */
	public function __construct(
		public readonly array $customer,
		public readonly string $sourceApp,
		public readonly string $reference,
		public readonly string $period,
		public readonly array $lines,
		public readonly string $batchId,
		public readonly string $payloadHash,
	) {

	}//end __construct()

	/**
	 * The customer's administration.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function administrationId(): string {
		return (string)$this->customer['administrationId'];

	}//end administrationId()

	/**
	 * The first day of the month.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function periodStart(): string {
		return $this->period . '-01';

	}//end periodStart()

	/**
	 * The last day of the month.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/billable-period-becomes-an-invoice/specs/usage-metered-billing/spec.md (REQ-UMB-005)
	 */
	public function periodEnd(): string {
		return date('Y-m-t', (int)strtotime($this->periodStart()));

	}//end periodEnd()
}//end class
