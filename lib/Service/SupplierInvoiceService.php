<?php

/**
 * Supplier Invoice Service
 *
 * Slice 05 of the bookkeeping-purchase-order-3way chain: server-authoritative
 * ingestion of supplier invoices from a Peppol-received UBL Invoice XML or a
 * PDF invoice processed through OCR (REQ-PO3W-004 + REQ-PO3W-007). Persists
 * SupplierInvoice records into the OpenRegister `SupplierInvoice` schema
 * declared by slice 01, populating `ubl_source_uri`, `peppol_received_at`
 * (UBL path) or `ocr_confidence_score` (PDF/OCR path), and lifecycle
 * `statusCode = received` — ready for the matching engine in slice 06.
 *
 * All reads/writes go through OpenRegister's real ObjectService API
 * (find / findAll / saveObject) — the methods `findObject` /
 * `createFromArray` / `deleteFromId` do NOT exist and are never used
 * ([[or-objectservice-api]]). Every monetary field is stored as integer
 * euro cents per the slice-01 schema (ADR-022); the UBL/OCR parsers
 * convert decimal amounts via toCents() with half-up rounding to avoid
 * float drift across line items.
 *
 * The administration scope is validated by AdministrationContextService
 * (ADR-005 IDOR-safe). When the openconnector hook dispatches an event
 * with no resolvable administration (e.g. tenant identity not yet wired),
 * the call is rejected — no fallback to "any administration" — so
 * cross-tenant pollution is impossible.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use SimpleXMLElement;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Slice 05 — Supplier invoice ingestion from UBL (Peppol) and PDF (OCR).
 *
 * Public methods:
 * - ingestUBLInvoice(): parse UBL Invoice XML into a SupplierInvoice record
 *   plus line items; record ubl_source_uri + peppol_received_at; statusCode
 *   = received (REQ-PO3W-004).
 * - ingestPDFInvoice(): consume the OCR extraction payload of a PDF invoice;
 *   create a SupplierInvoice with ocr_confidence_score for downstream
 *   low-confidence gating (REQ-PO3W-007).
 * - setStatus(): transition a persisted SupplierInvoice through the lifecycle
 *   declared in slice 01 (received -> matching -> matched / exception ->
 *   approved -> paid; received|exception -> rejected). Slice 05 itself only
 *   needs `received` (the initial state) but exposes the guarded transition
 *   so the openconnector hook (and downstream slice 06) call a single
 *   server-authoritative method instead of writing statusCode directly.
 * - linkInvoiceLineToPo() (slice 07): stamp the embedded invoice line with
 *   linkedPoLineId / linkedPoId / linkedGrnLineId / linkedMatchId so the
 *   SupplierInvoice document records its per-line PO links inline, and
 *   append linkedPoId to the header's matchedPoIds (de-duplicated +
 *   insertion-order preserved) + stamp consolidatedAt. Called by the
 *   multi-PO consolidation engine once per matched invoice line so a
 *   consumer reading the SupplierInvoice does not have to traverse
 *   ThreeWayMatch records to see which POs a maand-factuur consolidates
 *   (REQ-PO3W-007).
 *
 * The service uses the same in-app helpers as the PO service in slice 02
 * (register slug from IAppConfig, ObjectService resolved lazily via the
 * container) so the test stub in slice 02 is straightforward to port here.
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; deferred pending a dedicated refactor.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion/tasks.md
 */
class SupplierInvoiceService {
	/**
	 * Initial lifecycle state of a newly ingested invoice (REQ-SI-002).
	 *
	 * @var string
	 */
	public const STATUS_RECEIVED = 'received';

	/**
	 * Source-format tag stored on the SupplierInvoice record indicating the
	 * ingestion path (`ubl`, `pdf`).
	 *
	 * @var string
	 */
	private const SOURCE_FORMAT_UBL = 'ubl';

	/**
	 * Source-format tag for the OCR/PDF ingestion path.
	 *
	 * @var string
	 */
	private const SOURCE_FORMAT_PDF = 'pdf';

