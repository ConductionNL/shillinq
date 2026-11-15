<?php

/**
 * Invoice Line Link
 *
 * The link SupplierInvoiceService::linkInvoiceLineToPo() stamps on one
 * embedded SupplierInvoice line: the 1-based line number it targets and the
 * per-line link fields (linkedPoLineId, linkedPoId, linkedGrnLineId,
 * linkedMatchId), blank ids already stored as null.
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
 * One invoice line's PO / GRN / match link.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 */
final class InvoiceLineLink {

	/**
	 * Constructor.
	 *
	 * @param int                     $lineNumber 1-based line position.
	 * @param array<string,string|null> $fields   The link fields, in the order they are stamped.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function __construct(
		public readonly int $lineNumber,
		public readonly array $fields,
	) {

	}//end __construct()
}//end class
