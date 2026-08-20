<?php

/**
 * Multi-PO Consolidation Service
 *
 * Slice 07 of the bookkeeping-purchase-order-3way chain — extends the
 * matching engine (slice 06) to handle one supplier invoice whose lines
 * consolidate items from multiple POs / GRNs (REQ-PO3W-007). The service:
 *
 *  - enumerates candidate (PO line, GRN line) tuples per invoice line by
 *    product_code + date proximity (within a 30-day window);
 *  - presents ambiguous candidates to the crediteuren-administrateur and
 *    persists the operator's choice on the ThreeWayMatch record;
 *  - creates ONE ThreeWayMatch per matched (PO line, GRN line, invoice line)
 *    trio so each trio is processed independently through the slice-06
 *    tolerance path.
 *
 * The matching engine, when present (slice 06), evaluates each per-trio
 * ThreeWayMatch through its tolerance profile so some trios auto-approve
 * and others route to exception. This service is deliberately decoupled
 * from the engine class — it writes ThreeWayMatch records in a status the
 * engine recognises and the engine picks them up. Until the engine lands
 * the persisted records carry a server-default match_status of
 * `auto_approved` for unambiguous trios and an `exception_*` status for the
 * non-matching cases (missing GRN, missing PO) so downstream exception
 * resolution in slice 08 has a deterministic surface.
 *
 * All reads/writes go through OpenRegister's real ObjectService API
 * (find / findAll / saveObject); the methods `findObject` /
 * `createFromArray` / `deleteFromId` do NOT exist and are never used
 * ([[or-objectservice-api]]). Money is integer euro cents (ADR-022);
 * dates are ISO-8601 strings — date arithmetic uses DateTimeImmutable so
 * the 30-day window is computed deterministically and time-zone safe.
 *
 * Administration scope is validated server-side by
 * AdministrationContextService — cross-tenant calls are masked as 404 so
 * the candidate enumeration cannot leak PO/GRN ids across tenants (ADR-005
 * IDOR-safe). The persisted disambiguationChoice is also validated against
 * the live candidate set the engine produces so an attacker cannot post a
 * forged PO line id past the controller.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Slice 07 — multi-PO consolidated invoice matching.
 *
 * Public methods:
 *  - enumerateCandidateTuples(): per invoice line, return all candidate
 *    (PO line, GRN line) tuples reachable for the supplier within the
 *    date-proximity window;
 *  - disambiguateAmbiguousMatches(): record the operator's chosen tuple
 *    for an ambiguous invoice line; idempotent + server-validated;
 *  - consolidateInvoice(): orchestrates the full fan-out — for every
 *    invoice line, picks the unambiguous match OR the recorded
 *    disambiguation choice and writes one ThreeWayMatch per matched trio.
 *
 * Every persisted ThreeWayMatch is also projected back onto the
 * SupplierInvoice document via {@see SupplierInvoiceService::linkInvoiceLineToPo()}
 * so the embedded invoice line carries linkedPoLineId / linkedPoId /
 * linkedGrnLineId / linkedMatchId inline and the header's matchedPoIds +
 * consolidatedAt reflect the full set of POs the maand-factuur consolidates
 * (REQ-PO3W-007). The inline projection is best-effort — a write failure
 * is logged but does not roll back the ThreeWayMatch record, which is the
 * canonical surface for the matching engine in slice 06.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)           Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
 */
class MultiPoConsolidationService {
	/**
	 * Date-proximity window for candidate enumeration (REQ-PO3W-007 D9).
	 *
	 * @var int
	 */
	public const DATE_PROXIMITY_DAYS = 30;

	/**
	 * Match status the consolidation writes when a trio is matched
	 * unambiguously and the engine should pick it up for tolerance
	 * evaluation. The slice-01 ThreeWayMatch lifecycle uses
	 * `auto_approved` as the initial state, so writing it here keeps the
	 * record state machine consistent with the schema while the slice-06
	 * engine subsequently reclassifies on divergence.
	 *
	 * @var string
	 */
	public const MATCH_STATUS_AUTO_APPROVED = 'auto_approved';

