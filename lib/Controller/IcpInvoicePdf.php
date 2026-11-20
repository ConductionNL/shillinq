<?php

/**
 * ICP Invoice PDF
 *
 * One render of an ICP invoice PDF: the invoice, its administration and the
 * records it is rendered from.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
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

namespace OCA\Shillinq\Controller;

/**
 * The invoice to render and, once loaded, its invoice, customer and seller.
 *
 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
 */
final class IcpInvoicePdf {
	/**
	 * The loaded invoice, customer and seller.
	 *
	 * @var array{invoice: array<string,mixed>|null, customer: array<string,mixed>, seller: array<string,mixed>}
	 */
	public array $records = ['invoice' => null, 'customer' => [], 'seller' => []];

	/**
	 * Start the render.
	 *
	 * @param string $invoiceId The invoice.
	 * @param string $administrationId Its administration.
	 */
	public function __construct(
		public readonly string $invoiceId,
		public readonly string $administrationId,
	) {
	}//end __construct()

	/**
	 * The log context of a failure.
	 *
	 * @param \Throwable $failure The failure.
	 *
	 * @return array<string,string>
	 *
	 * @spec openspec/specs/bookkeeping-icp-opgaaf/spec.md
	 */
	public function logContext(\Throwable $failure): array {
		return [
			'administrationId' => $this->administrationId,
			'invoiceId' => $this->invoiceId,
			'exception' => $failure->getMessage(),
		];

	}//end logContext()
}//end class
