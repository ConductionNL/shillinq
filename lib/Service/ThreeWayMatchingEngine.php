<?php

/**
 * Three-Way Matching Engine
 *
 * Slice 06 of the bookkeeping-purchase-order-3way chain (REQ-PO3W-004,
 * REQ-PO3W-006, REQ-PO3W-011): server-authoritative line-level matching of
 * a SupplierInvoice against the originating PurchaseOrder(s) and its third
 * leg — either GoodsReceiptNote(s) for goods lines, or SvcReceipt(s)
 * (prestatieverklaring / service-entry-sheet, member 12) for service
 * lines, which will never have a physical goods receipt. Both receipt
 * types are resolved and merged before line matching so
 * matchLineItems()/calculateDivergence() need zero receipt-type
 * branching — see ServiceReceiptService for why SvcReceiptLine reuses
 * GoodsReceiptLine's exact field names. Computes per-line price_delta,
 * quantity_delta, vat_delta, date_delta in integer cents / thousandths /
 * days, resolves the most-specific applicable ToleranceProfile (delegated
 * to {@see ToleranceProfileService::getApplicableProfile()}), evaluates
 * each divergence under the "more permissive" tolerance rule, and writes a
 * ThreeWayMatch record with the resulting match_status and a structured
 * divergenceDetails breakdown.
 *
 * Within-tolerance matches land with match_status auto_approved (no
 * divergences detected) or within_tolerance (divergences detected but all
 * within tolerance) and trigger an immediate SupplierInvoice transition
 * out of `received` → `matching` → `matched` so member 09's GR/IR
 * clearing posting fires declaratively. Out-of-tolerance matches set an
 * exception_* status (exception_price / exception_quantity /
 * exception_missing_grn / exception_missing_po) and transition the
 * invoice to `exception`; the exception resolution UI lives in slice 08.
 *
 * Scope boundary: this slice handles the single-PO matching path only.
 * Multi-PO consolidated invoices (one invoice → many POs with ambiguous
 * candidates) are member 07's responsibility — when more than one PO ID
 * is resolved this engine routes to exception_missing_po so the
 * downstream consolidator can pick up the candidate set.
 *
 * All reads/writes go through OpenRegister's real ObjectService API
 * (find / findAll / saveObject) — the methods `findObject` /
 * `createFromArray` / `deleteFromId` do NOT exist and are never used
 * ([[or-objectservice-api]]). Money fields are integer cents per ADR-022;
 * quantities are integer thousandths so the per-line subtractor stays
 * bit-exact across partial-receipt cases.
 *
 * Tenant isolation: every read and write is scoped to the invoice's
 * administrationId, asserted by AdministrationContextService at the
 * controller boundary (ADR-005). The matching engine itself does not
 * trust scope from the request body — the SupplierInvoice carries its
 * own administrationId which is the authoritative source.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\Shillinq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Slice 06 — single-PO 3-way matching engine.
 *
 * Public surface:
 * - evaluateMatch(invoiceId): main entry point. Loads the SupplierInvoice,
 *   resolves matched PO/GRN candidates, scores every invoice line and
 *   writes a ThreeWayMatch record.
 * - matchLineItems(): exposes the candidate-tuple resolver so unit tests
 *   can exercise the matcher in isolation.
 * - calculateDivergence(): per-line delta breakdown.
 * - evaluateTolerance(): "more permissive" + quantity/date evaluation.
 * - routeToException(): writes a ThreeWayMatch with exception_* status
 *   (resolution UI lives in member 08).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Engine touches five
 * registers (SupplierInvoice, PurchaseOrder, PurchaseOrderLine,
 * GoodsReceiptNote, GoodsReceiptLine, ThreeWayMatch, ToleranceProfile)
 * by design.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Matching is by nature
 * a multi-axis decision (price + qty + date + presence).
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic (multi-axis match decision); deferred pending a dedicated
 * refactor.
 */
class ThreeWayMatchingEngine {

	/**
	 * Match outcome — exact 3-way match, zero divergence.
	 *
	 * @var string
	 */
	public const STATUS_AUTO_APPROVED = 'auto_approved';

	/**
	 * Match outcome — divergence detected but every field within tolerance.
	 *
	 * @var string
	 */
	public const STATUS_WITHIN_TOLERANCE = 'within_tolerance';

	/**
	 * Match outcome — price diverged beyond tolerance (REQ-PO3W-004).
	 *
	 * @var string
	 */
	public const STATUS_EXCEPTION_PRICE = 'exception_price';

	/**
	 * Match outcome — quantity diverged beyond tolerance.
	 *
	 * @var string
	 */
	public const STATUS_EXCEPTION_QUANTITY = 'exception_quantity';

	/**
	 * Match outcome — invoice has no matching GRN (services or pre-receipt).
	 *
	 * @var string
	 */
	public const STATUS_EXCEPTION_MISSING_GRN = 'exception_missing_grn';

