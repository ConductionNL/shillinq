<?php

/**
 * Source Id Claim
 *
 * The time entries and expenses a new or re-validated invoice wants to bill,
 * checked by {@see InvoiceDeduplicationService} against existing invoices.
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
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The source ids one invoice claims.
 *
 * @spec openspec/specs/invoice-from-time-and-expense/spec.md
 */
class SourceIdClaim {

	/**
	 * Requested time entry ids.
	 *
	 * @var array<int,string>
	 */
	public array $timeEntryIds = [];

	/**
	 * Requested expense ids.
	 *
	 * @var array<int,string>
	 */
	public array $expenseIds = [];

	/**
	 * Invoice id to skip (the draft being re-validated), if any.
	 *
	 * @var string|null
	 */
	public ?string $excludeInvoiceId = null;
}//end class
