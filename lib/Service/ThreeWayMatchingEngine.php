<?php

/**
 * Three-Way Matching Engine
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * exception_* status (exception_price / exception_quantity / exception_date /
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
 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Kernel\Contracts\ThreeWayMatchingEngineInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
 * @SuppressWarnings(PHPMD.NPathComplexity)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic (multi-axis match decision); deferred pending a dedicated
 * refactor.
 */
class ThreeWayMatchingEngine implements ThreeWayMatchingEngineInterface {

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
	 * Match outcome — delivery date outside the date tolerance, early or late.
	 *
	 * @var string
	 */
	public const STATUS_EXCEPTION_DATE = 'exception_date';

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
	 * Receipt schema => [line schema, the line field naming the receipt].
	 *
	 * Goods receipts come first, so their ids and lines precede service
	 * receipts in every match.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const RECEIPT_LINES = [
		self::SCHEMA_GRN => [self::SCHEMA_GRN_LINE, 'grnId'],
		self::SCHEMA_SVC_RECEIPT => [self::SCHEMA_SVC_RECEIPT_LINE, 'serviceReceiptId'],
	];

	/**
	 * Receipt schema => the statuses that count as an accepted third leg.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const ACCEPTED_RECEIPT_STATUSES = [
		self::SCHEMA_GRN => ['accepted', 'quality_checked'],
		self::SCHEMA_SVC_RECEIPT => ['accepted'],
	];

	/**
	 * Divergence field => the axis that fails when it is out of tolerance.
	 *
	 * @var array<string,string>
	 */
	private const FAILED_AXIS = [
		'po_line' => 'missing_po',
		'grn_line' => 'missing_grn',
		'unitPrice' => 'price',
		'lineTotal' => 'price',
		'vat' => 'price',
		'quantity' => 'quantity',
		'deliveryDate' => 'date',
	];

	/**
	 * Outcome => match status, strongest first.
	 *
	 * @var array<string,string>
	 */
	private const OUTCOME_STATUS = [
		'missing_po' => self::STATUS_EXCEPTION_MISSING_PO,
		'missing_grn' => self::STATUS_EXCEPTION_MISSING_GRN,
		'price' => self::STATUS_EXCEPTION_PRICE,
		'quantity' => self::STATUS_EXCEPTION_QUANTITY,
		'date' => self::STATUS_EXCEPTION_DATE,
		'delta' => self::STATUS_WITHIN_TOLERANCE,
	];

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
	 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
	 */
	public function evaluateMatch(string $administrationId, string $invoiceId): array {
		$run = $this->openRun(administrationId: $administrationId, invoiceId: $invoiceId);

		// Move the invoice into "matching" so the audit trail captures the
		// intermediate state. Re-evaluating an already-matched invoice hits
		// an "illegal transition" guard in setStatus and still writes a new
		// ThreeWayMatch.
		$this->safeTransition(administrationId: $run->administrationId, invoiceId: $invoiceId, toStatus: 'matching');

		$poIds = $this->resolvePoIds(administrationId: $run->administrationId, invoice: $run->invoice);
		if (count($poIds) !== 1) {
			return $this->routePoException(run: $run, poIds: $poIds);
		}

		return $this->matchAgainstPo(run: $run, poId: $poIds[0]);
	}//end evaluateMatch()

	/**
	 * Load the SupplierInvoice under the caller's administration.
	 *
	 * The run takes its administrationId from the invoice record, not from
	 * the caller; the controller has already asserted the caller can see
	 * that scope.
	 *
	 * @param string $administrationId Administration scope from the controller.
	 * @param string $invoiceId        SupplierInvoice id.
	 *
	 * @return ThreeWayMatchRun
	 *
	 * @throws \RuntimeException When the scope is blank or the invoice is unknown.
	 */
	private function openRun(string $administrationId, string $invoiceId): ThreeWayMatchRun {
		if ($administrationId === '') {
			throw new RuntimeException('administrationId is required');
		}

		$invoice = null;
		if ($invoiceId !== '') {
			$invoice = $this->findOne(
				schema: self::SCHEMA_SUPPLIER_INVOICE,
				filters: [
					'id' => $invoiceId,
					'administrationId' => $administrationId,
				]
			);
		}

		if ($invoice === null) {
			throw new RuntimeException('Supplier invoice not found');
		}

		$run = new ThreeWayMatchRun(
			administrationId: (string)($invoice['administrationId'] ?? $administrationId),
			invoiceId: $invoiceId
		);
		$run->invoice = $invoice;
		return $run;
	}//end openRun()