	/**
	 * Match outcome — invoice has no matching PO (off-contract spend) OR
	 * multi-PO consolidation candidate set (deferred to slice 07).
	 *
	 * @var string
	 */
	public const STATUS_EXCEPTION_MISSING_PO = 'exception_missing_po';

	/**
	 * Schema slug for the SupplierInvoice register (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_SUPPLIER_INVOICE = 'SupplierInvoice';

	/**
	 * Schema slug for the PurchaseOrder register (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_PO = 'PurchaseOrder';

	/**
	 * Schema slug for the PurchaseOrderLine register (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_PO_LINE = 'PurchaseOrderLine';

	/**
	 * Schema slug for the GoodsReceiptNote register (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_GRN = 'GoodsReceiptNote';

	/**
	 * Schema slug for the GoodsReceiptLine register (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_GRN_LINE = 'GoodsReceiptLine';

	/**
	 * Schema slug for the SvcReceipt register (member 12) — the
	 * service-PO alternative third leg to GoodsReceiptNote
	 * (REQ-PO3W-011).
	 *
	 * @var string
	 */
	private const SCHEMA_SVC_RECEIPT = 'SvcReceipt';

	/**
	 * Schema slug for the SvcReceiptLine register (member 12).
	 *
	 * @var string
	 */
	private const SCHEMA_SVC_RECEIPT_LINE = 'SvcReceiptLine';

