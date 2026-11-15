<?php

/**
 * Invoice line match
 *
 * One ThreeWayMatch to write for an invoice line: the invoice and line it
 * belongs to, the (PO line, GRN line) candidate it records, the operator's
 * disambiguation choice when there was one, and its initial match status.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A ThreeWayMatch record to persist for one invoice line.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 */
final class InvoiceLineMatch {
	/**
	 * The 1-based invoice line position.
	 *
	 * @var int
	 */
	public int $invoiceLineNumber = 0;

	/**
	 * The candidate tuple snapshot.
	 *
	 * @var array<string,mixed>
	 */
	public array $candidate = [];

	/**
	 * The operator's choice (null when unambiguous).
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $disambiguationChoice = null;

	/**
	 * The initial match status.
	 *
	 * @var string
	 */
	public string $matchStatus = '';

	/**
	 * Start a match record for an invoice.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId        SupplierInvoice id.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $invoiceId,
	) {
	}//end __construct()

	/**
	 * A one-element id list when the candidate carries a non-empty id under
	 * the key, otherwise an empty list.
	 *
	 * @param string $key poId or grnId.
	 *
	 * @return array<int,string> The id list.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function presentIds(string $key): array {
		if (isset($this->candidate[$key]) === true && $this->candidate[$key] !== '') {
			return [(string)$this->candidate[$key]];
		}

		return [];
	}//end presentIds()
}//end class