	/**
	 * Route an invoice that resolves to no PO, or to more than one, to
	 * exception_missing_po.
	 *
	 * Multi-PO consolidation is member 07's job: the `multi_po` divergence
	 * hands it the candidate set.
	 *
	 * @param ThreeWayMatchRun $run   The evaluation.
	 * @param array<int,string> $poIds The tenant-validated PO ids.
	 *
	 * @return array<string,mixed> The persisted ThreeWayMatch record.
	 */
	private function routePoException(ThreeWayMatchRun $run, array $poIds): array {
		$divergences = [['field' => 'po', 'expected' => 'matched', 'actual' => 'missing']];
		if (count($poIds) > 1) {
			$divergences = [['field' => 'multi_po', 'expected' => 1, 'actual' => count($poIds)]];
		}

		$route = new ExceptionRoute(invoice: $run->invoice, matchStatus: self::STATUS_EXCEPTION_MISSING_PO);
		$route->divergences  = $divergences;
		$route->matchedPoIds = $poIds;

		return $this->routeToException(administrationId: $run->administrationId, route: $route);
	}//end routePoException()

	/**
	 * Match the invoice against its single PO, or route it to
	 * exception_missing_grn when the PO has no accepted receipt.
	 *
	 * @param ThreeWayMatchRun $run  The evaluation.
	 * @param string           $poId The tenant-validated PO id.
	 *
	 * @return array<string,mixed> The persisted ThreeWayMatch record.
	 */
	private function matchAgainstPo(ThreeWayMatchRun $run, string $poId): array {
		$run->poId = $poId;
		$run->po = $this->findOne(
			schema: self::SCHEMA_PO,
			filters: ['id' => $poId, 'administrationId' => $run->administrationId]
		);
		$run->poLines = $this->findAll(
			schema: self::SCHEMA_PO_LINE,
			filters: ['poId' => $poId, 'administrationId' => $run->administrationId]
		);
		$run->receipts = $this->receiptsForPo(run: $run);

		if ($this->hasAcceptedReceipt(receipts: $run->receipts) === true) {
			return $this->recordLineMatch(run: $run);
		}

		$route = new ExceptionRoute(invoice: $run->invoice, matchStatus: self::STATUS_EXCEPTION_MISSING_GRN);
		$route->divergences   = [
			['field' => 'grn', 'expected' => 'accepted', 'actual' => 'missing'],
		];
		$route->matchedPoIds  = [$poId];
		$route->matchedGrnIds = $this->receiptIds(receipts: $run->receipts);

		return $this->routeToException(administrationId: $run->administrationId, route: $route);
	}//end matchAgainstPo()

	/**
	 * Score the invoice lines against the PO and receipt lines, write the
	 * ThreeWayMatch and move the invoice to matched or exception.
	 *
	 * @param ThreeWayMatchRun $run The evaluation, with its PO and receipts.
	 *
	 * @return array<string,mixed> The persisted ThreeWayMatch record.
	 */
	private function recordLineMatch(ThreeWayMatchRun $run): array {
		$tuples = $this->matchLineItems(
			invoiceLines: (array)($run->invoice['lines'] ?? []),
			poLines: self::withOrderDeliveryDate(poLines: $run->poLines, po: $run->po),
			grnLines: $this->receiptLines(run: $run)
		);
		$scored = $this->scoreTuples(tuples: $tuples, profile: $this->profileFor(run: $run));

		$saved = $this->saveObject(
			schema: self::SCHEMA_THREE_WAY_MATCH,
			object: [
				'invoiceId' => $run->invoiceId,
				'matchedPoIds' => [$run->poId],
				'matchedGrnIds' => $this->receiptIds(receipts: $run->receipts),
				'matchStatus' => $scored['matchStatus'],
				'divergenceDetails' => $scored['divergences'],
				'costCenter' => (string)($run->po['costCenter'] ?? ''),
				'projectCode' => (string)($run->po['projectCode'] ?? ''),
				'createdAt' => $this->nowIso(),
				'administrationId' => $run->administrationId,
			]
		);

		$toStatus = 'exception';
		if (in_array($scored['matchStatus'], [self::STATUS_AUTO_APPROVED, self::STATUS_WITHIN_TOLERANCE], true) === true) {
			$toStatus = 'matched';
		}

		$this->safeTransition(administrationId: $run->administrationId, invoiceId: $run->invoiceId, toStatus: $toStatus);
		return $saved;
	}//end recordLineMatch()