	/**
	 * Schema slug for the ThreeWayMatch register (slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_THREE_WAY_MATCH = 'ThreeWayMatch';

	/**
	 * Constructor.
	 *
	 *                                      — OR's
	 *                                      ObjectService
	 *                                      is fetched
	 *                                      lazily so
	 *                                      unit tests
	 *                                      can swap an
	 *                                      in-memory
	 *                                      stub.
	 * @param IAppConfig $appConfig App config for the OR register slug.
	 * @param ToleranceProfileService $toleranceService Tolerance resolution + evaluation.
	 * @param SupplierInvoiceService $invoiceService For lifecycle transitions on the
	 *                                               matched invoice (received →
	 *                                               matching → matched/exception).
	 * @param LoggerInterface $logger Logger (no sensitive payloads).
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 * @param ExceptionResolutionService|null $exceptionResolution Raises the
	 *                                                             crediteuren-administrateur
	 *                                                             alert when a match is routed
	 *                                                             to an exception status
	 *                                                             (REQ-PO3W-004); nullable so
	 *                                                             unit tests need not wire the
	 *                                                             notification manager.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly ToleranceProfileService $toleranceService,
		private readonly SupplierInvoiceService $invoiceService,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
		private readonly ?ExceptionResolutionService $exceptionResolution = null,
	) {

	}//end __construct()

	/**
	 * Evaluate a 3-way match for a SupplierInvoice.
	 *
	 * Main entry point for the engine. Loads the invoice + resolves PO /
	 * GRN candidates, scores divergence line-by-line, applies the
	 * applicable ToleranceProfile, writes the ThreeWayMatch record and
	 * transitions the SupplierInvoice lifecycle (received → matching →
	 * matched / exception). Returns the persisted ThreeWayMatch.
	 *
	 * Server-authoritative: this method derives the administrationId
	 * from the SupplierInvoice record (NOT from the caller) and asserts
	 * the invoice's matchedPoIds resolve under the same administration —
	 * cross-tenant references are masked as exception_missing_po so the
	 * engine never leaks the existence of a foreign PO.
	 *
	 * @param string $administrationId Administration scope (server-resolved
	 *                                 at the controller).
	 * @param string $invoiceId SupplierInvoice id.
	 *
	 * @return array<string,mixed> The persisted ThreeWayMatch record.
	 *
	 * @throws \RuntimeException When the invoice is unknown or cross-tenant.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
	 */
	public function evaluateMatch(string $administrationId, string $invoiceId): array {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

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

		// Server-authoritative scope: trust the invoice record's
		// administrationId, not the caller's claim. The controller has
		// already asserted the caller can see the same scope.
		$invoiceAdmin = (string)($invoice['administrationId'] ?? $administrationId);

		// Move the invoice into "matching" so the audit trail captures the
		// intermediate state. Idempotent — re-evaluating an already-matched
		// invoice will hit an "illegal transition" guard from setStatus and
		// fall through to writing a new ThreeWayMatch anyway.
		$this->safeTransition(administrationId: $invoiceAdmin, invoiceId: $invoiceId, toStatus: 'matching');

		// Resolve PO / GRN candidates.
		$poIds = $this->resolvePoIds(administrationId: $invoiceAdmin, invoice: $invoice);

		// Multi-PO is deferred to slice 07. When more than one PO matches,
		// we route to exception_missing_po with a `multi_po` divergence
		// breadcrumb so the consolidator picks it up.
		if (count($poIds) > 1) {
			return $this->routeToException(
				administrationId: $invoiceAdmin,
				invoice: $invoice,
				matchStatus: self::STATUS_EXCEPTION_MISSING_PO,
				divergences: [
					[
						'field' => 'multi_po',
						'expected' => 1,
						'actual' => count($poIds),
					],
				],
				matchedPoIds: $poIds,
				matchedGrnIds: []
			);
		}

		if ($poIds === []) {
			return $this->routeToException(
				administrationId: $invoiceAdmin,
				invoice: $invoice,
				matchStatus: self::STATUS_EXCEPTION_MISSING_PO,
				divergences: [
					['field' => 'po', 'expected' => 'matched', 'actual' => 'missing'],
				],
				matchedPoIds: [],
				matchedGrnIds: []
			);
		}

		$poId = $poIds[0];
		$po = $this->findOne(
			schema: self::SCHEMA_PO,
			filters: ['id' => $poId, 'administrationId' => $invoiceAdmin]
		);
		$poLines = $this->findAll(
			schema: self::SCHEMA_PO_LINE,
			filters: ['poId' => $poId, 'administrationId' => $invoiceAdmin]
		);

		$grns = $this->findAll(
			schema: self::SCHEMA_GRN,
			filters: ['administrationId' => $invoiceAdmin]
		);
		$matchedGrns = [];
		foreach ($grns as $grn) {
			$grnPoIds = (array)($grn['poIds'] ?? []);
			if (in_array($poId, $grnPoIds, true) === true) {
				$matchedGrns[] = $grn;
			}
		}

		// REQ-PO3W-011: a service PurchaseOrderLine will never have a
		// GoodsReceiptNote — resolve an accepted SvcReceipt (prestatieverklaring)
		// as the alternative third leg so the matching engine is not
		// goods-only.
		$svcReceipts = $this->findAll(
			schema: self::SCHEMA_SVC_RECEIPT,
			filters: ['administrationId' => $invoiceAdmin]
		);
		$matchedSvcReceipts = [];
		foreach ($svcReceipts as $svcReceipt) {
			$svcPoIds = (array)($svcReceipt['poIds'] ?? []);
			if (in_array($poId, $svcPoIds, true) === true) {
				$matchedSvcReceipts[] = $svcReceipt;
			}
		}

		// No accepted GRN AND no accepted SvcReceipt = exception_missing_grn
		// (their resolution is operator-driven via the slice-08 exception
		// UI). Before REQ-PO3W-011 this was the ONLY outcome for a service
		// PO — every service invoice was permanently stuck here.
		$hasAcceptedGrn = false;
		foreach ($matchedGrns as $grn) {
			$status = (string)($grn['statusCode'] ?? '');
			if ($status === 'accepted' || $status === 'quality_checked') {
				$hasAcceptedGrn = true;
				break;
			}
		}

		$hasAcceptedSvcReceipt = false;
		foreach ($matchedSvcReceipts as $svcReceipt) {
			$status = (string)($svcReceipt['statusCode'] ?? '');
			if ($status === 'accepted') {
				$hasAcceptedSvcReceipt = true;
				break;
			}
		}

		$matchedGrnIds = [];
		foreach ($matchedGrns as $grn) {
			$id = (string)($grn['id'] ?? ($grn['@self']['id'] ?? ''));
			if ($id !== '') {
				$matchedGrnIds[] = $id;
			}
		}

		foreach ($matchedSvcReceipts as $svcReceipt) {
			$id = (string)($svcReceipt['id'] ?? ($svcReceipt['@self']['id'] ?? ''));
			if ($id !== '') {
				// SvcReceipt ids are recorded in matchedGrnIds alongside GRN
				// ids — both registers feed the same "third leg" concept and
				// ThreeWayMatch.matchedGrnIds is the historical field name
				// for it; splitting into a parallel matchedSvcReceiptIds
				// field would only fragment the audit trail for no benefit.
				$matchedGrnIds[] = $id;
			}
		}

		if ($hasAcceptedGrn === false && $hasAcceptedSvcReceipt === false) {
			return $this->routeToException(
				administrationId: $invoiceAdmin,
				invoice: $invoice,
				matchStatus: self::STATUS_EXCEPTION_MISSING_GRN,
				divergences: [
					['field' => 'grn', 'expected' => 'accepted', 'actual' => 'missing'],
				],
				matchedPoIds: [$poId],
				matchedGrnIds: $matchedGrnIds
			);
		}

		// Match invoice lines to (PO line, GRN line) tuples.
		$invoiceLines = (array)($invoice['lines'] ?? []);
		$grnLines = [];
		foreach ($matchedGrns as $grn) {
			$grnId = (string)($grn['id'] ?? ($grn['@self']['id'] ?? ''));
			if ($grnId === '') {
				continue;
			}

			$rows = $this->findAll(
				schema: self::SCHEMA_GRN_LINE,
				filters: ['grnId' => $grnId, 'administrationId' => $invoiceAdmin]
			);
			foreach ($rows as $row) {
				$grnLines[] = $row;
			}
		}

		// Merge in SvcReceiptLine rows — they carry the same
		// quantityAccepted/quantityReceived field names as GoodsReceiptLine
		// by design (see ServiceReceiptService), so matchLineItems() /
		// calculateDivergence() need zero receipt-type branching.
		foreach ($matchedSvcReceipts as $svcReceipt) {
			$svcReceiptId = (string)($svcReceipt['id'] ?? ($svcReceipt['@self']['id'] ?? ''));
			if ($svcReceiptId === '') {
				continue;
			}

			$rows = $this->findAll(
				schema: self::SCHEMA_SVC_RECEIPT_LINE,
				filters: ['serviceReceiptId' => $svcReceiptId, 'administrationId' => $invoiceAdmin]
			);
			foreach ($rows as $row) {
				$grnLines[] = $row;
			}
		}

		$tuples = $this->matchLineItems(
			invoiceLines: $invoiceLines,
			poLines: $poLines,
			grnLines: $grnLines
		);

		// Resolve the applicable tolerance profile (most-specific).
		$profile = $this->toleranceService->getApplicableProfile(
			administrationId: $invoiceAdmin,
			candidate: [
				'supplierId' => (string)($invoice['supplierId'] ?? ''),
				'productCategory' => $this->extractProductCategory(poLines: $poLines),
				'glAccount' => $this->extractGlAccount(poLines: $poLines),
			]
		);

		// Score every tuple.
		$divergences = [];
		$hadPriceFail = false;
		$hadQtyFail = false;
		$hadAnyDelta = false;
		foreach ($tuples as $tuple) {
			$deltaSet = $this->calculateDivergence(
				invoiceLine: $tuple['invoiceLine'],
				poLine:      $tuple['poLine'],
				grnLine:     $tuple['grnLine']
			);

			foreach ($deltaSet as $delta) {
				$within = $this->evaluateTolerance(divergence: $delta, profile: $profile);
				$toleranceProfileId = null;
				if ($profile !== null) {
					$toleranceProfileId = (string)($profile['profileId'] ?? ($profile['id'] ?? ''));
				}

				$delta['toleranceProfileId'] = $toleranceProfileId;
				$divergences[] = $delta;

				if (($delta['deltaCents'] ?? 0) !== 0 || ($delta['deltaPercentage'] ?? 0) !== 0) {
					$hadAnyDelta = true;
				}

				if ($within === false) {
					if ($delta['field'] === 'unitPrice' || $delta['field'] === 'lineTotal' || $delta['field'] === 'vat') {
						$hadPriceFail = true;
					} elseif ($delta['field'] === 'quantity') {
						$hadQtyFail = true;
					}
				}
			}//end foreach
		}//end foreach

		$matchStatus = self::STATUS_AUTO_APPROVED;
		if ($hadPriceFail === true) {
			$matchStatus = self::STATUS_EXCEPTION_PRICE;
		} elseif ($hadQtyFail === true) {
			$matchStatus = self::STATUS_EXCEPTION_QUANTITY;
		} elseif ($hadAnyDelta === true) {
			$matchStatus = self::STATUS_WITHIN_TOLERANCE;
		}

		$match = [
			'invoiceId' => $invoiceId,
			'matchedPoIds' => [$poId],
			'matchedGrnIds' => $matchedGrnIds,
			'matchStatus' => $matchStatus,
			'divergenceDetails' => $divergences,
			'costCenter' => (string)($po['costCenter'] ?? ''),
			'projectCode' => (string)($po['projectCode'] ?? ''),
			'createdAt' => $this->nowIso(),
			'administrationId' => $invoiceAdmin,
		];

		$saved = $this->saveObject(schema: self::SCHEMA_THREE_WAY_MATCH, object: $match);

		// Transition the invoice — matching → matched (auto / within
		// tolerance) or matching → exception (out of tolerance).
		if ($matchStatus === self::STATUS_AUTO_APPROVED || $matchStatus === self::STATUS_WITHIN_TOLERANCE) {
			$this->safeTransition(administrationId: $invoiceAdmin, invoiceId: $invoiceId, toStatus: 'matched');
		} else {
			$this->safeTransition(administrationId: $invoiceAdmin, invoiceId: $invoiceId, toStatus: 'exception');
		}

		return $saved;
	}//end evaluateMatch()

