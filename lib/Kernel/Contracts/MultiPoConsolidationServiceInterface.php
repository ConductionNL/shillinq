<?php

/**
 * Contract of MultiPoConsolidationService
 *
 * The public surface of MultiPoConsolidationService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of MultiPoConsolidationService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MultiPoConsolidationServiceInterface {

	/**
	 * Orchestrate the full multi-PO consolidation fan-out for one invoice.
	 *
	 * For every invoice line:
	 *  - enumerate candidate (PO line, GRN line) tuples;
	 *  - zero candidates ⇒ write a ThreeWayMatch with
	 *    exception_missing_po so the exception workflow picks it up;
	 *  - exactly one candidate ⇒ write a ThreeWayMatch trio record (the
	 *    matching engine reclassifies on divergence);
	 *  - more than one candidate ⇒ skip the line (the controller surfaces
	 *    the candidates to the operator who later calls
	 *    disambiguateAmbiguousMatches()).
	 *
	 * The method returns one entry per invoice line so the caller can
	 * tell which trios materialised and which need disambiguation.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId SupplierInvoice id.
	 *
	 * @return array<int,array<string,mixed>> Per-line result, each entry
	 *                                        shape:
	 *                                        ['invoiceLineNumber' => int, 'status' => 'auto'|'pending'|'exception',
	 *                                        'matchId' => ?string, 'candidateCount' => int].
	 *
	 * @throws \RuntimeException On cross-tenant access or missing invoice.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function consolidateInvoice(string $administrationId, string $invoiceId): array;

	/**
	 * Record the operator's chosen candidate tuple for an ambiguous
	 * invoice line and persist the choice on the produced ThreeWayMatch.
	 *
	 * The caller (controller) supplies the SupplierInvoice id, the 1-based
	 * invoice line number and the chosen (poLineId, grnLineId) pair. The
	 * method:
	 *
	 *  1. validates the administration scope (ADR-005);
	 *  2. re-enumerates candidates server-side (never trusting the chosen
	 *     tuple supplied by the client without validation);
	 *  3. asserts the chosen pair is in the candidate set — a forged
	 *     POST is rejected with a RuntimeException the controller maps
	 *     to 400;
	 *  4. writes a ThreeWayMatch with the trio identifiers + the
	 *     disambiguationChoice snapshot (candidateCount, chosenPoLineId,
	 *     chosenGrnLineId, rejectedPoLineIds, chosenBy, chosenAt).
	 *
	 * The created record is the same shape the unambiguous path produces,
	 * so the engine in slice 06 evaluates both through the same tolerance
	 * path.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId SupplierInvoice id.
	 * @param int $invoiceLineNumber 1-based invoice-line position.
	 * @param string $chosenPoLineId The PO line the operator picked.
	 * @param string $chosenGrnLineId The GRN line the operator picked
	 *                                (may be empty when no GRN component).
	 *
	 * @return array<string,mixed> The persisted ThreeWayMatch record.
	 *
	 * @throws \RuntimeException On cross-tenant access, missing invoice,
	 *                           invoice-line out of range, or a chosen
	 *                           tuple that is not in the live candidate
	 *                           set.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function disambiguateAmbiguousMatches(
		string $administrationId,
		string $invoiceId,
		int $invoiceLineNumber,
		string $chosenPoLineId,
		string $chosenGrnLineId,
	): array;

	/**
	 * Enumerate candidate (PO line, GRN line) tuples for one invoice line.
	 *
	 * The method:
	 *  1. validates the administration scope (ADR-005);
	 *  2. resolves the supplier from the invoice header so the search is
	 *     scoped to PO records of the right supplier (a maand-factuur from
	 *     supplier A can never match a PO placed against supplier B);
	 *  3. enumerates PurchaseOrderLine rows for the supplier whose
	 *     productOrServiceCode matches the invoice line's productCode and
	 *     whose expectedDeliveryDate falls within DATE_PROXIMITY_DAYS of
	 *     the invoice date;
	 *  4. for each candidate PO line, looks up matching GoodsReceiptLine
	 *     rows (by poLineId) — there may be zero (missing-GRN exception)
	 *     or several (split deliveries) GRN lines per PO line; each (PO
	 *     line, GRN line) pair becomes a candidate tuple.
	 *
	 * The result is an ordered list of candidate tuples — the matching
	 * engine writes one ThreeWayMatch per unambiguous trio, and the
	 * disambiguation panel uses the full list when more than one
	 * candidate exists.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $invoice The SupplierInvoice header
	 *                                     (must carry supplierId
	 *                                     and invoiceDate so the
	 *                                     window can be anchored).
	 * @param array<string,mixed> $invoiceLine A single invoice line
	 *                                         with productCode and
	 *                                         optional lineNumber.
	 *
	 * @return array<int,array<string,mixed>> Ordered list of candidate
	 *                                        tuples — each entry is
	 *                                        ['poLineId' => string, 'poId' => string, 'grnLineId' => ?string,
	 *                                        'grnId' => ?string, 'productCode' => string,
	 *                                        'expectedDeliveryDate' => string, 'matchSource' => 'po-grn'|'po-only'].
	 *
	 * @throws \RuntimeException When the administration is inaccessible.
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function enumerateCandidateTuples(
		string $administrationId,
		array $invoice,
		array $invoiceLine,
	): array;
}//end interface
