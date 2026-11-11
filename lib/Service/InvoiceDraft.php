<?php

/**
 * One invoice draft from a generation request.
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
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Request\InvoiceGenerationRequest;

/**
 * The request, the sources it resolved, the line drafts and totals, and the
 * saved invoice's number and id. Built and read by InvoiceGenerationService.
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md#requirement-invoice-generation-service
 */
final class InvoiceDraft {

	/**
	 * The validated request.
	 *
	 * @var InvoiceGenerationRequest
	 */
	public InvoiceGenerationRequest $request;

	/**
	 * Resolved time entries.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $timeEntries = [];

	/**
	 * Resolved expenses.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $expenses = [];

	/**
	 * Total hours logged on the time entries.
	 *
	 * @var float
	 */
	public float $hoursLogged = 0.0;

	/**
	 * The line drafts.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $lineDrafts = [];

	/**
	 * The VAT totals of the line drafts.
	 *
	 * @var array<string,mixed>
	 */
	public array $totals = [];

	/**
	 * The invoice number.
	 *
	 * @var string
	 */
	public string $invoiceNumber = '';

	/**
	 * The saved invoice's id.
	 *
	 * @var string
	 */
	public string $invoiceId = '';
}//end class
