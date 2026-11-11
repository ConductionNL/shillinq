<?php

/**
 * Candidate tuple search
 *
 * The scope of one candidate enumeration of MultiPoConsolidationService: the
 * administration and product code searched for, the supplier's purchase
 * orders and the invoice-date proximity window a PO line must fall in.
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
 * One search for (PO line, GRN line) candidates of an invoice line.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 */
final class CandidateTupleSearch {
	/**
	 * The supplier-scoped purchase order ids, as keys.
	 *
	 * @var array<string,true>
	 */
	public array $supplierPoIds = [];

	/**
	 * The expected-delivery-date window.
	 *
	 * @var array{from:mixed,to:mixed}
	 */
	public array $window = ['from' => null, 'to' => null];

	/**
	 * Start a search for a product in an administration.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $productCode      The invoice line's product code.
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $productCode,
	) {
	}//end __construct()

	/**
	 * A candidate tuple in its documented key order.
	 *
	 * @param array<string,mixed> $base The PO line fields (poLineId, poId, productCode, expectedDeliveryDate).
	 * @param array<string,mixed> $grn  The GRN fields (grnLineId, grnId, matchSource).
	 *
	 * @return array<string,mixed> The tuple.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function tuple(array $base, array $grn): array {
		return [
			'poLineId' => $base['poLineId'],
			'poId' => $base['poId'],
			'grnLineId' => $grn['grnLineId'],
			'grnId' => $grn['grnId'],
			'productCode' => $base['productCode'],
			'expectedDeliveryDate' => $base['expectedDeliveryDate'],
			'matchSource' => $grn['matchSource'],
		];
	}//end tuple()
}//end class