	/**
	 * Match status for an invoice line that has no candidate PO/GRN tuple.
	 * Maps to exception_missing_po (the REQ-MATCH-002 enum value) so the
	 * exception workflow in slice 08 surfaces it for resolution.
	 *
	 * @var string
	 */
	public const MATCH_STATUS_MISSING_PO = 'exception_missing_po';

	/**
	 * Match status for an invoice line whose PO candidate exists but no
	 * matching GRN line within the date window (services / pre-receipt
	 * invoice scenarios).
	 *
	 * @var string
	 */
	public const MATCH_STATUS_MISSING_GRN = 'exception_missing_grn';

	/**
	 * Schema slug for supplier invoice records (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_SUPPLIER_INVOICE = 'SupplierInvoice';

	/**
	 * Schema slug for purchase orders (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_PURCHASE_ORDER = 'PurchaseOrder';

	/**
	 * Schema slug for purchase-order lines (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_PO_LINE = 'PurchaseOrderLine';

	/**
	 * Schema slug for goods-receipt lines (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_GRN_LINE = 'GoodsReceiptLine';

	/**
	 * Schema slug for the three-way match register (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_THREE_WAY_MATCH = 'ThreeWayMatch';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for the OR register slug.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope (ADR-005).
	 * @param IUserSession $userSession Session for chosenBy attribution.
	 * @param SupplierInvoiceService $supplierInvoiceService Slice 05 service — extended in slice 07
	 *                                                       with linkInvoiceLineToPo() so the
	 *                                                       SupplierInvoice document records its
	 *                                                       per-line PO links inline (REQ-PO3W-007).
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly IUserSession $userSession,
		private readonly SupplierInvoiceService $supplierInvoiceService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

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
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
	 */
	public function enumerateCandidateTuples(
		string $administrationId,
		array $invoice,
		array $invoiceLine,
	): array {
		$this->assertAccess(administrationId: $administrationId);

		$supplierId = trim((string)($invoice['supplierId'] ?? ''));
		if ($supplierId === '') {
			return [];
		}

		$productCode = trim((string)($invoiceLine['productCode'] ?? ''));
		if ($productCode === '') {
			// Without a product code we cannot enumerate candidates — this
			// is the missing-PO exception path (the caller writes the
			// exception_missing_po ThreeWayMatch directly).
			return [];
		}

		$invoiceDate = trim((string)($invoice['invoiceDate'] ?? ''));
		$window = $this->dateWindow(anchor: $invoiceDate, days: self::DATE_PROXIMITY_DAYS);

		// Step 1 — supplier-scoped POs.
		$supplierPos = $this->findAll(
			schema: self::SCHEMA_PURCHASE_ORDER,
			filters: [
				'administrationId' => $administrationId,
				'supplierId' => $supplierId,
			]
		);
		if ($supplierPos === []) {
			return [];
		}

		$supplierPoIds = [];
		foreach ($supplierPos as $purchaseOrder) {
			$supplierPoIds[$this->idOf(record: $purchaseOrder)] = true;
		}

		// Step 2 — PO lines for the product, scoped to supplier POs and
		// within the date window. We pull PO lines on the product code
		// and reject those whose poId is not in the supplier-scoped set
		// OR whose expectedDeliveryDate is outside the window.
		$candidatePoLines = $this->findAll(
			schema: self::SCHEMA_PO_LINE,
			filters: [
				'administrationId' => $administrationId,
				'productOrServiceCode' => $productCode,
			]
		);

		$tuples = [];
		foreach ($candidatePoLines as $poLine) {
			$poId = trim((string)($poLine['poId'] ?? ''));
			if ($poId === '' || isset($supplierPoIds[$poId]) === false) {
				continue;
			}

			$deliveryDate = trim((string)($poLine['expectedDeliveryDate'] ?? ''));
			if ($this->withinWindow(date: $deliveryDate, window: $window) === false) {
				continue;
			}

			$poLineId = $this->idOf(record: $poLine);
			if ($poLineId === '') {
				continue;
			}

			// Step 3 — GRN lines back-referencing this PO line.
			$grnLines = $this->findAll(
				schema: self::SCHEMA_GRN_LINE,
				filters: [
					'administrationId' => $administrationId,
					'poLineId' => $poLineId,
				]
			);

			if ($grnLines === []) {
				// PO-only candidate (no GRN yet — services or pre-receipt).
				$tuples[] = [
					'poLineId' => $poLineId,
					'poId' => $poId,
					'grnLineId' => null,
					'grnId' => null,
					'productCode' => $productCode,
					'expectedDeliveryDate' => $deliveryDate,
					'matchSource' => 'po-only',
				];
				continue;
			}

			foreach ($grnLines as $grnLine) {
				$grnLineId = $this->idOf(record: $grnLine);
				if ($grnLineId === '') {
					continue;
				}

				$tuples[] = [
					'poLineId' => $poLineId,
					'poId' => $poId,
					'grnLineId' => $grnLineId,
					'grnId' => trim((string)($grnLine['grnId'] ?? '')),
					'productCode' => $productCode,
					'expectedDeliveryDate' => $deliveryDate,
					'matchSource' => 'po-grn',
				];
			}
		}//end foreach

		return $tuples;
	}//end enumerateCandidateTuples()

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
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
	 */
	public function disambiguateAmbiguousMatches(
		string $administrationId,
		string $invoiceId,
		int $invoiceLineNumber,
		string $chosenPoLineId,
		string $chosenGrnLineId,
	): array {
		$this->assertAccess(administrationId: $administrationId);

		if ($invoiceId === '') {
			throw new RuntimeException('Supplier invoice not found');
		}

		if ($invoiceLineNumber < 1) {
			throw new RuntimeException('Invalid invoiceLineNumber');
		}

		if ($chosenPoLineId === '') {
			throw new RuntimeException('chosenPoLineId is required');
		}

		$invoice = $this->findOne(
			schema: self::SCHEMA_SUPPLIER_INVOICE,
			filters: [
				'id' => $invoiceId,
				'administrationId' => $administrationId,
			]
		);
		if ($invoice === null) {
			throw new RuntimeException('Supplier invoice not found');
		}

		$invoiceLine = $this->locateInvoiceLine(
			invoice: $invoice,
			invoiceLineNumber: $invoiceLineNumber
		);
		if ($invoiceLine === null) {
			throw new RuntimeException('Invoice line not found');
		}

		$candidates = $this->enumerateCandidateTuples(
			administrationId: $administrationId,
			invoice: $invoice,
			invoiceLine: $invoiceLine
		);

		$chosen = null;
		$rejectedPoLineIds = [];
		foreach ($candidates as $candidate) {
			$sameLine = ((string)$candidate['poLineId'] === $chosenPoLineId);
			if ($chosenGrnLineId === '') {
				$sameGrn = ($candidate['grnLineId'] === null || $candidate['grnLineId'] === '');
			} else {
				$sameGrn = ((string)($candidate['grnLineId'] ?? '') === $chosenGrnLineId);
			}

			if ($sameLine === true && $sameGrn === true) {
				$chosen = $candidate;
				continue;
			}

			if ($sameLine === false) {
				$rejectedPoLineIds[(string)$candidate['poLineId']] = true;
			}
		}

		if ($chosen === null) {
			throw new RuntimeException('Chosen tuple is not in the candidate set');
		}

		$persistedGrnLineId = null;
		if ($chosenGrnLineId !== '') {
			$persistedGrnLineId = $chosenGrnLineId;
		}

		$disambiguationChoice = [
			'candidateCount' => count($candidates),
			'chosenPoLineId' => $chosenPoLineId,
			'chosenGrnLineId' => $persistedGrnLineId,
			// `array_keys()` already returns a list.
			'rejectedPoLineIds' => array_keys($rejectedPoLineIds),
			'chosenBy' => $this->currentUserId(),
			'chosenAt' => $this->nowIso(),
		];

		return $this->writeMatchRecord(
			administrationId: $administrationId,
			invoiceId: $invoiceId,
			invoiceLineNumber: $invoiceLineNumber,
			candidate: $chosen,
			disambiguationChoice: $disambiguationChoice,
			matchStatus: self::MATCH_STATUS_AUTO_APPROVED
		);

	}//end disambiguateAmbiguousMatches()

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
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
	 */
	public function consolidateInvoice(string $administrationId, string $invoiceId): array {
		$this->assertAccess(administrationId: $administrationId);

		if ($invoiceId === '') {
			throw new RuntimeException('Supplier invoice not found');
		}

		$invoice = $this->findOne(
			schema: self::SCHEMA_SUPPLIER_INVOICE,
			filters: [
				'id' => $invoiceId,
				'administrationId' => $administrationId,
			]
		);
		if ($invoice === null) {
			throw new RuntimeException('Supplier invoice not found');
		}

		$lines = $this->invoiceLines(invoice: $invoice);
		if ($lines === []) {
			return [];
		}

		$results = [];
		foreach ($lines as $lineIndex => $line) {
			$lineNumber = (int)($line['lineNumber'] ?? ($lineIndex + 1));

			$candidates = $this->enumerateCandidateTuples(
				administrationId: $administrationId,
				invoice: $invoice,
				invoiceLine: $line
			);

			if ($candidates === []) {
				$missing = $this->writeMatchRecord(
					administrationId: $administrationId,
					invoiceId: $invoiceId,
					invoiceLineNumber: $lineNumber,
					candidate: [
						'poLineId' => null,
						'poId' => null,
						'grnLineId' => null,
						'grnId' => null,
						'productCode' => trim((string)($line['productCode'] ?? '')),
					],
					disambiguationChoice: null,
					matchStatus: self::MATCH_STATUS_MISSING_PO
				);

				$results[] = [
					'invoiceLineNumber' => $lineNumber,
					'status' => 'exception',
					'matchId' => $this->idOf(record: $missing),
					'candidateCount' => 0,
				];
				continue;
			}//end if

			if (count($candidates) > 1) {
				// Ambiguous — surface to the operator; no ThreeWayMatch yet.
				$results[] = [
					'invoiceLineNumber' => $lineNumber,
					'status' => 'pending',
					'matchId' => null,
					'candidateCount' => count($candidates),
				];
				continue;
			}

			$candidate = $candidates[0];
			$matchStatus = self::MATCH_STATUS_AUTO_APPROVED;
			if ($candidate['grnLineId'] === null) {
				$matchStatus = self::MATCH_STATUS_MISSING_GRN;
			}

			$persisted = $this->writeMatchRecord(
				administrationId: $administrationId,
				invoiceId: $invoiceId,
				invoiceLineNumber: $lineNumber,
				candidate: $candidate,
				disambiguationChoice: null,
				matchStatus: $matchStatus
			);

			$lineStatus = 'exception';
			if ($matchStatus === self::MATCH_STATUS_AUTO_APPROVED) {
				$lineStatus = 'auto';
			}

			$results[] = [
				'invoiceLineNumber' => $lineNumber,
				'status' => $lineStatus,
				'matchId' => $this->idOf(record: $persisted),
				'candidateCount' => 1,
			];
		}//end foreach

		return $results;
	}//end consolidateInvoice()