	/**
	 * Find the third-leg receipts that reference the PO, per receipt schema.
	 *
	 * A service PurchaseOrderLine never has a GoodsReceiptNote, so an
	 * accepted SvcReceipt (prestatieverklaring) is the alternative third leg
	 * (REQ-PO3W-011).
	 *
	 * @param ThreeWayMatchRun $run The evaluation, with its PO id.
	 *
	 * @return array<string,array<int,array<string,mixed>>> Receipt schema => receipts.
	 */
	private function receiptsForPo(ThreeWayMatchRun $run): array {
		$poId = $run->poId;
		$receipts = [];
		foreach (array_keys(self::RECEIPT_LINES) as $schema) {
			$rows = $this->findAll(schema: $schema, filters: ['administrationId' => $run->administrationId]);
			$receipts[$schema] = array_values(
				array_filter(
					$rows,
					static fn (array $row): bool => in_array($poId, (array)($row['poIds'] ?? []), true)
				)
			);
		}

		return $receipts;
	}//end receiptsForPo()

	/**
	 * The ids of the receipts, goods receipts first.
	 *
	 * SvcReceipt ids go into matchedGrnIds next to GRN ids: both feed the
	 * same third leg, and matchedGrnIds is the historical field for it.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $receipts Receipt schema => receipts.
	 *
	 * @return array<int,string>
	 */
	private function receiptIds(array $receipts): array {
		$ids = [];
		foreach ($receipts as $rows) {
			foreach ($rows as $row) {
				$id = $this->idOf(row: $row);
				if ($id !== '') {
					$ids[] = $id;
				}
			}
		}

		return $ids;
	}//end receiptIds()

	/**
	 * Whether any receipt is in an accepted status for its schema.
	 *
	 * Without one the invoice goes to exception_missing_grn.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $receipts Receipt schema => receipts.
	 *
	 * @return bool
	 */
	private function hasAcceptedReceipt(array $receipts): bool {
		foreach ($receipts as $schema => $rows) {
			foreach ($rows as $row) {
				if (in_array((string)($row['statusCode'] ?? ''), self::ACCEPTED_RECEIPT_STATUSES[$schema], true) === true) {
					return true;
				}
			}
		}

		return false;
	}//end hasAcceptedReceipt()

	/**
	 * Load the lines of every accepted receipt.
	 *
	 * Draft and rejected receipts do not count as delivered, so their lines
	 * are left out of the match.
	 *
	 * SvcReceiptLine carries the same quantity field names as
	 * GoodsReceiptLine (see ServiceReceiptService), so the line matcher
	 * needs no receipt-type branching.
	 *
	 * @param ThreeWayMatchRun $run The evaluation, with its receipts.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function receiptLines(ThreeWayMatchRun $run): array {
		$lines = [];
		foreach ($run->receipts as $schema => $rows) {
			[$lineSchema, $receiptKey] = self::RECEIPT_LINES[$schema];
			foreach ($rows as $row) {
				$receiptId = $this->idOf(row: $row);
				$accepted = in_array((string)($row['statusCode'] ?? ''), self::ACCEPTED_RECEIPT_STATUSES[$schema], true);
				if ($receiptId !== '' && $accepted === true) {
					$found = $this->findAll(
						schema: $lineSchema,
						filters: [$receiptKey => $receiptId, 'administrationId' => $run->administrationId]
					);
					// A goods receipt line is dated by its receipt; a service
					// receipt is not date checked (REQ-TWM-003).
					if ($schema === self::SCHEMA_GRN) {
						$found = self::withReceivedAt(lines: $found, receivedAt: trim((string)($row['receivedAt'] ?? '')));
					}

					array_push($lines, ...$found);
				}
			}
		}

		return $lines;
	}//end receiptLines()

	/**
	 * Goods receipt lines stamped with their receipt's `receivedAt` as
	 * `_receivedAt`, the actual date the delivery-date check reads.
	 *
	 * @param array<int, array<string, mixed>> $lines      The receipt's lines.
	 * @param string                           $receivedAt The receipt's receivedAt; '' leaves the lines undated.
	 *
	 * @return array<int, array<string, mixed>> The lines.
	 *
	 * @spec openspec/changes/three-way-match-delivery-date-exception/specs/bookkeeping-three-way-matching-engine/spec.md#requirement-a-delivery-outside-the-date-tolerance-is-an-exception-req-twm-003
	 */
	private static function withReceivedAt(array $lines, string $receivedAt): array {
		if ($receivedAt === '') {
			return $lines;
		}

		foreach ($lines as $index => $line) {
			$lines[$index]['_receivedAt'] = $receivedAt;
		}

		return $lines;
	}//end withReceivedAt()

