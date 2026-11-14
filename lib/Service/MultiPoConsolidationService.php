<?php

/**
 * Multi-PO Consolidation Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\MultiPoConsolidationServiceInterface;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
 */
class MultiPoConsolidationService implements MultiPoConsolidationServiceInterface {
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
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-007
	 */
	public function enumerateCandidateTuples(
		string $administrationId,
		array $invoice,
		array $invoiceLine,
	): array {
		$this->assertAccess(administrationId: $administrationId);

		$supplierId = trim((string)($invoice['supplierId'] ?? ''));
		$productCode = trim((string)($invoiceLine['productCode'] ?? ''));
		if ($supplierId === '' || $productCode === '') {
			// Without a product code we cannot enumerate candidates — this
			// is the missing-PO exception path (the caller writes the
			// exception_missing_po ThreeWayMatch directly).
			return [];
		}

		$search = new CandidateTupleSearch(administrationId: $administrationId, productCode: $productCode);
		$search->window = $this->dateWindow(anchor: trim((string)($invoice['invoiceDate'] ?? '')), days: self::DATE_PROXIMITY_DAYS);
		$search->supplierPoIds = $this->supplierPoIds(administrationId: $administrationId, supplierId: $supplierId);
		if ($search->supplierPoIds === []) {
			return [];
		}

		return $this->candidateTuples(search: $search);
	}//end enumerateCandidateTuples()

	/**
	 * Step 1 — the ids of the supplier's purchase orders, as keys.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $supplierId       The invoice's supplier.
	 *
	 * @return array<string,true> The supplier PO ids, or [] when there are none.
	 */
	private function supplierPoIds(string $administrationId, string $supplierId): array {
		$supplierPos = $this->findAll(
			schema: self::SCHEMA_PURCHASE_ORDER,
			filters: [
				'administrationId' => $administrationId,
				'supplierId' => $supplierId,
			]
		);

		$supplierPoIds = [];
		foreach ($supplierPos as $purchaseOrder) {
			$supplierPoIds[$this->idOf(record: $purchaseOrder)] = true;
		}

		return $supplierPoIds;
	}//end supplierPoIds()

	/**
	 * Step 2 — PO lines for the product, scoped to the supplier POs and
	 * within the date window, each with its GRN-line candidates. PO lines
	 * are pulled on the product code; those whose poId is not in the
	 * supplier-scoped set or whose expectedDeliveryDate is outside the
	 * window are rejected.
	 *
	 * @param CandidateTupleSearch $search The search.
	 *
	 * @return array<int,array<string,mixed>> The candidate tuples.
	 */
	private function candidateTuples(CandidateTupleSearch $search): array {
		$candidatePoLines = $this->findAll(
			schema: self::SCHEMA_PO_LINE,
			filters: [
				'administrationId' => $search->administrationId,
				'productOrServiceCode' => $search->productCode,
			]
		);

		$tuples = [];
		foreach ($candidatePoLines as $poLine) {
			if ($this->isCandidatePoLine(search: $search, poLine: $poLine) === true) {
				$tuples = array_merge($tuples, $this->poLineTuples(search: $search, poLine: $poLine));
			}
		}

		return $tuples;
	}//end candidateTuples()

	/**
	 * True when a PO line belongs to a supplier PO, falls in the date window
	 * and has an id.
	 *
	 * @param CandidateTupleSearch $search The search.
	 * @param array<string,mixed>  $poLine The PO line.
	 *
	 * @return bool Whether the PO line is a candidate.
	 */
	private function isCandidatePoLine(CandidateTupleSearch $search, array $poLine): bool {
		$poId = trim((string)($poLine['poId'] ?? ''));
		if ($poId === '' || isset($search->supplierPoIds[$poId]) === false) {
			return false;
		}

		$deliveryDate = trim((string)($poLine['expectedDeliveryDate'] ?? ''));
		if ($this->withinWindow(date: $deliveryDate, window: $search->window) === false) {
			return false;
		}

		return $this->idOf(record: $poLine) !== '';
	}//end isCandidatePoLine()