	/**
	 * Match invoice lines to (PO line, GRN line) tuples by product code,
	 * falling back to line-number when product codes are absent on
	 * either side (services invoices typically lack catalogue codes).
	 *
	 * The matcher is per-line — each invoice line yields one tuple with
	 * either matched PO/GRN lines or nulls (which the scorer translates
	 * into the appropriate exception). Multi-line invoices match in
	 * O(invoice × po) which is acceptable for realistic line counts.
	 *
	 * @param array<int,array<string,mixed>> $invoiceLines Invoice line items.
	 * @param array<int,array<string,mixed>> $poLines PO lines.
	 * @param array<int,array<string,mixed>> $grnLines GRN lines (across the matched GRNs).
	 *
	 * @return array<int,array{invoiceLine: array<string,mixed>, poLine: array<string,mixed>|null, grnLine: array<string,mixed>|null}>
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
	 */
	public function matchLineItems(array $invoiceLines, array $poLines, array $grnLines): array {
		$tuples = [];
		foreach ($invoiceLines as $invoiceLine) {
			$productCode = trim((string)($invoiceLine['productCode'] ?? ''));
			$lineNumber = (int)($invoiceLine['lineNumber'] ?? 0);

			$poLine = $this->findPoLine(poLines: $poLines, productCode: $productCode, lineNumber: $lineNumber);
			$grnLine = null;
			if ($poLine !== null) {
				$poLineId = (string)($poLine['id'] ?? ($poLine['@self']['id'] ?? ''));
				$grnLine = $this->findGrnLine(grnLines: $grnLines, poLineId: $poLineId);
			}

			$tuples[] = [
				'invoiceLine' => $invoiceLine,
				'poLine' => $poLine,
				'grnLine' => $grnLine,
			];
		}

		return $tuples;
	}//end matchLineItems()