	/**
	 * Persist one ThreeWayMatch record for a (PO line, GRN line, invoice
	 * line) trio.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId SupplierInvoice id.
	 * @param int $invoiceLineNumber 1-based line position.
	 * @param array<string,mixed> $candidate Tuple snapshot.
	 * @param array<string,mixed>|null $disambiguationChoice Operator's choice (null when unambiguous).
	 * @param string $matchStatus Initial match status.
	 *
	 * @return array<string,mixed> The persisted match record.
	 */
	private function writeMatchRecord(
		string $administrationId,
		string $invoiceId,
		int $invoiceLineNumber,
		array $candidate,
		?array $disambiguationChoice,
		string $matchStatus,
	): array {
		$matchedPoIds = [];
		if (isset($candidate['poId']) === true && $candidate['poId'] !== '') {
			$matchedPoIds[] = (string)$candidate['poId'];
		}

		$matchedGrnIds = [];
		if (isset($candidate['grnId']) === true && $candidate['grnId'] !== '') {
			$matchedGrnIds[] = (string)$candidate['grnId'];
		}

		$record = [
			'invoiceId' => $invoiceId,
			'invoiceLineNumber' => $invoiceLineNumber,
			'productCode' => ($candidate['productCode'] ?? null),
			'poLineId' => ($candidate['poLineId'] ?? null),
			'grnLineId' => ($candidate['grnLineId'] ?? null),
			'matchedPoIds' => $matchedPoIds,
			'matchedGrnIds' => $matchedGrnIds,
			'matchStatus' => $matchStatus,
			'divergenceDetails' => [],
			'disambiguationChoice' => $disambiguationChoice,
			'createdAt' => $this->nowIso(),
			'administrationId' => $administrationId,
		];

		$persisted = $this->saveObject(schema: self::SCHEMA_THREE_WAY_MATCH, object: $record);

		// Slice 07 — write the PO link back to the SupplierInvoice document so
		// a consumer reading the invoice sees the per-line PO links inline
		// (REQ-PO3W-007). On the missing-PO exception path the link fields
		// are NULL but the matchId is still recorded so the UI can show the
		// exception status without traversing ThreeWayMatch records.
		$matchId = $this->idOf(record: $persisted);
		$linkedPoLineId = ($candidate['poLineId'] ?? null);
		$linkedPoId = ($candidate['poId'] ?? null);
		$linkedGrnLineId = ($candidate['grnLineId'] ?? null);

		$projectedPoLineId = null;
		$projectedPoId = null;
		$projectedGrnLineId = null;
		$projectedMatchId = null;
		if ($linkedPoLineId !== null) {
			$projectedPoLineId = (string)$linkedPoLineId;
		}

		if ($linkedPoId !== null) {
			$projectedPoId = (string)$linkedPoId;
		}

		if ($linkedGrnLineId !== null) {
			$projectedGrnLineId = (string)$linkedGrnLineId;
		}

		if ($matchId !== '') {
			$projectedMatchId = $matchId;
		}

		try {
			$this->supplierInvoiceService->linkInvoiceLineToPo(
				administrationId: $administrationId,
				invoiceId: $invoiceId,
				invoiceLineNumber: $invoiceLineNumber,
				linkedPoLineId: $projectedPoLineId,
				linkedPoId: $projectedPoId,
				linkedGrnLineId: $projectedGrnLineId,
				linkedMatchId: $projectedMatchId
			);
		} catch (\Throwable $e) {
			// Inline-link is a best-effort projection — the canonical
			// record is the ThreeWayMatch we just persisted, so a failure
			// to update the invoice document MUST NOT roll back the
			// engine. Log + continue.
			$this->logger->warning(
				'MultiPoConsolidationService: failed to link invoice line to PO',
				[
					'invoiceId' => $invoiceId,
					'invoiceLineNumber' => $invoiceLineNumber,
					'exception' => $e->getMessage(),
				]
			);
		}//end try

		return $persisted;
	}//end writeMatchRecord()