	/**
	 * Step 3 — the tuples of one candidate PO line: one per GRN line that
	 * back-references it, or a single PO-only tuple when there is none
	 * (services or pre-receipt).
	 *
	 * @param CandidateTupleSearch $search The search.
	 * @param array<string,mixed>  $poLine The candidate PO line.
	 *
	 * @return array<int,array<string,mixed>> The PO line's tuples.
	 */
	private function poLineTuples(CandidateTupleSearch $search, array $poLine): array {
		$base = [
			'poLineId' => $this->idOf(record: $poLine),
			'poId' => trim((string)($poLine['poId'] ?? '')),
			'productCode' => $search->productCode,
			'expectedDeliveryDate' => trim((string)($poLine['expectedDeliveryDate'] ?? '')),
		];
		$grnLines = $this->findAll(
			schema: self::SCHEMA_GRN_LINE,
			filters: [
				'administrationId' => $search->administrationId,
				'poLineId' => $base['poLineId'],
			]
		);
		$tuples = [];
		foreach ($this->grnFields(grnLines: $grnLines) as $grn) {
			$tuples[] = $search->tuple(base: $base, grn: $grn);
		}

		return $tuples;
	}//end poLineTuples()

	/**
	 * The GRN side of a PO line's tuples: one po-grn entry per GRN line with
	 * an id, or a single po-only entry when no GRN line back-references it.
	 *
	 * @param array<int,array<string,mixed>> $grnLines The GRN lines back-referencing the PO line.
	 *
	 * @return array<int,array<string,mixed>> grnLineId, grnId and matchSource per tuple.
	 */
	private function grnFields(array $grnLines): array {
		if ($grnLines === []) {
			return [['grnLineId' => null, 'grnId' => null, 'matchSource' => 'po-only']];
		}

		$fields = [];
		foreach ($grnLines as $grnLine) {
			$grnLineId = $this->idOf(record: $grnLine);
			if ($grnLineId !== '') {
				$fields[] = ['grnLineId' => $grnLineId, 'grnId' => trim((string)($grnLine['grnId'] ?? '')), 'matchSource' => 'po-grn'];
			}
		}

		return $fields;
	}//end grnFields()

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
	): array {
		$this->assertAccess(administrationId: $administrationId);

		$run = new DisambiguationRun(administrationId: $administrationId, invoiceId: $invoiceId);
		$run->invoiceLineNumber = $invoiceLineNumber;
		$run->chosenPoLineId = $chosenPoLineId;
		$run->chosenGrnLineId = $chosenGrnLineId;
		return $this->recordChoice(run: $run);

	}//end disambiguateAmbiguousMatches()

	/**
	 * Validate the choice, re-enumerate the live candidates, pick the chosen
	 * one and write its ThreeWayMatch with the choice snapshot.
	 *
	 * @param DisambiguationRun $run The disambiguation run.
	 *
	 * @return array<string,mixed> The persisted ThreeWayMatch record.
	 *
	 * @throws \RuntimeException On an invalid request, missing invoice or line, or a forged choice.
	 */
	private function recordChoice(DisambiguationRun $run): array {
		$this->assertChoiceRequest(run: $run);
		$invoice = $this->requireInvoice(administrationId: $run->administrationId, invoiceId: $run->invoiceId);
		$invoiceLine = $this->locateInvoiceLine(invoice: $invoice, invoiceLineNumber: $run->invoiceLineNumber);
		if ($invoiceLine === null) {
			throw new RuntimeException('Invoice line not found');
		}

		$run->candidates = $this->enumerateCandidateTuples(
			administrationId: $run->administrationId,
			invoice: $invoice,
			invoiceLine: $invoiceLine
		);
		$this->pickChosenCandidate(run: $run);

		$lineMatch = new InvoiceLineMatch(administrationId: $run->administrationId, invoiceId: $run->invoiceId);
		$lineMatch->candidate = (array)$run->chosen;
		$lineMatch->invoiceLineNumber = $run->invoiceLineNumber;
		$lineMatch->disambiguationChoice = $this->choiceSnapshot(run: $run);
		$lineMatch->matchStatus = self::MATCH_STATUS_AUTO_APPROVED;
		return $this->writeMatchRecord(lineMatch: $lineMatch);
	}//end recordChoice()

	/**
	 * Refuse a choice without invoice, with an invalid line number or
	 * without a chosen PO line.
	 *
	 * @param DisambiguationRun $run The disambiguation run.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the request is invalid.
	 */
	private function assertChoiceRequest(DisambiguationRun $run): void {
		if ($run->invoiceId === '') {
			throw new RuntimeException('Supplier invoice not found');
		}

		if ($run->invoiceLineNumber < 1) {
			throw new RuntimeException('Invalid invoiceLineNumber');
		}

		if ($run->chosenPoLineId === '') {
			throw new RuntimeException('chosenPoLineId is required');
		}
	}//end assertChoiceRequest()

	/**
	 * Find the candidate matching the chosen (PO line, GRN line) pair and
	 * collect the PO lines the choice rejects.
	 *
	 * @param DisambiguationRun $run The disambiguation run.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the chosen pair is not in the candidate set.
	 */
	private function pickChosenCandidate(DisambiguationRun $run): void {
		foreach ($run->candidates as $candidate) {
			$sameLine = ((string)$candidate['poLineId'] === $run->chosenPoLineId);
			if ($sameLine === true && $this->isChosenGrnLine(run: $run, candidate: $candidate) === true) {
				$run->chosen = $candidate;
				continue;
			}

			if ($sameLine === false) {
				$run->rejectedPoLineIds[(string)$candidate['poLineId']] = true;
			}
		}

		if ($run->chosen === null) {
			throw new RuntimeException('Chosen tuple is not in the candidate set');
		}
	}//end pickChosenCandidate()

	/**
	 * True when a candidate carries the chosen GRN line — or none, when no
	 * GRN line was chosen.
	 *
	 * @param DisambiguationRun   $run       The disambiguation run.
	 * @param array<string,mixed> $candidate The candidate tuple.
	 *
	 * @return bool Whether the GRN side matches the choice.
	 */
	private function isChosenGrnLine(DisambiguationRun $run, array $candidate): bool {
		if ($run->chosenGrnLineId === '') {
			return ($candidate['grnLineId'] === null || $candidate['grnLineId'] === '');
		}

		return ((string)($candidate['grnLineId'] ?? '') === $run->chosenGrnLineId);
	}//end isChosenGrnLine()

	/**
	 * The disambiguationChoice snapshot of a recorded choice.
	 *
	 * @param DisambiguationRun $run The disambiguation run.
	 *
	 * @return array<string,mixed> candidateCount, chosen ids, rejected PO lines, chosenBy, chosenAt.
	 */
	private function choiceSnapshot(DisambiguationRun $run): array {
		$persistedGrnLineId = null;
		if ($run->chosenGrnLineId !== '') {
			$persistedGrnLineId = $run->chosenGrnLineId;
		}

		return [
			'candidateCount' => count($run->candidates),
			'chosenPoLineId' => $run->chosenPoLineId,
			'chosenGrnLineId' => $persistedGrnLineId,
			// `array_keys()` already returns a list.
			'rejectedPoLineIds' => array_keys($run->rejectedPoLineIds),
			'chosenBy' => $this->currentUserId(),
			'chosenAt' => $this->nowIso(),
		];
	}//end choiceSnapshot()

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
	public function consolidateInvoice(string $administrationId, string $invoiceId): array {
		$this->assertAccess(administrationId: $administrationId);

		$run = new InvoiceConsolidationRun(administrationId: $administrationId, invoiceId: $invoiceId);
		$run->invoice = $this->requireInvoice(administrationId: $administrationId, invoiceId: $invoiceId);

		$results = [];
		foreach ($this->invoiceLines(invoice: $run->invoice) as $lineIndex => $line) {
			$run->lineNumber = (int)($line['lineNumber'] ?? ($lineIndex + 1));
			$results[] = $this->consolidateLine(run: $run, line: $line);
		}

		return $results;
	}//end consolidateInvoice()

	/**
	 * The invoice with the given id in the administration.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $invoiceId        SupplierInvoice id.
	 *
	 * @return array<string,mixed> The invoice.
	 *
	 * @throws \RuntimeException When the id is empty or the invoice is missing.
	 */
	private function requireInvoice(string $administrationId, string $invoiceId): array {
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

		return $invoice;
	}//end requireInvoice()

	/**
	 * Consolidate one invoice line: no candidate is a missing-PO exception,
	 * several are left pending for the operator, one is written as a trio.
	 *
	 * @param InvoiceConsolidationRun $run  The consolidation run.
	 * @param array<string,mixed>     $line The invoice line.
	 *
	 * @return array<string,mixed> The line result.
	 */
	private function consolidateLine(InvoiceConsolidationRun $run, array $line): array {
		$candidates = $this->enumerateCandidateTuples(
			administrationId: $run->administrationId,
			invoice: $run->invoice,
			invoiceLine: $line
		);
		if ($candidates === []) {
			return $this->recordMissingPo(run: $run, line: $line);
		}

		if (count($candidates) > 1) {
			// Ambiguous — surface to the operator; no ThreeWayMatch yet.
			return $run->result(outcome: ['status' => 'pending', 'matchId' => null, 'candidateCount' => count($candidates)]);
		}

		return $this->recordSingleCandidate(run: $run, candidate: $candidates[0]);
	}//end consolidateLine()

	/**
	 * Write the exception_missing_po ThreeWayMatch for a line without
	 * candidates.
	 *
	 * @param InvoiceConsolidationRun $run  The consolidation run.
	 * @param array<string,mixed>     $line The invoice line.
	 *
	 * @return array<string,mixed> The line result.
	 */
	private function recordMissingPo(InvoiceConsolidationRun $run, array $line): array {
		$lineMatch = $this->consolidationMatch(run: $run, matchStatus: self::MATCH_STATUS_MISSING_PO);
		$lineMatch->candidate = [
			'poLineId' => null,
			'poId' => null,
			'grnLineId' => null,
			'grnId' => null,
			'productCode' => trim((string)($line['productCode'] ?? '')),
		];
		$missing = $this->writeMatchRecord(lineMatch: $lineMatch);

		return $run->result(outcome: ['status' => 'exception', 'matchId' => $this->idOf(record: $missing), 'candidateCount' => 0]);
	}//end recordMissingPo()

	/**
	 * Write the trio ThreeWayMatch of a line with exactly one candidate: auto
	 * approved with a GRN line, a missing-GRN exception without one (the
	 * matching engine reclassifies on divergence).
	 *
	 * @param InvoiceConsolidationRun $run       The consolidation run.
	 * @param array<string,mixed>     $candidate The single candidate.
	 *
	 * @return array<string,mixed> The line result.
	 */
	private function recordSingleCandidate(InvoiceConsolidationRun $run, array $candidate): array {
		$matchStatus = self::MATCH_STATUS_AUTO_APPROVED;
		$lineStatus = 'auto';
		if ($candidate['grnLineId'] === null) {
			$matchStatus = self::MATCH_STATUS_MISSING_GRN;
			$lineStatus = 'exception';
		}

		$lineMatch = $this->consolidationMatch(run: $run, matchStatus: $matchStatus);
		$lineMatch->candidate = $candidate;
		$persisted = $this->writeMatchRecord(lineMatch: $lineMatch);

		return $run->result(outcome: ['status' => $lineStatus, 'matchId' => $this->idOf(record: $persisted), 'candidateCount' => 1]);
	}//end recordSingleCandidate()

	/**
	 * A ThreeWayMatch to write for the run's line, without a choice.
	 *
	 * @param InvoiceConsolidationRun $run         The consolidation run.
	 * @param string                  $matchStatus The initial match status.
	 *
	 * @return InvoiceLineMatch The match to write.
	 */
	private function consolidationMatch(InvoiceConsolidationRun $run, string $matchStatus): InvoiceLineMatch {
		$lineMatch = new InvoiceLineMatch(administrationId: $run->administrationId, invoiceId: $run->invoiceId);
		$lineMatch->invoiceLineNumber = $run->lineNumber;
		$lineMatch->matchStatus = $matchStatus;
		return $lineMatch;
	}//end consolidationMatch()

	/**
	 * Persist one ThreeWayMatch record for a (PO line, GRN line, invoice
	 * line) trio.
	 *
	 * @param InvoiceLineMatch $lineMatch The match to write.
	 *
	 * @return array<string,mixed> The persisted match record.
	 */
	private function writeMatchRecord(InvoiceLineMatch $lineMatch): array {
		$persisted = $this->saveObject(schema: self::SCHEMA_THREE_WAY_MATCH, object: $this->matchPayload(lineMatch: $lineMatch));

		// Slice 07 — write the PO link back to the SupplierInvoice document so
		// a consumer reading the invoice sees the per-line PO links inline
		// (REQ-PO3W-007). On the missing-PO exception path the link fields
		// are NULL but the matchId is still recorded so the UI can show the
		// exception status without traversing ThreeWayMatch records.
		$this->linkInvoiceLine(lineMatch: $lineMatch, matchId: $this->idOf(record: $persisted));

		return $persisted;
	}//end writeMatchRecord()

	/**
	 * The ThreeWayMatch payload of a line match.
	 *
	 * @param InvoiceLineMatch $lineMatch The match to write.
	 *
	 * @return array<string,mixed> The record.
	 */
	private function matchPayload(InvoiceLineMatch $lineMatch): array {
		$candidate = $lineMatch->candidate;
		return [
			'invoiceId' => $lineMatch->invoiceId,
			'invoiceLineNumber' => $lineMatch->invoiceLineNumber,
			'productCode' => ($candidate['productCode'] ?? null),
			'poLineId' => ($candidate['poLineId'] ?? null),
			'grnLineId' => ($candidate['grnLineId'] ?? null),
			'matchedPoIds' => $lineMatch->presentIds(key: 'poId'),
			'matchedGrnIds' => $lineMatch->presentIds(key: 'grnId'),
			'matchStatus' => $lineMatch->matchStatus,
			'divergenceDetails' => [],
			'disambiguationChoice' => $lineMatch->disambiguationChoice,
			'createdAt' => $this->nowIso(),
			'administrationId' => $lineMatch->administrationId,
		];
	}//end matchPayload()

	/**
	 * Project the line's PO links onto the SupplierInvoice. The inline link
	 * is a best-effort projection — the canonical record is the ThreeWayMatch
	 * just persisted, so a failure to update the invoice document MUST NOT
	 * roll back the engine: it is logged and the run continues.
	 *
	 * @param InvoiceLineMatch $lineMatch The written match.
	 * @param string           $matchId   The persisted match id ('' when none).
	 *
	 * @return void
	 */
	private function linkInvoiceLine(InvoiceLineMatch $lineMatch, string $matchId): void {
		$candidate = $lineMatch->candidate;
		try {
			$this->supplierInvoiceService->linkInvoiceLineToPo(
				administrationId: $lineMatch->administrationId,
				invoiceId: $lineMatch->invoiceId,
				invoiceLineNumber: $lineMatch->invoiceLineNumber,
				linkedPoLineId: $this->stringOrNull(value: ($candidate['poLineId'] ?? null)),
				linkedPoId: $this->stringOrNull(value: ($candidate['poId'] ?? null)),
				linkedGrnLineId: $this->stringOrNull(value: ($candidate['grnLineId'] ?? null)),
				linkedMatchId: $this->idOrNull(id: $matchId)
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'MultiPoConsolidationService: failed to link invoice line to PO',
				[
					'invoiceId' => $lineMatch->invoiceId,
					'invoiceLineNumber' => $lineMatch->invoiceLineNumber,
					'exception' => $e->getMessage(),
				]
			);
		}//end try
	}//end linkInvoiceLine()

	/**
	 * A value as a string, or null when it is null.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string|null The string, or null.
	 */
	private function stringOrNull(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end stringOrNull()

	/**
	 * An id, or null when it is empty.
	 *
	 * @param string $id The id.
	 *
	 * @return string|null The id, or null.
	 */
	private function idOrNull(string $id): ?string {
		if ($id === '') {
			return null;
		}

		return $id;
	}//end idOrNull()

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
		$register = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
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