	/**
	 * Compute per-field divergence for one (invoice, PO, GRN) tuple.
	 *
	 * Emitted fields (when applicable):
	 *  - unitPrice: signed delta in cents (invoice - po), with percentage
	 *    in basis points of the PO unit price;
	 *  - quantity: signed delta in thousandths (invoice - grnAccepted),
	 *    falling back to (invoice - poOrdered) when GRN is absent;
	 *  - vat: signed delta in cents (invoice vatAmount - po vatAmount);
	 *  - deliveryDate: signed delta in days (grn received - po expected).
	 *
	 * Each entry carries an `expected` + `actual` pair for the
	 * divergenceDetails JSON. Missing PO/GRN lines yield a presence
	 * marker so the caller can still classify the row.
	 *
	 * @param array<string,mixed> $invoiceLine Invoice line item.
	 * @param array<string,mixed>|null $poLine Matched PO line (or null).
	 * @param array<string,mixed>|null $grnLine Matched GRN line (or null).
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
	 */
	public function calculateDivergence(array $invoiceLine, ?array $poLine, ?array $grnLine): array {
		$deltas = [];

		if ($poLine === null) {
			$deltas[] = [
				'field' => 'po_line',
				'expected' => 'matched',
				'actual' => 'missing',
				'deltaCents' => null,
				'deltaPercentage' => null,
			];
			return $deltas;
		}

		// Unit-price delta in cents (integer arithmetic).
		$invoiceUnitPriceCents = (int)($invoiceLine['unitPrice'] ?? 0);
		$poUnitPriceCents = (int)($poLine['unitPrice'] ?? 0);
		$unitPriceDelta = $invoiceUnitPriceCents - $poUnitPriceCents;
		$unitPricePct = 0;
		if ($poUnitPriceCents !== 0) {
			$unitPricePct = (int)round((($unitPriceDelta * 10000) / abs($poUnitPriceCents)), 0, PHP_ROUND_HALF_UP);
		}

		$deltas[] = [
			'field' => 'unitPrice',
			'expected' => $poUnitPriceCents,
			'actual' => $invoiceUnitPriceCents,
			'deltaCents' => $unitPriceDelta,
			'deltaPercentage' => $unitPricePct,
		];

		// Quantity delta in thousandths. Prefer GRN accepted; fall back
		// to PO ordered when the GRN line is absent (services case).
		$invoiceQty = $this->thousandths(value: (float)($invoiceLine['quantity'] ?? 0));
		if ($grnLine !== null) {
			$expectedQty = $this->thousandths(value: (float)($grnLine['quantityAccepted'] ?? $grnLine['quantityReceived'] ?? 0));
			$qtySource = 'grn';
		} else {
			$expectedQty = $this->thousandths(value: (float)($poLine['quantityOrdered'] ?? 0));
			$qtySource = 'po';
		}

		$qtyDelta = $invoiceQty - $expectedQty;
		$qtyPct = 0;
		if ($expectedQty !== 0) {
			$qtyPct = (int)round((($qtyDelta * 10000) / abs($expectedQty)), 0, PHP_ROUND_HALF_UP);
		}

		$deltas[] = [
			'field' => 'quantity',
			'expected' => $expectedQty,
			'actual' => $invoiceQty,
			'deltaCents' => null,
			'deltaPercentage' => $qtyPct,
			'source' => $qtySource,
		];

		// VAT delta in cents — invoice vatRate × invoice lineExtension vs
		// PO vatAmount. The invoice schema carries vatRate as a fraction
		// (0.21); the PO schema carries vatRate as basis points (2100)
		// and vatAmount in cents. We compute both in cents to compare.
		$invoiceLineExt = (int)($invoiceLine['lineExtension'] ?? 0);
		$invoiceVatRate = (float)($invoiceLine['vatRate'] ?? 0);
		$invoiceVatCents = (int)round(($invoiceLineExt * $invoiceVatRate), 0, PHP_ROUND_HALF_UP);

		$poVatCents = (int)($poLine['vatAmount'] ?? 0);
		$vatDelta = $invoiceVatCents - $poVatCents;
		$deltas[] = [
			'field' => 'vat',
			'expected' => $poVatCents,
			'actual' => $invoiceVatCents,
			'deltaCents' => $vatDelta,
			'deltaPercentage' => null,
		];

		// Delivery-date delta in days (only meaningful when a GRN is
		// present, since the PO's expected date is what the receipt
		// matched against).
		if ($grnLine !== null) {
			// GRN lines don't carry a per-line date; use the parent GRN
			// receivedAt via the matcher (added as $grnLine['_receivedAt']
			// when known). When absent, skip the date delta.
			$expectedDate = trim((string)($poLine['expectedDeliveryDate'] ?? ''));
			$actualDate = trim((string)($grnLine['_receivedAt'] ?? ''));
			if ($expectedDate !== '' && $actualDate !== '') {
				$deltaDays = $this->dateDeltaDays(expected: $expectedDate, actual: $actualDate);
				$deltas[] = [
					'field' => 'deliveryDate',
					'expected' => $expectedDate,
					'actual' => substr($actualDate, 0, 10),
					'deltaCents' => null,
					'deltaPercentage' => null,
					'deltaDays' => $deltaDays,
				];
			}
		}

		return $deltas;
	}//end calculateDivergence()