	/**
	 * Extract the embedded invoice lines from a SupplierInvoice record.
	 *
	 * Slice 05 stores parsed UBL/OCR lines on the invoice document as the
	 * `lines` array. This helper accepts either the embedded form or a
	 * separately-keyed `invoiceLines` array so the service stays
	 * forward-compatible with a future move to a SupplierInvoiceLine
	 * register.
	 *
	 * @param array<string,mixed> $invoice The persisted invoice.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function invoiceLines(array $invoice): array {
		$lines = ($invoice['lines'] ?? $invoice['invoiceLines'] ?? []);
		if (is_array($lines) === false) {
			return [];
		}

		$result = [];
		foreach ($lines as $line) {
			if (is_array($line) === true) {
				$result[] = $line;
			}
		}

		return $result;
	}//end invoiceLines()

	/**
	 * Locate a single invoice line by 1-based lineNumber.
	 *
	 * @param array<string,mixed> $invoice Invoice header.
	 * @param int $invoiceLineNumber 1-based position.
	 *
	 * @return array<string,mixed>|null
	 */
	private function locateInvoiceLine(array $invoice, int $invoiceLineNumber): ?array {
		$lines = $this->invoiceLines(invoice: $invoice);
		foreach ($lines as $index => $line) {
			$lineNumber = (int)($line['lineNumber'] ?? ($index + 1));
			if ($lineNumber === $invoiceLineNumber) {
				return $line;
			}
		}

		return null;
	}//end locateInvoiceLine()