	/**
	 * Order lines with the order's `expectedDeliveryDate` where a line names
	 * none, so the line's own date wins and the order's is the fallback.
	 *
	 * @param array<int, array<string, mixed>> $poLines The order's lines.
	 * @param array<string, mixed>|null        $po      The order.
	 *
	 * @return array<int, array<string, mixed>> The lines.
	 *
	 * @spec openspec/changes/three-way-match-delivery-date-exception/specs/bookkeeping-three-way-matching-engine/spec.md#requirement-a-delivery-outside-the-date-tolerance-is-an-exception-req-twm-003
	 */
	private static function withOrderDeliveryDate(array $poLines, ?array $po): array {
		$orderDate = trim((string)($po['expectedDeliveryDate'] ?? ''));
		if ($orderDate === '') {
			return $poLines;
		}

		foreach ($poLines as $index => $poLine) {
			if (is_array($poLine) === true && trim((string)($poLine['expectedDeliveryDate'] ?? '')) === '') {
				$poLines[$index]['expectedDeliveryDate'] = $orderDate;
			}
		}

		return $poLines;
	}//end withOrderDeliveryDate()

	/**
	 * Resolve the most-specific applicable ToleranceProfile.
	 *
	 * @param ThreeWayMatchRun $run The evaluation, with its PO lines.
	 *
	 * @return array<string,mixed>|null
	 */
	private function profileFor(ThreeWayMatchRun $run): ?array {
		$poLines = $run->poLines;
		return $this->toleranceService->getApplicableProfile(
			administrationId: $run->administrationId,
			candidate: [
				'supplierId' => (string)($run->invoice['supplierId'] ?? ''),
				'productCategory' => $this->extractProductCategory(poLines: $poLines),
				'glAccount' => $this->extractGlAccount(poLines: $poLines),
			]
		);
	}//end profileFor()

	/**
	 * Score every line tuple and roll the outcomes up into a match status.
	 *
	 * @param array<int,array<string,mixed>> $tuples  Line tuples from matchLineItems().
	 * @param array<string,mixed>|null       $profile Applicable profile.
	 *
	 * @return array{matchStatus: string, divergences: array<int,array<string,mixed>>}
	 */
	private function scoreTuples(array $tuples, ?array $profile): array {
		$toleranceProfileId = $this->profileIdOf(profile: $profile);
		$divergences = [];
		$outcomes = [];
		foreach ($tuples as $tuple) {
			$deltaSet = $this->calculateDivergence(
				invoiceLine: $tuple['invoiceLine'],
				poLine:      $tuple['poLine'],
				grnLine:     $tuple['grnLine']
			);
			foreach ($deltaSet as $delta) {
				$outcomes[$this->deltaOutcome(delta: $delta, profile: $profile)] = true;
				$delta['toleranceProfileId'] = $toleranceProfileId;
				$divergences[] = $delta;
			}
		}

		return [
			'matchStatus' => $this->rollUpStatus(outcomes: $outcomes),
			'divergences' => $divergences,
		];
	}//end scoreTuples()

