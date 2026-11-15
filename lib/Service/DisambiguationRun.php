<?php

/**
 * Disambiguation run
 *
 * The state of one MultiPoConsolidationService::disambiguateAmbiguousMatches()
 * call: the invoice line and the operator's chosen pair, the live candidate
 * set, the candidate that matches the choice and the PO lines it rejects.
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
 * Progress of recording an operator's choice for an ambiguous invoice line.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 */
final class DisambiguationRun {
	/**
	 * The 1-based invoice line position.
	 *
	 * @var int
	 */
	public int $invoiceLineNumber = 0;

	/**
	 * The PO line the operator picked.
	 *
	 * @var string
	 */
	public string $chosenPoLineId = '';

	/**
	 * The GRN line the operator picked ('' when none).
	 *
	 * @var string
	 */
	public string $chosenGrnLineId = '';

	/**
	 * The live candidate set.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public array $candidates = [];

	/**
	 * The candidate matching the choice.
	 *
	 * @var array<string,mixed>|null
	 */
	public ?array $chosen = null;

	/**
	 * The PO lines the choice rejects, as keys.
	 *
	 * @var array<string,true>
	 */
	public array $rejectedPoLineIds = [];

	/**
	 * Start recording a choice for an invoice.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId        SupplierInvoice id.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $invoiceId,
	) {
	}//end __construct()
}//end class