	/**
	 * Decide whether a single divergence entry is within tolerance.
	 *
	 * Dispatches on the field name to the right ToleranceProfileService
	 * predicate. unitPrice + lineTotal + vat use the "more permissive"
	 * price rule; quantity uses the proportional quantity rule;
	 * deliveryDate uses the absolute day rule; everything else (presence
	 * markers etc.) is treated as "not within tolerance" so the engine
	 * defers the call to the exception workflow.
	 *
	 * @param array<string,mixed> $divergence Divergence entry.
	 * @param array<string,mixed>|null $profile Applicable ToleranceProfile (or null).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
	 */
	public function evaluateTolerance(array $divergence, ?array $profile): bool {
		$field = (string)($divergence['field'] ?? '');
		switch ($field) {
			case 'unitPrice':
			case 'lineTotal':
			case 'vat':
				$expected = (int)($divergence['expected'] ?? 0);
				$actual = (int)($divergence['actual'] ?? 0);
				return $this->toleranceService->evaluateWithinTolerance(
					expectedCents: $expected,
					actualCents:   $actual,
					profile:       $profile
				);

			case 'quantity':
				$expected = (int)($divergence['expected'] ?? 0);
				$actual = (int)($divergence['actual'] ?? 0);
				return $this->toleranceService->evaluateQuantityVariance(
					expectedThousandths: $expected,
					actualThousandths:   $actual,
					profile:             $profile
				);

			case 'deliveryDate':
				$delta = (int)($divergence['deltaDays'] ?? 0);
				return $this->toleranceService->evaluateDateVariance(deltaDays: $delta, profile: $profile);
			default:
				// Presence markers (po_line missing etc.) are never within tolerance.
				return false;
		}//end switch

	}//end evaluateTolerance()