	/**
	 * Classify one divergence: a failed price or quantity axis, a delta
	 * within tolerance, or none.
	 *
	 * @param array<string,mixed>      $delta   One divergence.
	 * @param array<string,mixed>|null $profile Applicable profile.
	 *
	 * @return string One of the OUTCOME_STATUS keys, or 'none'.
	 */
	private function deltaOutcome(array $delta, ?array $profile): string {
		$within = $this->evaluateTolerance(divergence: $delta, profile: $profile);
		if ($within === false && isset(self::FAILED_AXIS[$delta['field']]) === true) {
			return self::FAILED_AXIS[$delta['field']];
		}

		if ($this->hasDelta(delta: $delta) === true) {
			return 'delta';
		}

		return 'none';
	}//end deltaOutcome()

	/**
	 * Whether a divergence carries a non-zero cents or percentage delta.
	 *
	 * @param array<string,mixed> $delta One divergence.
	 *
	 * @return bool
	 */
	private function hasDelta(array $delta): bool {
		return ($delta['deltaCents'] ?? 0) !== 0 || ($delta['deltaPercentage'] ?? 0) !== 0;
	}//end hasDelta()

	/**
	 * The match status of the strongest outcome: a line without a PO line,
	 * then a line without a receipt line, then a price failure, then a
	 * quantity failure, then a delivery date failure, then a delta within
	 * tolerance.
	 *
	 * @param array<string,bool> $outcomes The outcomes seen.
	 *
	 * @return string
	 */
	private function rollUpStatus(array $outcomes): string {
		foreach (self::OUTCOME_STATUS as $outcome => $status) {
			if (isset($outcomes[$outcome]) === true) {
				return $status;
			}
		}

		return self::STATUS_AUTO_APPROVED;
	}//end rollUpStatus()

	/**
	 * The id a divergence records for the applied profile.
	 *
	 * @param array<string,mixed>|null $profile Applicable profile.
	 *
	 * @return string|null Null when no profile applied.
	 */
	private function profileIdOf(?array $profile): ?string {
		if ($profile === null) {
			return null;
		}

		return (string)($profile['profileId'] ?? ($profile['id'] ?? ''));
	}//end profileIdOf()