	/**
	 * Allowed lifecycle transitions (mirror of the slice-01 schema
	 * `x-openregister-lifecycle.transitions`). Each entry is a
	 * `from => list<to>` adjacency list; setStatus() rejects any transition
	 * not in the table so the server, not the caller, enforces the
	 * lifecycle.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const ALLOWED_TRANSITIONS = [
		'received' => ['matching', 'rejected'],
		'matching' => ['matched', 'exception'],
		'matched' => ['approved'],
		'exception' => ['approved', 'rejected'],
		'approved' => ['paid'],
		'paid' => [],
		'rejected' => [],
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for the OR register slug.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope (ADR-005).
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly AdministrationContextService $administrationContext,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Ingest a Peppol-received UBL Invoice XML (REQ-PO3W-004 ingestion path).
	 *
	 * The caller supplies the UBL Invoice XML (as a string), the resolved
	 * tenant administration id, the originating Peppol message id (used as
	 * the `ublSourceUri` when no explicit URI is supplied) and an optional
	 * `ublSourceUri` + `peppolReceivedAt`. The method:
	 *
	 *  1. validates the administration scope (ADR-005);
	 *  2. parses the UBL via {@see parseUblInvoice()};
	 *  3. de-duplicates against an existing (invoiceNumber, supplierId,
	 *     administrationId) tuple — repeated deliveries on the same Peppol
	 *     message id MUST NOT create duplicate records;
	 *  4. persists a SupplierInvoice record with statusCode = received,
	 *     sourceFormat = ubl and the parsed line items as an embedded
	 *     `lines` array (the lines schema lives downstream in slice 06; at
	 *     this slice the lines travel embedded on the invoice document).
	 *
	 * @param string $administrationId Tenant scope (must be
	 *                                 accessible to the caller).
	 * @param string $ublXml UBL Invoice XML document.
	 * @param array<string,mixed> $context Optional context: ublSourceUri,
	 *                                     peppolReceivedAt (ISO-8601),
	 *                                     peppolMessageId.
	 *
	 * @return array<string,mixed> The persisted SupplierInvoice (id stamped by OR).
	 *
	 * @throws \RuntimeException When the administration is inaccessible, the
	 *                           UBL is malformed, or the document does not
	 *                           carry an InvoiceNumber.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion/tasks.md
	 */
	public function ingestUBLInvoice(string $administrationId, string $ublXml, array $context = []): array {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Administration not found');
		}

		$parsed = $this->parseUblInvoice(ublXml: $ublXml);

		$existing = $this->findOne(
			schema: 'SupplierInvoice',
			filters: [
				'administrationId' => $administrationId,
				'invoiceNumber' => $parsed['invoiceNumber'],
				'supplierId' => $parsed['supplierId'],
			]
		);
		if ($existing !== null) {
			// Idempotent ingestion — repeated Peppol deliveries return the
			// existing record so the openconnector hook can retry safely.
			return $existing;
		}

		$peppolReceivedAt = trim((string)($context['peppolReceivedAt'] ?? ''));
		if ($peppolReceivedAt === '') {
			$peppolReceivedAt = $this->nowIso();
		}

		$ublSourceUri = trim((string)($context['ublSourceUri'] ?? ''));
		if ($ublSourceUri === '' && isset($context['peppolMessageId']) === true) {
			$ublSourceUri = 'peppol:' . trim((string)$context['peppolMessageId']);
		}

		$record = array_merge(
			$parsed,
			[
				'administrationId' => $administrationId,
				'sourceFormat' => self::SOURCE_FORMAT_UBL,
				'ublSourceUri' => $ublSourceUri,
				'peppolReceivedAt' => $peppolReceivedAt,
				'statusCode' => self::STATUS_RECEIVED,
				'createdAt' => $this->nowIso(),
			]
		);