	/**
	 * Write a ThreeWayMatch with the supplied exception status and
	 * transition the invoice to `exception` (REQ-PO3W-004).
	 *
	 * The resolution UI lives in slice 08; this method only routes the
	 * record into the exception queue.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $invoice Loaded SupplierInvoice.
	 * @param string $matchStatus One of the exception_* enum values.
	 * @param array<int,array<string,mixed>> $divergences Divergence breakdown.
	 * @param array<int,string> $matchedPoIds Candidate PO ids (may be empty).
	 * @param array<int,string> $matchedGrnIds Candidate GRN ids (may be empty).
	 *
	 * @return array<string,mixed> The persisted ThreeWayMatch record.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-06-matching-engine/tasks.md
	 */
	public function routeToException(
		string $administrationId,
		array $invoice,
		string $matchStatus,
		array $divergences,
		array $matchedPoIds,
		array $matchedGrnIds,
	): array {
		$invoiceId = (string)($invoice['id'] ?? ($invoice['@self']['id'] ?? ''));
		$match = [
			'invoiceId' => $invoiceId,
			'matchedPoIds' => $matchedPoIds,
			'matchedGrnIds' => $matchedGrnIds,
			'matchStatus' => $matchStatus,
			'divergenceDetails' => $divergences,
			'costCenter' => (string)($invoice['costCenter'] ?? ''),
			'projectCode' => (string)($invoice['projectCode'] ?? ''),
			'createdAt' => $this->nowIso(),
			'administrationId' => $administrationId,
		];

		$saved = $this->saveObject(schema: self::SCHEMA_THREE_WAY_MATCH, object: $match);
		$this->safeTransition(administrationId: $administrationId, invoiceId: $invoiceId, toStatus: 'exception');

		// Alert the crediteuren-administrateur. REQ-PO3W-004
		// (openspec/specs/bookkeeping-purchase-order-3way/spec.md:291) requires
		// the system to "create a notification for the crediteuren-
		// administrateur" when it marks an exception status, and :300 spells
		// out "routes to crediteuren-administrateur via notification". Until
		// now the record went into the exception queue and alerted NOBODY:
		// ExceptionResolutionService::notifyOnException() implemented exactly
		// this and had zero callers, and the engine contained no notification
		// code at all. Its docblock even names this site — "wired from the
		// matching engine in slice 06 once it ships".
		//
		// Fail-soft: the ThreeWayMatch is already persisted and the invoice
		// already transitioned, so a notification outage must not roll the
		// match back or bubble a 500 into the evaluate endpoint. The service
		// re-reads the match by id and re-checks the exception status itself,
		// so passing the saved id is sufficient and cannot notify on a
		// non-exception record.
		$this->notifyException(administrationId: $administrationId, match: $saved);

		return $saved;
	}//end routeToException()

	/**
	 * Raise the exception alert for a freshly routed ThreeWayMatch.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $match The persisted ThreeWayMatch.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md
	 */
	private function notifyException(string $administrationId, array $match): void {
		if ($this->exceptionResolution === null) {
			return;
		}

		$matchId = (string)($match['id'] ?? ($match['@self']['id'] ?? ''));
		if ($matchId === '') {
			return;
		}

		try {
			$this->exceptionResolution->notifyOnException(
				administrationId: $administrationId,
				matchId: $matchId
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ThreeWayMatchingEngine: exception alert failed (fail-soft)',
				['matchId' => $matchId, 'exception' => $e->getMessage()]
			);
		}

	}//end notifyException()

	/**
	 * Pick the PO line that best matches an invoice line — exact product
	 * code wins; line-number fallback when product codes are absent.
	 *
	 * @param array<int,array<string,mixed>> $poLines PO lines.
	 * @param string $productCode Invoice product code.
	 * @param int $lineNumber Invoice line number.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findPoLine(array $poLines, string $productCode, int $lineNumber): ?array {
		if ($productCode !== '') {
			foreach ($poLines as $poLine) {
				if ((string)($poLine['productOrServiceCode'] ?? '') === $productCode) {
					return $poLine;
				}
			}
		}

		if ($lineNumber > 0) {
			foreach ($poLines as $poLine) {
				if ((int)($poLine['lineNumber'] ?? 0) === $lineNumber) {
					return $poLine;
				}
			}
		}

		return null;
	}//end findPoLine()

	/**
	 * Pick the GRN line whose poLineId matches the given PO line id. When
	 * multiple receipts land against the same PO line (partial-receipt
	 * case) the quantities are summed so the comparison stays bit-exact.
	 *
	 * @param array<int,array<string,mixed>> $grnLines GRN lines.
	 * @param string $poLineId PO line id to match.
	 *
	 * @return array<string,mixed>|null
	 */
	private function findGrnLine(array $grnLines, string $poLineId): ?array {
		if ($poLineId === '') {
			return null;
		}

		$candidates = [];
		foreach ($grnLines as $grnLine) {
			if ((string)($grnLine['poLineId'] ?? '') === $poLineId) {
				$candidates[] = $grnLine;
			}
		}

		if ($candidates === []) {
			return null;
		}

		if (count($candidates) === 1) {
			return $candidates[0];
		}

		// Multi-receipt: sum accepted + received across all matching lines
		// so the engine sees one canonical row.
		$accepted = 0.0;
		$received = 0.0;
		foreach ($candidates as $grnLine) {
			$accepted += (float)($grnLine['quantityAccepted'] ?? 0);
			$received += (float)($grnLine['quantityReceived'] ?? 0);
		}

		$first = $candidates[0];
		$first['quantityAccepted'] = $accepted;
		$first['quantityReceived'] = $received;
		return $first;
	}//end findGrnLine()

	/**
	 * Extract the product category to feed the tolerance scope resolver.
	 * Currently the PO line schema does not carry an explicit category
	 * (slice 01's `productOrServiceCode` is the catalogue FK) so we
	 * return ''; the category-scope branch of the tolerance resolver
	 * then short-circuits.
	 *
	 * @param array<int,array<string,mixed>> $poLines PO lines.
	 *
	 * @return string
	 */
	private function extractProductCategory(array $poLines): string {
		foreach ($poLines as $poLine) {
			$category = trim((string)($poLine['productCategory'] ?? ''));
			if ($category !== '') {
				return $category;
			}
		}

		return '';
	}//end extractProductCategory()

