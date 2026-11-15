<?php

/**
 * What the ICP invoice overlay document shows.
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
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The invoice header, the resolved parties and VAT-IDs and the ICP flags
 * {@see ArInvoiceIcpPdfRenderer} renders. Built and used only by that renderer.
 *
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 */
final class IcpInvoiceView {
	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $invoice       ARInvoice record (header).
	 * @param string              $customerName  Customer legal / display name.
	 * @param string              $sellerName    Seller legal / display name.
	 * @param string              $sellerVatId   Seller NL VAT-ID.
	 * @param string              $buyerVatId    Buyer VAT-ID (or C-party for T).
	 * @param bool                $treatAsIcp    Whether the ICP overlay applies.
	 * @param string              $supplyType    Supply type (L / S / T).
	 * @param bool                $triangulation Whether this is a triangulation supply.
	 */
	public function __construct(
		public readonly array $invoice,
		public readonly string $customerName,
		public readonly string $sellerName,
		public readonly string $sellerVatId,
		public readonly string $buyerVatId,
		public readonly bool $treatAsIcp,
		public readonly string $supplyType,
		public readonly bool $triangulation,
	) {

	}//end __construct()
}//end class