		return $this->saveObject(schema: 'SupplierInvoice', object: $record);
	}//end ingestUBLInvoice()

	/**
	 * Ingest a PDF-attached invoice via openconnector's OCR extraction
	 * service (REQ-PO3W-007).
	 *
	 * The OCR caller supplies the extraction payload (a structured array
	 * with the same keys the UBL parser produces) plus an
	 * `ocrConfidenceScore` between 0.00 and 1.00. The method:
	 *
	 *  1. validates the administration scope (ADR-005);
	 *  2. validates the OCR payload (invoiceNumber + supplierId required —
	 *     OCR may legitimately drop dates or totals which downstream
	 *     low-confidence gating in slice 08 then asks an operator to fill);
	 *  3. clamps the ocrConfidenceScore to [0.00, 1.00];
	 *  4. de-duplicates against an existing record;
	 *  5. persists a SupplierInvoice with statusCode = received and the
	 *     OCR confidence + sourceFormat = pdf for downstream gating.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param array<string,mixed> $ocrPayload Structured OCR extraction
	 *                                        payload (see parsed shape
	 *                                        of UBL invoice parser).
	 * @param float $confidenceScore OCR confidence in [0, 1].
	 * @param array<string,mixed> $context Optional context:
	 *                                     pdfSourceUri,
	 *                                     ocrProcessedAt.
	 *
	 * @return array<string,mixed> The persisted SupplierInvoice.
	 *
	 * @throws \RuntimeException When the administration is inaccessible or
	 *                           the OCR payload lacks a usable invoice
	 *                           identifier.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion/tasks.md
	 */
	public function ingestPDFInvoice(
		string $administrationId,
		array $ocrPayload,
		float $confidenceScore,
		array $context = [],
	): array {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Administration not found');
		}

		$normalised = $this->normaliseOcrPayload(ocrPayload: $ocrPayload);

		$existing = $this->findOne(
			schema: 'SupplierInvoice',
			filters: [
				'administrationId' => $administrationId,
				'invoiceNumber' => $normalised['invoiceNumber'],
				'supplierId' => $normalised['supplierId'],
			]
		);
		if ($existing !== null) {
			return $existing;
		}

		// Clamp confidence to [0, 1]; OCR engines sometimes report a noisy
		// score outside the documented range.
		$clampedConfidence = max(0.0, min(1.0, $confidenceScore));
		// Round to multipleOf 0.01 to match the slice-01 schema constraint.
		$clampedConfidence = (float)(round($clampedConfidence * 100) / 100);

		$ocrProcessedAt = trim((string)($context['ocrProcessedAt'] ?? ''));
		if ($ocrProcessedAt === '') {
			$ocrProcessedAt = $this->nowIso();
		}

		$record = array_merge(
			$normalised,
			[
				'administrationId' => $administrationId,
				'sourceFormat' => self::SOURCE_FORMAT_PDF,
				'ocrConfidenceScore' => $clampedConfidence,
				'pdfSourceUri' => trim((string)($context['pdfSourceUri'] ?? '')),
				'ocrProcessedAt' => $ocrProcessedAt,
				'statusCode' => self::STATUS_RECEIVED,
				'createdAt' => $this->nowIso(),
			]
		);

		return $this->saveObject(schema: 'SupplierInvoice', object: $record);
	}//end ingestPDFInvoice()

	/**
	 * Slice 07 — record the (PO line, GRN line, ThreeWayMatch) link on a
	 * single embedded invoice line and update the SupplierInvoice header's
	 * `matchedPoIds` + `consolidatedAt` (REQ-PO3W-007).
	 *
	 * The multi-PO consolidation engine calls this method once per matched
	 * invoice line so the SupplierInvoice document itself records which POs
	 * the maand-factuur consolidates — consumers reading the invoice see the
	 * PO links inline without traversing ThreeWayMatch records.
	 *
	 * The method is server-authoritative:
	 *
	 *  - validates the administration scope (ADR-005); cross-tenant calls
	 *    are masked as "Supplier invoice not found";
	 *  - loads the persisted invoice; missing → same masked error;
	 *  - locates the embedded invoice line by 1-based `invoiceLineNumber`;
	 *    unknown line → RuntimeException so the controller layer maps to a
	 *    deterministic 404;
	 *  - stamps `linkedPoLineId`, `linkedPoId`, `linkedGrnLineId`,
	 *    `linkedMatchId` on the line; NULLable values are stored as NULL so
	 *    the JSON document matches the slice-07 schema fragment exactly;
	 *  - appends `linkedPoId` to the header's `matchedPoIds` (de-duplicated
	 *    + insertion-order preserved) and stamps `consolidatedAt`.
	 *
	 * The method is idempotent — re-linking the same line with the same
	 * trio yields the same persisted record; re-linking with a different
	 * trio overwrites the per-line link fields (the operator changing a
	 * disambiguation choice).
	 *
	 * @param string $administrationId Tenant scope (server-resolved).
	 * @param string $invoiceId SupplierInvoice id.
	 * @param int $invoiceLineNumber 1-based line position.
	 * @param string|null $linkedPoLineId PurchaseOrderLine id (NULL on exception path).
	 * @param string|null $linkedPoId PurchaseOrder id (NULL on exception path).
	 * @param string|null $linkedGrnLineId GoodsReceiptLine id (NULL on PO-only / exception).
	 * @param string|null $linkedMatchId ThreeWayMatch id (NULL when no trio yet).
	 *
	 * @return array<string,mixed> The updated SupplierInvoice.
	 *
	 * @throws \RuntimeException On unknown invoice, unknown invoice line, or
	 *                           cross-tenant access.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-07-multi-po-consolidation/tasks.md
	 */
	public function linkInvoiceLineToPo(
		string $administrationId,
		string $invoiceId,
		int $invoiceLineNumber,
		?string $linkedPoLineId,
		?string $linkedPoId,
		?string $linkedGrnLineId,
		?string $linkedMatchId,
	): array {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Supplier invoice not found');
		}

		if ($invoiceId === '') {
			throw new RuntimeException('Supplier invoice not found');
		}

		if ($invoiceLineNumber < 1) {
			throw new RuntimeException('Invalid invoiceLineNumber');
		}

		$invoice = $this->findOne(
			schema: 'SupplierInvoice',
			filters: [
				'id' => $invoiceId,
				'administrationId' => $administrationId,
			]
		);
		if ($invoice === null) {
			throw new RuntimeException('Supplier invoice not found');
		}

		$lines = ($invoice['lines'] ?? []);
		if (is_array($lines) === false) {
			$lines = [];
		}

		$located = false;
		foreach ($lines as $index => $line) {
			if (is_array($line) === false) {
				continue;
			}

			$candidateNumber = (int)($line['lineNumber'] ?? ((int)$index + 1));
			if ($candidateNumber !== $invoiceLineNumber) {
				continue;
			}

			$normalisedPoLineId = $linkedPoLineId;
			$normalisedPoId = $linkedPoId;
			$normalisedGrnLineId = $linkedGrnLineId;
			$normalisedMatchId = $linkedMatchId;
			if ($normalisedPoLineId === '') {
				$normalisedPoLineId = null;
			}

			if ($normalisedPoId === '') {
				$normalisedPoId = null;
			}

			if ($normalisedGrnLineId === '') {
				$normalisedGrnLineId = null;
			}

			if ($normalisedMatchId === '') {
				$normalisedMatchId = null;
			}

			$line['linkedPoLineId'] = $normalisedPoLineId;
			$line['linkedPoId'] = $normalisedPoId;
			$line['linkedGrnLineId'] = $normalisedGrnLineId;
			$line['linkedMatchId'] = $normalisedMatchId;
			$lines[$index] = $line;
			$located = true;
			break;
		}//end foreach

		if ($located === false) {
			throw new RuntimeException('Invoice line not found');
		}

		$invoice['lines'] = $lines;

		// De-duplicated, insertion-order-preserving matchedPoIds.
		$existingMatchedPoIds = ($invoice['matchedPoIds'] ?? []);
		if (is_array($existingMatchedPoIds) === false) {
			$existingMatchedPoIds = [];
		}

		$matchedPoIds = [];
		$seen = [];
		foreach ($existingMatchedPoIds as $existing) {
			$existingPoId = trim((string)$existing);
			if ($existingPoId === '' || isset($seen[$existingPoId]) === true) {
				continue;
			}

			$matchedPoIds[] = $existingPoId;
			$seen[$existingPoId] = true;
		}

		if ($linkedPoId !== null && $linkedPoId !== '' && isset($seen[$linkedPoId]) === false) {
			$matchedPoIds[] = $linkedPoId;
			$seen[$linkedPoId] = true;
		}

		$invoice['matchedPoIds'] = $matchedPoIds;
		$invoice['consolidatedAt'] = $this->nowIso();

		return $this->saveObject(schema: 'SupplierInvoice', object: $invoice);
	}//end linkInvoiceLineToPo()

	/**
	 * Server-authoritative lifecycle transition for a persisted
	 * SupplierInvoice (REQ-SI-002).
	 *
	 * The Vue layer never writes `statusCode` directly. This method:
	 *
	 *  - validates the administration scope (ADR-005);
	 *  - loads the persisted record (cross-tenant masked as not found);
	 *  - asserts the requested transition is in {@see ALLOWED_TRANSITIONS}
	 *    — anything else is rejected with a 409-equivalent
	 *    RuntimeException so the controller can map it to a clear error;
	 *  - persists the new statusCode plus a per-state timestamp on the
	 *    record so the audit trail in slice 11 can reconstruct the
	 *    history without an additional audit-log table.
	 *
	 * @param string $administrationId Tenant scope (server-resolved).
	 * @param string $invoiceId SupplierInvoice id.
	 * @param string $toStatus Target lifecycle state.
	 *
	 * @return array<string,mixed> The updated SupplierInvoice.
	 *
	 * @throws \RuntimeException On unknown invoice, illegal transition, or
	 *                           cross-tenant access.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-05-supplier-invoice-ingestion/tasks.md
	 */
	public function setStatus(string $administrationId, string $invoiceId, string $toStatus): array {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		if ($this->administrationContext->canAccess(administrationId: $administrationId) === false) {
			throw new RuntimeException('Supplier invoice not found');
		}

		if ($invoiceId === '') {
			throw new RuntimeException('Supplier invoice not found');
		}

		$invoice = $this->findOne(
			schema: 'SupplierInvoice',
			filters: [
				'id' => $invoiceId,
				'administrationId' => $administrationId,
			]
		);
		if ($invoice === null) {
			throw new RuntimeException('Supplier invoice not found');
		}

		$fromStatus = (string)($invoice['statusCode'] ?? '');
		if (isset(self::ALLOWED_TRANSITIONS[$fromStatus]) === false) {
			throw new RuntimeException('Invalid current statusCode: ' . $fromStatus);
		}

		if (in_array($toStatus, self::ALLOWED_TRANSITIONS[$fromStatus], true) === false) {
			throw new RuntimeException('Illegal transition from ' . $fromStatus . ' to ' . $toStatus);
		}

		$invoice['statusCode'] = $toStatus;
		$invoice[$toStatus . 'At'] = $this->nowIso();

		return $this->saveObject(schema: 'SupplierInvoice', object: $invoice);
	}//end setStatus()

	/**
	 * Parse a UBL Invoice XML document into the structured field shape used
	 * by both ingestion paths.
	 *
	 * The parser is deliberately minimal — Peppol BIS Invoice / UBL 2.1 is
	 * a large standard but our matching engine only needs the header
	 * identifiers, dates, monetary totals and the line items. Anything
	 * else (extensions, allowances, delivery party detail) is ignored at
	 * this slice; later slices may project additional fields into
	 * dedicated registers.
	 *
	 * Monetary fields in UBL are decimal amounts in the invoice currency;
	 * we convert each via {@see toCents()} (half-up rounding) so the
	 * SupplierInvoice schema's integer-cent invariant holds.
	 *
	 * @param string $ublXml UBL Invoice XML document.
	 *
	 * @return array<string,mixed> Parsed header + lines.
	 *
	 * @throws \RuntimeException When the document is not valid XML or the
	 *                           mandatory InvoiceNumber field is absent.
	 */
	public function parseUblInvoice(string $ublXml): array {
		if (trim($ublXml) === '') {
			throw new RuntimeException('UBL Invoice XML is empty');
		}

		// Libxml ≥ 2.9 disables external-entity loading by default (PHP 8);
		// simplexml_load_string is XXE-safe in our supported runtime
		// ([[nc-security-defaults]]).
		$xml = simplexml_load_string($ublXml);
		if ($xml === false) {
			throw new RuntimeException('UBL Invoice XML is malformed');
		}

		// Register the canonical UBL Invoice + CBC + CAC namespaces so
		// XPath lookups work regardless of the source document's prefix
		// conventions.
		$namespaces = $xml->getNamespaces(true);
		foreach ([
			'inv' => 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2',
			'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
			'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
		] as $prefix => $uri
		) {
			if (in_array($uri, $namespaces, true) === true) {
				$xml->registerXPathNamespace($prefix, $uri);
			}
		}

		$invoiceNumber = $this->xpathFirst(xml: $xml, paths: ['//cbc:ID', '/inv:Invoice/cbc:ID']);
		$invoiceNumber = trim($invoiceNumber);
		if ($invoiceNumber === '') {
			throw new RuntimeException('UBL Invoice is missing InvoiceNumber (cbc:ID)');
		}

		$supplierId = trim(
			$this->xpathFirst(
				xml: $xml,
				paths: [
					'//cac:AccountingSupplierParty/cac:Party/cac:PartyIdentification/cbc:ID',
					'//cac:AccountingSupplierParty/cac:Party/cbc:EndpointID',
				]
			)
		);

		$currency = trim(
			$this->xpathFirst(
				xml: $xml,
				paths: ['//cbc:DocumentCurrencyCode']
			)
		);
		if ($currency === '') {
			$currency = 'EUR';
		}

		$totalExclVat = $this->xpathFirstFloat(xml: $xml, paths: ['//cac:LegalMonetaryTotal/cbc:LineExtensionAmount']);
		$totalInclVat = $this->xpathFirstFloat(xml: $xml, paths: ['//cac:LegalMonetaryTotal/cbc:PayableAmount']);
		$totalVat = $this->xpathFirstFloat(xml: $xml, paths: ['//cac:TaxTotal/cbc:TaxAmount']);

		$lines = [];
		$nodes = $xml->xpath('//cac:InvoiceLine');
		if (is_array($nodes) === true) {
			$lineNumber = 0;
			foreach ($nodes as $node) {
				$lineNumber++;
				$lines[] = $this->parseUblInvoiceLine(node: $node, lineNumber: $lineNumber);
			}
		}

		return [
			'invoiceNumber' => $invoiceNumber,
			'supplierId' => $supplierId,
			'invoiceDate' => trim($this->xpathFirst(xml: $xml, paths: ['//cbc:IssueDate'])),
			'dueDate' => trim($this->xpathFirst(xml: $xml, paths: ['//cbc:DueDate'])),
			'currency' => $currency,
			'totalExclVat' => $this->toCents(amount: $totalExclVat),
			'totalVat' => $this->toCents(amount: $totalVat),
			'totalInclVat' => $this->toCents(amount: $totalInclVat),
			'paymentReference' => trim($this->xpathFirst(xml: $xml, paths: ['//cac:PaymentMeans/cbc:PaymentID'])),
			'lines' => $lines,
		];

	}//end parseUblInvoice()

	/**
	 * Parse a single cac:InvoiceLine element into the embedded line shape.
	 *
	 * @param SimpleXMLElement $node The InvoiceLine node.
	 * @param int $lineNumber Position in the document (1-based).
	 *
	 * @return array<string,mixed>
	 */
	private function parseUblInvoiceLine(SimpleXMLElement $node, int $lineNumber): array {
		// Re-register namespaces on the line node so XPath works against the
		// element scope (some SimpleXML implementations require this).
		$namespaces = $node->getNamespaces(true);
		foreach ([
			'cbc' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2',
			'cac' => 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2',
		] as $prefix => $uri
		) {
			if (in_array($uri, $namespaces, true) === true) {
				$node->registerXPathNamespace($prefix, $uri);
			}
		}

		$idNode = $node->xpath('cbc:ID');
		$rawLineId = '';
		if (is_array($idNode) === true && isset($idNode[0]) === true) {
			$rawLineId = trim((string)$idNode[0]);
		}

		$description = $this->xpathFirst(xml: $node, paths: ['cac:Item/cbc:Description', 'cac:Item/cbc:Name']);
		$productCode = $this->xpathFirst(xml: $node, paths: ['cac:Item/cac:SellersItemIdentification/cbc:ID']);
		$quantity = $this->xpathFirstFloat(xml: $node, paths: ['cbc:InvoicedQuantity']);
		$unitPrice = $this->xpathFirstFloat(xml: $node, paths: ['cac:Price/cbc:PriceAmount']);
		$lineExtension = $this->xpathFirstFloat(xml: $node, paths: ['cbc:LineExtensionAmount']);
		$vatRate = $this->xpathFirstFloat(
			xml: $node,
			paths: ['cac:Item/cac:ClassifiedTaxCategory/cbc:Percent', 'cac:TaxTotal/cac:TaxSubtotal/cac:TaxCategory/cbc:Percent']
		);
		// UBL Percent is expressed in whole-percent (21.0); store as the
		// fraction the rest of the app already uses (0.21).
		$vatRateFraction = 0.0;
		if ($vatRate > 0.0) {
			$vatRateFraction = ($vatRate / 100.0);
		}

		$resolvedLineNumber = $lineNumber;
		if ($rawLineId !== '' && ctype_digit($rawLineId) === true) {
			$resolvedLineNumber = (int)$rawLineId;
		}

		return [
			'lineNumber' => $resolvedLineNumber,
			'productCode' => trim($productCode),
			'description' => trim($description),
			'quantity' => $quantity,
			'unitPrice' => $this->toCents(amount: $unitPrice),
			'lineExtension' => $this->toCents(amount: $lineExtension),
			'vatRate' => $vatRateFraction,
		];

	}//end parseUblInvoiceLine()

	/**
	 * Run a sequence of xpath candidates and return the first non-empty
	 * string match (or '' when nothing matches).
	 *
	 * @param SimpleXMLElement $xml Root element to search.
	 * @param array<int,string> $paths Candidate xpath expressions, in priority.
	 *
	 * @return string
	 */
	private function xpathFirst(SimpleXMLElement $xml, array $paths): string {
		foreach ($paths as $path) {
			$nodes = $xml->xpath($path);
			if (is_array($nodes) === true && isset($nodes[0]) === true) {
				$value = trim((string)$nodes[0]);
				if ($value !== '') {
					return $value;
				}
			}
		}

		return '';
	}//end xpathFirst()

	/**
	 * Convenience wrapper around xpathFirst() that converts the matched
	 * text to a float (or 0.0 when no match).
	 *
	 * @param SimpleXMLElement $xml Root element.
	 * @param array<int,string> $paths Candidate xpath expressions.
	 *
	 * @return float
	 */
	private function xpathFirstFloat(SimpleXMLElement $xml, array $paths): float {
		$value = $this->xpathFirst(xml: $xml, paths: $paths);
		if ($value === '') {
			return 0.0;
		}

		return (float)$value;
	}//end xpathFirstFloat()

	/**
	 * Normalise + validate the OCR payload supplied by the openconnector
	 * OCR service.
	 *
	 * The OCR caller is expected to produce the same field shape the UBL
	 * parser does; this method enforces the mandatory identifiers and
	 * coerces monetary amounts to integer cents so downstream consumers
	 * see a single canonical type.
	 *
	 * @param array<string,mixed> $ocrPayload OCR payload.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When mandatory identifiers are missing.
	 */
	private function normaliseOcrPayload(array $ocrPayload): array {
		$invoiceNumber = trim((string)($ocrPayload['invoiceNumber'] ?? ''));
		$supplierId = trim((string)($ocrPayload['supplierId'] ?? ''));

		if ($invoiceNumber === '') {
			throw new RuntimeException('OCR payload is missing invoiceNumber');
		}

		if ($supplierId === '') {
			throw new RuntimeException('OCR payload is missing supplierId');
		}

		$currency = trim((string)($ocrPayload['currency'] ?? 'EUR'));
		if ($currency === '') {
			$currency = 'EUR';
		}

		$totalExclVat = $this->toCentsLoose(value: ($ocrPayload['totalExclVat'] ?? null));
		$totalVat = $this->toCentsLoose(value: ($ocrPayload['totalVat'] ?? null));
		$totalInclVat = $this->toCentsLoose(value: ($ocrPayload['totalInclVat'] ?? null));

		$lines = [];
		if (isset($ocrPayload['lines']) === true && is_array($ocrPayload['lines']) === true) {
			$lineNumber = 0;
			foreach ($ocrPayload['lines'] as $rawLine) {
				if (is_array($rawLine) === false) {
					continue;
				}

				$lineNumber++;
				$lines[] = [
					'lineNumber' => ((int)($rawLine['lineNumber'] ?? $lineNumber)),
					'productCode' => trim((string)($rawLine['productCode'] ?? '')),
					'description' => trim((string)($rawLine['description'] ?? '')),
					'quantity' => (float)($rawLine['quantity'] ?? 0),
					'unitPrice' => $this->toCentsLoose(value: ($rawLine['unitPrice'] ?? null)),
					'lineExtension' => $this->toCentsLoose(value: ($rawLine['lineExtension'] ?? null)),
					'vatRate' => (float)($rawLine['vatRate'] ?? 0),
				];
			}
		}

		return [
			'invoiceNumber' => $invoiceNumber,
			'supplierId' => $supplierId,
			'invoiceDate' => trim((string)($ocrPayload['invoiceDate'] ?? '')),
			'dueDate' => trim((string)($ocrPayload['dueDate'] ?? '')),
			'currency' => $currency,
			'totalExclVat' => $totalExclVat,
			'totalVat' => $totalVat,
			'totalInclVat' => $totalInclVat,
			'paymentReference' => trim((string)($ocrPayload['paymentReference'] ?? '')),
			'lines' => $lines,
		];

	}//end normaliseOcrPayload()

	/**
	 * Loose float-or-null to integer cents.
	 *
	 * OCR payloads frequently emit `null` for missing fields; this helper
	 * preserves null so downstream consumers can distinguish "no value
	 * extracted" from "zero" (the slice-01 schema marks the total fields
	 * nullable).
	 *
	 * @param mixed $value Raw value (float, int, string, null).
	 *
	 * @return int|null
	 */
	private function toCentsLoose(mixed $value): ?int {
		if ($value === null || $value === '') {
			return null;
		}

		if (is_numeric($value) === false) {
			return null;
		}

		return $this->toCents(amount: (float)$value);
	}//end toCentsLoose()

	/**
	 * Persist an object via OR's real ObjectService API (saveObject).
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
				'SupplierInvoiceService: failed to persist object',
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
	 * Fetch all matching records via the real ObjectService API (findAll).
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
				'SupplierInvoiceService: failed to query OpenRegister',
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
	 * Convert a decimal amount to integer cents, rounding half-up.
	 *
	 * @param float $amount Amount in the document currency.
	 *
	 * @return int Cents.
	 */
	private function toCents(float $amount): int {
		return (int)round(($amount * 100), 0, PHP_ROUND_HALF_UP);
	}//end toCents()

	/**
	 * Current timestamp in ISO-8601 (Y-m-d\TH:i:sP) — server-authoritative.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return date('c');
	}//end nowIso()
}//end class
