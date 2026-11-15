<?php

/**
 * Three-Way Match Run
 *
 * The state of one ThreeWayMatchingEngine::evaluateMatch() call: the
 * server-resolved administration, the requested invoice id, the loaded
 * SupplierInvoice and, once resolved, its PO, PO lines and receipts.
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
 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Per-call state of a three-way match evaluation.
 *
 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
 */
final class ThreeWayMatchRun {

	/**
	 * The loaded SupplierInvoice record.
	 *
	 * @var array<string,mixed>
	 */
	public array $invoice = [];

	/**
	 * The single tenant-validated PO id the invoice matches against.
	 *
	 * @var string
	 */
	public string $poId = '';

	/**
	 * The PurchaseOrder record, null when it could not be read.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $po = null;

	/**
	 * The PO's lines.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $poLines = [];

	/**
	 * Receipt schema => the receipts that reference the PO.
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	public array $receipts = [];

	/**
	 * Constructor.
	 *
	 * @param string $administrationId Administration scope taken from the invoice record.
	 * @param string $invoiceId        The requested SupplierInvoice id.
	 *
	 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $invoiceId,
	) {

	}//end __construct()
}//end class