	/**
	 * Extract the GL account from the first PO line that carries one;
	 * used by the tolerance scope resolver's gl_account tier.
	 *
	 * @param array<int,array<string,mixed>> $poLines PO lines.
	 *
	 * @return string
	 */
	private function extractGlAccount(array $poLines): string {
		foreach ($poLines as $poLine) {
			$account = trim((string)($poLine['glAccount'] ?? ''));
			if ($account !== '') {
				return $account;
			}
		}

		return '';
	}//end extractGlAccount()

	/**
	 * Resolve the PO id(s) the invoice should match against.
	 *
	 * Source of truth ordering:
	 *  1. invoice['matchedPoIds'] (set by the OCR + UBL pipeline when the
	 *     invoice carries a PO reference);
	 *  2. invoice['poId'] / invoice['purchaseOrderId'] (single-PO scalar);
	 *  3. fall back to looking up by paymentReference / supplierReference
	 *     against the PurchaseOrder register.
	 *
	 * Cross-tenant references are filtered out at this step (the find
	 * call is administration-scoped) so the engine never leaks the
	 * existence of a foreign PO.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $invoice Loaded SupplierInvoice.
	 *
	 * @return array<int,string>
	 */
	private function resolvePoIds(string $administrationId, array $invoice): array {
		$ids = [];
		$matched = $invoice['matchedPoIds'] ?? null;
		if (is_array($matched) === true) {
			foreach ($matched as $id) {
				$trimmed = trim((string)$id);
				if ($trimmed !== '') {
					$ids[] = $trimmed;
				}
			}
		}

		if ($ids === []) {
			$scalar = trim((string)($invoice['poId'] ?? $invoice['purchaseOrderId'] ?? ''));
			if ($scalar !== '') {
				$ids[] = $scalar;
			}
		}

		// Filter by tenant scope.
		$validated = [];
		foreach (array_values(array_unique($ids)) as $id) {
			$po = $this->findOne(
				schema: self::SCHEMA_PO,
				filters: ['id' => $id, 'administrationId' => $administrationId]
			);
			if ($po !== null) {
				$validated[] = $id;
			}
		}

		return $validated;
	}//end resolvePoIds()

	/**
	 * Convert a quantity float to integer thousandths.
	 *
	 * @param float $value Quantity value.
	 *
	 * @return int Thousandths.
	 */
	private function thousandths(float $value): int {
		return (int)round(($value * 1000.0), 0, PHP_ROUND_HALF_UP);
	}//end thousandths()

	/**
	 * Day delta between two ISO date strings (actual - expected). Returns
	 * 0 when either side is unparseable.
	 *
	 * @param string $expected Expected ISO date.
	 * @param string $actual Actual ISO date(-time).
	 *
	 * @return int
	 */
	private function dateDeltaDays(string $expected, string $actual): int {
		try {
			$expectedDt = new DateTimeImmutable($expected);
			$actualDt = new DateTimeImmutable(substr($actual, 0, 10));
		} catch (\Throwable $e) {
			return 0;
		}

		$secondsPerDay = 86400;
		return (int)round((($actualDt->getTimestamp() - $expectedDt->getTimestamp()) / $secondsPerDay));
	}//end dateDeltaDays()

	/**
	 * Best-effort lifecycle transition on the SupplierInvoice. Illegal
	 * transitions are swallowed (logged at debug) so re-evaluating an
	 * already-matched / already-exception invoice does not fail the
	 * matching call — the freshly-written ThreeWayMatch is still the
	 * canonical record.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $invoiceId SupplierInvoice id.
	 * @param string $toStatus Target lifecycle state.
	 *
	 * @return void
	 */
	private function safeTransition(string $administrationId, string $invoiceId, string $toStatus): void {
		try {
			$this->invoiceService->setStatus(
				administrationId: $administrationId,
				invoiceId:        $invoiceId,
				toStatus:         $toStatus
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'ThreeWayMatchingEngine: lifecycle transition skipped',
				['invoiceId' => $invoiceId, 'toStatus' => $toStatus, 'exception' => $e->getMessage()]
			);
		}

	}//end safeTransition()

	/**
	 * Persist an object via OR's real ObjectService API (saveObject).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object Object to persist.
	 *
	 * @return array<string,mixed>
	 *
	 * @throws \RuntimeException When the persistence call fails.
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
				'ThreeWayMatchingEngine: failed to persist object',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			throw new RuntimeException('Failed to persist ' . $schema);
		}

	}//end saveObject()

	/**
	 * Fetch one record via the real ObjectService API.
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
	 * Fetch all matching records via the real ObjectService API.
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
				'ThreeWayMatchingEngine: failed to query OpenRegister',
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
