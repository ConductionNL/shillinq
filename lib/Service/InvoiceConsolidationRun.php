<?php

/**
 * Invoice consolidation run
 *
 * The state of one MultiPoConsolidationService::consolidateInvoice() call: the
 * invoice being consolidated and the number of the line in hand.
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
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Progress of consolidating the lines of one supplier invoice.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 */
final class InvoiceConsolidationRun {
	/**
	 * The SupplierInvoice.
	 *
	 * @var array<string,mixed>
	 */
	public array $invoice = [];

	/**
	 * The 1-based number of the line in hand.
	 *
	 * @var int
	 */
	public int $lineNumber = 0;

	/**
	 * Start consolidating an invoice.
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
	 * The per-line result entry.
	 *
	 * @param array<string,mixed> $outcome status, matchId and candidateCount.
	 *
	 * @return array<string,mixed> The result, invoiceLineNumber first.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function result(array $outcome): array {
		return ['invoiceLineNumber' => $this->lineNumber] + $outcome;
	}//end result()
}//end class
