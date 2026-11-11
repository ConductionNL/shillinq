<?php

/**
 * Exception Route
 *
 * Where a SupplierInvoice goes when the three-way match routes it to the
 * exception queue: the invoice, the exception status, the divergence
 * breakdown and the candidate PO and GRN ids.
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
 * One invoice routed to a three-way match exception status.
 *
 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
 */
final class ExceptionRoute {

	/**
	 * Divergence breakdown.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $divergences = [];

	/**
	 * Candidate PO ids (may be empty).
	 *
	 * @var array<int,string>
	 */
	public array $matchedPoIds = [];

	/**
	 * Candidate GRN ids (may be empty).
	 *
	 * @var array<int,string>
	 */
	public array $matchedGrnIds = [];

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $invoice     Loaded SupplierInvoice.
	 * @param string              $matchStatus One of the exception_* enum values.
	 */
	public function __construct(
		public readonly array $invoice,
		public readonly string $matchStatus,
	) {

	}//end __construct()
}//end class
