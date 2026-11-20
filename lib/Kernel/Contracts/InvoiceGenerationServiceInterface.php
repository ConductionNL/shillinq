<?php

/**
 * Contract of InvoiceGenerationService
 *
 * The public surface of InvoiceGenerationService that other components call, so they depend
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

use OCA\Shillinq\Request\InvoiceGenerationRequest;

/**
 * Contract of InvoiceGenerationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InvoiceGenerationServiceInterface {

	/**
	 * Draft an invoice from a generation request — saves BillableInvoice +
	 * BillableInvoiceLine rows in draft status.
	 *
	 * Every id the request references (timeEntryIds/expenseIds/
	 * meterReadingIds/milestoneId) is resolved through a lookup scoped to
	 * $request->administrationId — a cross-tenant id can never resolve
	 * (REQ-001; see loadTimeEntries()/loadExpenses()/loadMeterReadings()/
	 * loadMilestone()/findScoped()).
	 *
	 * @param InvoiceGenerationRequest $request Validated request.
	 *
	 * @return array<string,mixed> Persisted BillableInvoice (with id).
	 *
	 * @spec openspec/specs/usage-metered-billing/spec.md
	 * @spec openspec/changes/security-endpoint-guards/specs/security-endpoint-guards/spec.md#req-001
	 */
	public function draftInvoice(InvoiceGenerationRequest $request): array;

	/**
	 * Post a draft invoice — transitions to posted, creates a stub Obligation,
	 * and emits a GL JournalEntry covering AR / Revenue / VAT.
	 *
	 * @param array<string,mixed> $invoice Persisted BillableInvoice.
	 *
	 * @return array<string,mixed> Updated invoice (and obligation summary).
	 *
	 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
	 */
	public function postInvoice(array $invoice): array;
}//end interface