	/**
	 * Compute the inclusive [from, to] date window around an anchor date.
	 *
	 * @param string $anchor Anchor date (ISO-8601 or YYYY-MM-DD).
	 * @param int $days Window radius in days.
	 *
	 * @return array{from:?DateTimeImmutable,to:?DateTimeImmutable}
	 */
	private function dateWindow(string $anchor, int $days): array {
		if ($anchor === '') {
			return ['from' => null, 'to' => null];
		}

		try {
			$center = new DateTimeImmutable($anchor);
		} catch (\Throwable $e) {
			return ['from' => null, 'to' => null];
		}

		$interval = new DateInterval('P' . $days . 'D');

		return [
			'from' => $center->sub($interval),
			'to' => $center->add($interval),
		];

	}//end dateWindow()

	/**
	 * Check whether a date string is within the inclusive window.
	 *
	 * If the anchor or candidate date is missing the function returns
	 * true (the missing-date case is handled by the exception workflow
	 * downstream — we do not silently drop candidates here).
	 *
	 * @param string $date Candidate date.
	 * @param array{from:?DateTimeImmutable,to:?DateTimeImmutable} $window Window.
	 *
	 * @return bool
	 */
	private function withinWindow(string $date, array $window): bool {
		if ($date === '') {
			return true;
		}

		if ($window['from'] === null || $window['to'] === null) {
			return true;
		}

		try {
			$candidate = new DateTimeImmutable($date);
		} catch (\Throwable $e) {
			return true;
		}

		if ($candidate < $window['from']) {
			return false;
		}

		if ($candidate > $window['to']) {
			return false;
		}

		return true;
	}//end withinWindow()