	/**
	 * The id of an OpenRegister row: its `id`, else its `@self.id`.
	 *
	 * @param array<string,mixed> $row The row.
	 *
	 * @return string Empty when the row carries neither.
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
	}//end idOf()

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
	 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
	 */
	public function matchLineItems(array $invoiceLines, array $poLines, array $grnLines): array {
		$tuples = [];
		foreach ($invoiceLines as $invoiceLine) {
			$productCode = trim((string)($invoiceLine['productCode'] ?? ''));
			$lineNumber = (int)($invoiceLine['lineNumber'] ?? 0);

			$poLine = $this->findPoLine(poLines: $poLines, productCode: $productCode, lineNumber: $lineNumber);
			$grnLine = null;
			if ($poLine !== null) {
				$poLineId = $this->idOf(row: $poLine);
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
	 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
	 */
	public function calculateDivergence(array $invoiceLine, ?array $poLine, ?array $grnLine): array {
		if ($poLine === null) {
			return [$this->missingPoLineDelta()];
		}

		$deltas = [
			$this->unitPriceDelta(invoiceLine: $invoiceLine, poLine: $poLine),
			$this->quantityDelta(invoiceLine: $invoiceLine, expected: $this->expectedQuantity(poLine: $poLine, grnLine: $grnLine)),
			$this->vatDelta(invoiceLine: $invoiceLine, poLine: $poLine),
		];

		if ($grnLine === null) {
			$deltas[] = $this->missingGrnLineDelta();
		}

		// Delivery-date delta in days (only meaningful when a GRN is
		// present, since the PO's expected date is what the receipt
		// matched against).
		$dateDelta = $this->deliveryDateDelta(poLine: $poLine, grnLine: $grnLine);
		if ($dateDelta !== null) {
			$deltas[] = $dateDelta;
		}

		return $deltas;
	}//end calculateDivergence()

	/**
	 * The presence marker of an invoice line without a matching PO line.
	 *
	 * @return array<string,mixed>
	 */
	private function missingPoLineDelta(): array {
		return [
			'field' => 'po_line',
			'expected' => 'matched',
			'actual' => 'missing',
			'deltaCents' => null,
			'deltaPercentage' => null,
		];

	}//end missingPoLineDelta()

	/**
	 * The presence marker of an invoice line without an accepted receipt line.
	 *
	 * @return array<string,mixed>
	 */
	private function missingGrnLineDelta(): array {
		return [
			'field' => 'grn_line',
			'expected' => 'received',
			'actual' => 'missing',
			'deltaCents' => null,
			'deltaPercentage' => null,
		];

	}//end missingGrnLineDelta()

	/**
	 * Unit-price delta in cents (integer arithmetic), with its percentage in
	 * basis points of the PO unit price.
	 *
	 * @param array<string,mixed> $invoiceLine Invoice line item.
	 * @param array<string,mixed> $poLine      Matched PO line.
	 *
	 * @return array<string,mixed>
	 */
	private function unitPriceDelta(array $invoiceLine, array $poLine): array {
		$invoiceUnitPriceCents = (int)($invoiceLine['unitPrice'] ?? 0);
		$poUnitPriceCents = (int)($poLine['unitPrice'] ?? 0);
		$unitPriceDelta = $invoiceUnitPriceCents - $poUnitPriceCents;

		return [
			'field' => 'unitPrice',
			'expected' => $poUnitPriceCents,
			'actual' => $invoiceUnitPriceCents,
			'deltaCents' => $unitPriceDelta,
			'deltaPercentage' => $this->basisPointsOf(delta: $unitPriceDelta, base: $poUnitPriceCents),
		];

	}//end unitPriceDelta()

	/**
	 * A signed delta in basis points of the absolute base; 0 for a zero base.
	 *
	 * @param int $delta The signed delta.
	 * @param int $base  The expected value.
	 *
	 * @return int
	 */
	private function basisPointsOf(int $delta, int $base): int {
		if ($base === 0) {
			return 0;
		}

		return (int)round((($delta * 10000) / abs($base)), 0, PHP_ROUND_HALF_UP);

	}//end basisPointsOf()

	/**
	 * The quantity the invoice line is held against, in thousandths: the GRN
	 * accepted (else received) quantity, falling back to the PO ordered
	 * quantity when the GRN line is absent. The missing receipt line is
	 * reported by its own presence marker.
	 *
	 * @param array<string,mixed>      $poLine  Matched PO line.
	 * @param array<string,mixed>|null $grnLine Matched GRN line (or null).
	 *
	 * @return array{quantity:int,source:string}
	 */
	private function expectedQuantity(array $poLine, ?array $grnLine): array {
		if ($grnLine !== null) {
			return [
				'quantity' => $this->thousandths(value: (float)($grnLine['quantityAccepted'] ?? $grnLine['quantityReceived'] ?? 0)),
				'source' => 'grn',
			];
		}

		return [
			'quantity' => $this->thousandths(value: (float)($poLine['quantityOrdered'] ?? 0)),
			'source' => 'po',
		];

	}//end expectedQuantity()

	/**
	 * Quantity delta in thousandths against the expected quantity.
	 *
	 * @param array<string,mixed>                $invoiceLine Invoice line item.
	 * @param array{quantity:int,source:string} $expected    From expectedQuantity().
	 *
	 * @return array<string,mixed>
	 */
	private function quantityDelta(array $invoiceLine, array $expected): array {
		$invoiceQty = $this->thousandths(value: (float)($invoiceLine['quantity'] ?? 0));
		$expectedQty = $expected['quantity'];

		return [
			'field' => 'quantity',
			'expected' => $expectedQty,
			'actual' => $invoiceQty,
			'deltaCents' => null,
			'deltaPercentage' => $this->basisPointsOf(delta: ($invoiceQty - $expectedQty), base: $expectedQty),
			'source' => $expected['source'],
		];

	}//end quantityDelta()

	/**
	 * VAT delta in cents — invoice vatRate × invoice lineExtension vs PO
	 * vatAmount. The invoice schema carries vatRate as a fraction (0.21);
	 * the PO schema carries vatRate as basis points (2100) and vatAmount in
	 * cents. Both are computed in cents to compare.
	 *
	 * @param array<string,mixed> $invoiceLine Invoice line item.
	 * @param array<string,mixed> $poLine      Matched PO line.
	 *
	 * @return array<string,mixed>
	 */
	private function vatDelta(array $invoiceLine, array $poLine): array {
		$invoiceLineExt = (int)($invoiceLine['lineExtension'] ?? 0);
		$invoiceVatRate = (float)($invoiceLine['vatRate'] ?? 0);
		$invoiceVatCents = (int)round(($invoiceLineExt * $invoiceVatRate), 0, PHP_ROUND_HALF_UP);
		$poVatCents = (int)($poLine['vatAmount'] ?? 0);

		return [
			'field' => 'vat',
			'expected' => $poVatCents,
			'actual' => $invoiceVatCents,
			'deltaCents' => $invoiceVatCents - $poVatCents,
			'deltaPercentage' => null,
		];

	}//end vatDelta()

	/**
	 * Delivery-date delta in days, or null without a GRN line or a date.
	 *
	 * GRN lines don't carry a per-line date; the parent GRN receivedAt is
	 * added by the matcher as $grnLine['_receivedAt'] when known. When
	 * absent, the date delta is skipped.
	 *
	 * @param array<string,mixed>      $poLine  Matched PO line.
	 * @param array<string,mixed>|null $grnLine Matched GRN line (or null).
	 *
	 * @return array<string,mixed>|null
	 */
	private function deliveryDateDelta(array $poLine, ?array $grnLine): ?array {
		if ($grnLine === null) {
			return null;
		}

		return $this->datedDelta(
			expectedDate: trim((string)($poLine['expectedDeliveryDate'] ?? '')),
			actualDate: trim((string)($grnLine['_receivedAt'] ?? ''))
		);

	}//end deliveryDateDelta()

	/**
	 * The delivery-date delta entry for two dates, or null when either is empty.
	 *
	 * @param string $expectedDate The PO's expected delivery date.
	 * @param string $actualDate   The GRN's received-at timestamp.
	 *
	 * @return array<string,mixed>|null
	 */
	private function datedDelta(string $expectedDate, string $actualDate): ?array {
		if ($expectedDate === '' || $actualDate === '') {
			return null;
		}

		return [
			'field' => 'deliveryDate',
			'expected' => $expectedDate,
			'actual' => substr($actualDate, 0, 10),
			'deltaCents' => null,
			'deltaPercentage' => null,
			'deltaDays' => $this->dateDeltaDays(expected: $expectedDate, actual: $actualDate),
		];

	}//end datedDelta()

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
	 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
	 */
	public function evaluateTolerance(array $divergence, ?array $profile): bool {
		$field = (string)($divergence['field'] ?? '');
		if (in_array($field, ['unitPrice', 'lineTotal', 'vat'], true) === true) {
			return $this->priceWithinTolerance(divergence: $divergence, profile: $profile);
		}

		if ($field === 'quantity') {
			return $this->quantityWithinTolerance(divergence: $divergence, profile: $profile);
		}

		if ($field === 'deliveryDate') {
			$delta = (int)($divergence['deltaDays'] ?? 0);
			return $this->toleranceService->evaluateDateVariance(deltaDays: $delta, profile: $profile);
		}

		// Presence markers (po_line missing etc.) are never within tolerance.
		return false;

	}//end evaluateTolerance()

	/**
	 * The "more permissive" price rule for a unitPrice, lineTotal or vat entry.
	 *
	 * @param array<string,mixed>      $divergence Divergence entry.
	 * @param array<string,mixed>|null $profile    Applicable ToleranceProfile (or null).
	 *
	 * @return bool
	 */
	private function priceWithinTolerance(array $divergence, ?array $profile): bool {
		$expected = (int)($divergence['expected'] ?? 0);
		$actual = (int)($divergence['actual'] ?? 0);
		return $this->toleranceService->evaluateWithinTolerance(
			expectedCents: $expected,
			actualCents:   $actual,
			profile:       $profile
		);

	}//end priceWithinTolerance()

	/**
	 * The proportional quantity rule for a quantity entry.
	 *
	 * @param array<string,mixed>      $divergence Divergence entry.
	 * @param array<string,mixed>|null $profile    Applicable ToleranceProfile (or null).
	 *
	 * @return bool
	 */
	private function quantityWithinTolerance(array $divergence, ?array $profile): bool {
		$expected = (int)($divergence['expected'] ?? 0);
		$actual = (int)($divergence['actual'] ?? 0);
		return $this->toleranceService->evaluateQuantityVariance(
			expectedThousandths: $expected,
			actualThousandths:   $actual,
			profile:             $profile
		);

	}//end quantityWithinTolerance()

	/**
	 * Write a ThreeWayMatch with the supplied exception status and
	 * transition the invoice to `exception` (REQ-PO3W-004).
	 *
	 * The resolution UI lives in slice 08; this method only routes the
	 * record into the exception queue.
	 *
	 * @param string         $administrationId Administration scope.
	 * @param ExceptionRoute $route            The invoice, exception status, divergences and candidate ids.
	 *
	 * @return array<string,mixed> The persisted ThreeWayMatch record.
	 *
	 * @spec openspec/specs/bookkeeping-three-way-matching-engine/spec.md
	 */
	public function routeToException(string $administrationId, ExceptionRoute $route): array {
		$invoiceId = $this->idOf(row: $route->invoice);
		$match = [
			'invoiceId' => $invoiceId,
			'matchedPoIds' => $route->matchedPoIds,
			'matchedGrnIds' => $route->matchedGrnIds,
			'matchStatus' => $route->matchStatus,
			'divergenceDetails' => $route->divergences,
			'costCenter' => (string)($route->invoice['costCenter'] ?? ''),
			'projectCode' => (string)($route->invoice['projectCode'] ?? ''),
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

		$matchId = $this->idOf(row: $match);
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
		$byCode = $this->poLineWithProductCode(poLines: $poLines, productCode: $productCode);
		if ($byCode !== null) {
			return $byCode;
		}

		return $this->poLineWithNumber(poLines: $poLines, lineNumber: $lineNumber);
	}//end findPoLine()

	/**
	 * The first PO line carrying the product code; none for an empty code.
	 *
	 * @param array<int,array<string,mixed>> $poLines     PO lines.
	 * @param string                         $productCode Invoice-side product code.
	 *
	 * @return array<string,mixed>|null
	 */
	private function poLineWithProductCode(array $poLines, string $productCode): ?array {
		if ($productCode === '') {
			return null;
		}

		foreach ($poLines as $poLine) {
			if ((string)($poLine['productOrServiceCode'] ?? '') === $productCode) {
				return $poLine;
			}
		}

		return null;

	}//end poLineWithProductCode()

	/**
	 * The first PO line with the line number; none for a non-positive number.
	 *
	 * @param array<int,array<string,mixed>> $poLines    PO lines.
	 * @param int                            $lineNumber Invoice-side line number.
	 *
	 * @return array<string,mixed>|null
	 */
	private function poLineWithNumber(array $poLines, int $lineNumber): ?array {
		if ($lineNumber <= 0) {
			return null;
		}

		foreach ($poLines as $poLine) {
			if ((int)($poLine['lineNumber'] ?? 0) === $lineNumber) {
				return $poLine;
			}
		}

		return null;

	}//end poLineWithNumber()

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

		$candidates = $this->grnLinesFor(grnLines: $grnLines, poLineId: $poLineId);
		if ($candidates === []) {
			return null;
		}

		if (count($candidates) === 1) {
			return $candidates[0];
		}

		return $this->summedGrnLine(candidates: $candidates);
	}//end findGrnLine()

	/**
	 * The GRN lines received against a PO line.
	 *
	 * @param array<int,array<string,mixed>> $grnLines GRN lines.
	 * @param string                         $poLineId PO line id to match.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function grnLinesFor(array $grnLines, string $poLineId): array {
		$candidates = [];
		foreach ($grnLines as $grnLine) {
			if ((string)($grnLine['poLineId'] ?? '') === $poLineId) {
				$candidates[] = $grnLine;
			}
		}

		return $candidates;

	}//end grnLinesFor()

	/**
	 * Multi-receipt: the first line with accepted + received summed across
	 * all matching lines, so the engine sees one canonical row.
	 *
	 * @param array<int,array<string,mixed>> $candidates GRN lines of one PO line.
	 *
	 * @return array<string,mixed>
	 */
	private function summedGrnLine(array $candidates): array {
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

	}//end summedGrnLine()

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
