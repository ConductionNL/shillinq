<?php

/**
 * Credit-note dispatch port for the 3-way-match exception workflow.
 *
 * Slice 08 of the bookkeeping-purchase-order-3way chain — when the
 * crediteuren-administrateur files a dispute on an out-of-tolerance
 * ThreeWayMatch the exception workflow auto-generates a UBL CreditNote
 * request and hands it to openconnector for transmission to the supplier
 * (REQ-PO3W-005). The dispatch itself is wrapped behind this narrow port so
 * the orchestration code stays decoupled from openconnector's HTTP surface
 * and unit-testable against a logging stub.
 *
 * The slice-08 production binding is {@see LogCreditNoteRequestAdapter},
 * which records the dispatch attempt against the application log and lets
 * the upstream openconnector configuration take over once it is provisioned.
 * Following the Peppol-transmission port pattern (slice 03) the live
 * adapter will land as part of the openconnector wiring change and re-use
 * the same interface; nothing in the calling service changes.
 *
 * @category Service
 * @package  OCA\Shillinq\Service\PurchaseOrder
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service\PurchaseOrder;

/**
 * Credit-note request dispatch port. One method = one dispatch attempt.
 *
 * The payload shape is the canonical UBL 2.1 CreditNote skeleton the
 * orchestration service composes from the disputed match + the invoice +
 * the PO; the port implementation owns the wire format (UBL XML body,
 * envelope metadata) and the HTTP transport.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
 */
interface CreditNoteRequestAdapterInterface {
	/**
	 * Submit one UBL CreditNote dispute request through openconnector.
	 *
	 * @param array<string,mixed> $payload The dispute payload — keys:
	 *                                     - matchId            string
	 *                                     - invoiceId          string
	 *                                     - invoiceNumber      string
	 *                                     - supplierId         string
	 *                                     - administrationId   string
	 *                                     - currency           string (ISO 4217)
	 *                                     - totalExclVat       int   (cents)
	 *                                     - totalVat           int   (cents)
	 *                                     - totalInclVat       int   (cents)
	 *                                     - reason             string
	 *                                     - divergenceDetails  array
	 *                                     - matchedPoIds       array<int,string>
	 *                                     - requestedAt        string (ISO-8601).
	 *
	 * @return array{accepted:bool,dispatchId:?string,error:?string}
	 *                                                               An outcome envelope — accepted=true with the openconnector
	 *                                                               dispatchId on success; accepted=false with a non-null error
	 *                                                               when the adapter refused to dispatch. The caller logs the
	 *                                                               outcome on the ThreeWayMatch but never blocks the
	 *                                                               resolution flow on the dispatch outcome.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-08-exception-workflow/tasks.md
	 */
	public function submitDisputeCreditNote(array $payload): array;
}//end interface