	/**
	 * Extract a record id, handling both top-level `id` and OR's
	 * `@self.id` envelope.
	 *
	 * @param array<string,mixed> $record OR record.
	 *
	 * @return string
	 */
	private function idOf(array $record): string {
		$id = ($record['id'] ?? ($record['@self']['id'] ?? ''));
		return trim((string)$id);
	}//end idOf()

	/**
	 * Validate the administration scope.
	 *
	 * @param string $administrationId Tenant id.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the administration is inaccessible.
	 */
	private function assertAccess(string $administrationId): void {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Administration not found');
		}

	}//end assertAccess()

	/**
	 * Resolve the current user id, falling back to 'system' when no user
	 * session is bound (e.g. inside a background job).
	 *
	 * @return string
	 */
	private function currentUserId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end currentUserId()

	/**
	 * Persist via the real ObjectService API (saveObject).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object Object to persist.
	 *
	 * @return array<string,mixed>
	 */
	private function saveObject(string $schema, array $object): array {
		try {
			$result = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->saveObject($object);

			// ADR-084: saveObject() is declared `: ObjectEntityInterface`, so the
			// is_array() arm here was unreachable by type and this helper returned
			// the INPUT on every save — silently discarding the id/uuid the store
			// had just generated, which callers then read back as empty.
			return (array)$result->jsonSerialize();
		} catch (\Throwable $e) {
			$this->logger->error(
				'MultiPoConsolidationService: failed to persist object',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist ' . $schema);
		}

	}//end saveObject()

	/**
	 * Fetch one record via the real ObjectService API (findAll then first).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findOne(string $schema, array $filters): ?array {
		$rows = $this->findAll(schema: $schema, filters: $filters);
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				return $row;
			}
		}

		return null;
	}//end findOne()

	/**
	 * Fetch all records via the real ObjectService API (findAll).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $filters Equality filters.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function findAll(string $schema, array $filters): array {
		try {
			$rows = $this->objectService
				->setRegister($this->register())
				->setSchema($schema)
				->findAll(['filters' => $filters]);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MultiPoConsolidationService: failed to query OpenRegister',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$result = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$result[] = $row;
			}
		}

		return $result;
	}//end findAll()

	/**
	 * Resolve the OR register slug from app config (defaults to "shillinq").
	 *
	 * @return string
	 */
	private function register(): string {
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()

	/**
	 * Current timestamp in ISO-8601 (Y-m-d\TH:i:sP) — server-authoritative.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return date('c');
	}//end nowIso()
}//end class
