<?php

/**
 * Goods Receipt Note Service
 *
 * Server-authoritative GRN capture for the 3-way-match Purchase Order sub-ledger
 * (REQ-GRN-001 / REQ-PO3W-003). Implements member 04 of the
 * bookkeeping-purchase-order-3way chain: the GoodsReceiptNote + GoodsReceiptLine
 * registers were declared in member 01; this service drives the GRN lifecycle
 * (draft → received → quality_checked → accepted / rejected) and posts the
 * inventory-stock-movement-ledger StockMove that credits inventory for the
 * quantity_accepted at the PO-line gl_account.
 *
 * Every read/write goes through the real OpenRegister ObjectService API
 * (find / findAll / saveObject — the methods findObject / createFromArray /
 * deleteFromId do NOT exist and are never used, ADR-022). Every read/write is
 * scoped to the caller's administrationId, validated by
 * AdministrationContextService (ADR-005, ADR-031 IDOR-safe). receivedBy /
 * inspector identities are derived from the validated session — they are NEVER
 * trusted from the request body.
 *
 * Quantities use integer thousandths (multipleOf 0.001 on the GoodsReceiptLine
 * schema fields declared by slice 01) so partial-receipt arithmetic stays
 * bit-exact across the multi-PO allocator.
 *
 * StockMove posting on accept reuses the
 * inventory-stock-movement-ledger StockMoveOffsetCreator pattern: we write a
 * lifecycleState="posted" + locked=true row directly via saveObject, with
 * movementType=receipt, movementReason=normal, and referenceDocumentUri
 * pointing back at the originating PO (shillinq://purchase-order/<poId>). The
 * GL posting on accept is fired declaratively by the OR lifecycle once the
 * GR/IR clearing logic in member 09 lands — this slice only mutates inventory
 * and updates PO status; it never decrements stock for rejected quantities.
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
 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
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
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Member 04 of bookkeeping-purchase-order-3way: GRN lifecycle + inventory
 * credit on accept.
 *
 * Public methods:
 * - createGRN(): validates the originating PO list + receiver identity,
 *   generates a per-administration GRN number, persists the GoodsReceiptNote
 *   with lifecycle state "received";
 * - addGRNLine(): appends a GoodsReceiptLine — quantity_received /
 *   quantity_accepted / quantity_rejected, rejection_reason, batch_ref — and
 *   validates the receipt totals against the PO line's quantityOrdered;
 * - qualityCheckPass(): transitions the GRN to "quality_checked";
 * - acceptGRN(): finalises the GRN, posts a StockMove credit for every
 *   accepted line, updates the originating PO(s) lifecycle to
 *   partial_received / fully_received;
 * - uploadPhotos(): appends docudesk file-id references to the GRN's
 *   `photos` array (delivery-condition evidence per REQ-GRN-001).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Service touches multiple
 * registers (PurchaseOrder, PurchaseOrderLine, GoodsReceiptNote,
 * GoodsReceiptLine, StockMove); decomposing further would only obscure the
 * 3-way-match middle leg.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   GRN lifecycle exposes the
 * five surfaces named above; each one corresponds 1:1 with a slice-04 task.
 *
 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
 *
 * @SuppressWarnings(PHPMD.ElseExpression)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * Pre-existing debt (issue #506): early-return refactor deferred pending
 * full behavioral verification of each branch.
 */
class GoodsReceiptNoteService {
	/**
	 * StockMove movementType for the GRN-accept inventory credit.
	 *
	 * @var string
	 */
	private const STOCK_MOVE_TYPE_RECEIPT = 'receipt';

	/**
	 * StockMove movementReason recorded on the GRN-accept inventory credit.
	 *
	 * The inventory-stock-movement-ledger schema enumerates the allowed reason
	 * codes; "normal" is the canonical seed for an unremarkable supplier
	 * delivery. Rejection details are NOT stored on the StockMove (which is
	 * never written for rejected quantities) — they live on the
	 * GoodsReceiptLine.rejectionReason.
	 *
	 * @var string
	 */
	private const STOCK_MOVE_REASON_NORMAL = 'normal';

	/**
	 * URI scheme prefix for the StockMove.referenceDocumentUri back-pointer.
	 *
	 * @var string
	 */
	private const REFERENCE_DOCUMENT_URI_PREFIX = 'shillinq://purchase-order/';

	/**
	 * Schema slug for the GoodsReceiptNote register (declared in slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_GRN = 'GoodsReceiptNote';

	/**
	 * Schema slug for the GoodsReceiptLine register (declared in slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_GRN_LINE = 'GoodsReceiptLine';

	/**
	 * Schema slug for the PurchaseOrder register (declared in slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_PO = 'PurchaseOrder';

	/**
	 * Schema slug for the PurchaseOrderLine register (declared in slice 01).
	 *
	 * @var string
	 */
	private const SCHEMA_PO_LINE = 'PurchaseOrderLine';

	/**
	 * Schema slug for the StockMove register (declared by
	 * inventory-stock-movement-ledger).
	 *
	 * @var string
	 */
	private const SCHEMA_STOCK_MOVE = 'StockMove';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param AdministrationContextService $administrationContext IDOR + tenant scope.
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
	 * Create a GoodsReceiptNote against one or more PurchaseOrders.
	 *
	 * Server-authoritative:
	 *  - receivedBy is derived from the validated session — never trusted
	 *    from the request body (ADR-005);
	 *  - the grn_number is generated server-side (per-administration
	 *    sequence: GRN-{year}-{administrationCode}-{sequence});
	 *  - the supplied po_ids[] are validated for tenant scope; cross-tenant
	 *    refs are masked as 404;
	 *  - lifecycle starts at "received" (REQ-GRN-002 — the receiver has
	 *    physically signed for the goods).
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param array<string,mixed> $payload Caller payload (po_ids, received_at,
	 *                                     carrier, delivery_note_ref, costCenter,
	 *                                     projectCode).
	 *
	 * @return array<string,mixed> The persisted GoodsReceiptNote payload.
	 *
	 * @throws \RuntimeException When the caller lacks access, no valid PO is
	 *                           supplied, or a required field is missing.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
	 */
	public function createGRN(string $administrationId, array $payload): array {
		$this->assertAccess(administrationId: $administrationId);
		$receivedBy = $this->requireSessionUser();

		$poIds = $this->normalisePoIds(rawIds: ($payload['poIds'] ?? $payload['po_ids'] ?? []));
		if ($poIds === []) {
			throw new RuntimeException('At least one purchase order id is required');
		}

		$this->assertPurchaseOrdersAccessible(administrationId: $administrationId, poIds: $poIds);

		$grnNumber = $this->generateGrnNumber(administrationId: $administrationId);
		$receivedAt = trim((string)($payload['receivedAt'] ?? $payload['received_at'] ?? ''));
		if ($receivedAt === '') {
			$receivedAt = $this->nowIso();
		}

		$grn = [
			'grnNumber' => $grnNumber,
			'poIds' => $poIds,
			'receivedAt' => $receivedAt,
			'receivedBy' => $receivedBy,
			'deliveryNoteReference' => trim((string)($payload['deliveryNoteReference'] ?? $payload['delivery_note_ref'] ?? '')),
			'carrier' => trim((string)($payload['carrier'] ?? '')),
			'lotNumbers' => $this->stringArray(input: ($payload['lotNumbers'] ?? [])),
			'serialNumbers' => $this->stringArray(input: ($payload['serialNumbers'] ?? [])),
			'temperatureLog' => $this->temperatureLog(input: ($payload['temperatureLog'] ?? [])),
			'photos' => $this->stringArray(input: ($payload['photos'] ?? [])),
			'costCenter' => trim((string)($payload['costCenter'] ?? '')),
			'projectCode' => trim((string)($payload['projectCode'] ?? '')),
			'statusCode' => 'received',
			'administrationId' => $administrationId,
		];

		return $this->saveObject(schema: self::SCHEMA_GRN, object: $grn);
	}//end createGRN()

	/**
	 * Append a GoodsReceiptLine to an existing GRN.
	 *
	 * Validates that the PO line belongs to one of the GRN's po_ids[] and
	 * that quantity_received >= quantity_accepted + quantity_rejected (the
	 * GRN ledger cannot accept-or-reject more than was physically received).
	 * rejection_reason is mandatory once quantity_rejected is non-zero per
	 * REQ-PO3W-003.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $grnId Parent GRN id.
	 * @param array<string,mixed> $payload poLineId, quantityReceived,
	 *                                     quantityAccepted, quantityRejected,
	 *                                     rejectionReason, batchReference.
	 *
	 * @return array<string,mixed> The persisted GoodsReceiptLine payload.
	 *
	 * @throws \RuntimeException When the GRN or PO line is missing, or
	 *                           validation fails.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
	 */
	public function addGRNLine(string $administrationId, string $grnId, array $payload): array {
		$this->assertAccess(administrationId: $administrationId);
		$inspector = $this->requireSessionUser();

		$grn = $this->findOne(
			schema: self::SCHEMA_GRN,
			filters: [
				'id' => $grnId,
				'administrationId' => $administrationId,
			]
		);
		if ($grn === null) {
			throw new RuntimeException('Goods receipt note not found');
		}

		$poLineId = trim((string)($payload['poLineId'] ?? $payload['po_line_id'] ?? ''));
		if ($poLineId === '') {
			throw new RuntimeException('poLineId is required');
		}

		$poLine = $this->findOne(
			schema: self::SCHEMA_PO_LINE,
			filters: [
				'id' => $poLineId,
				'administrationId' => $administrationId,
			]
		);
		if ($poLine === null) {
			throw new RuntimeException('Purchase order line not found');
		}

		// Cross-validate: the PO line MUST belong to one of the GRN's po_ids.
		$parentPoId = (string)($poLine['poId'] ?? '');
		$grnPoIds = $this->stringArray(input: ($grn['poIds'] ?? []));
		if ($parentPoId === '' || in_array($parentPoId, $grnPoIds, true) === false) {
			throw new RuntimeException('Purchase order line does not belong to this GRN');
		}

		$quantityReceived = $this->normaliseQuantity(value: ($payload['quantityReceived'] ?? $payload['quantity_received'] ?? 0));
		$quantityAccepted = $this->normaliseQuantity(value: ($payload['quantityAccepted'] ?? $payload['quantity_accepted'] ?? $quantityReceived));
		$quantityRejected = $this->normaliseQuantity(value: ($payload['quantityRejected'] ?? $payload['quantity_rejected'] ?? 0));
		$rejectionReason = trim((string)($payload['rejectionReason'] ?? $payload['rejection_reason'] ?? ''));
		$batchReference = trim((string)($payload['batchReference'] ?? $payload['batch_ref'] ?? ''));

		if ($quantityReceived <= 0.0) {
			throw new RuntimeException('quantityReceived must be positive');
		}

		// Accepted + rejected may NOT exceed received (integer-thousandths
		// arithmetic so rounding noise never creeps in).
		$receivedThousandths = $this->thousandths(value: $quantityReceived);
		$acceptedThousandths = $this->thousandths(value: $quantityAccepted);
		$rejectedThousandths = $this->thousandths(value: $quantityRejected);
		if (($acceptedThousandths + $rejectedThousandths) > $receivedThousandths) {
			throw new RuntimeException('quantityAccepted + quantityRejected may not exceed quantityReceived');
		}

		if ($rejectedThousandths > 0 && $rejectionReason === '') {
			throw new RuntimeException('rejectionReason is required when quantityRejected > 0');
		}

		$grnRecordId = (string)($grn['id'] ?? ($grn['@self']['id'] ?? $grnId));

		if ($rejectedThousandths > 0) {
			$rejectionReasonValue = $rejectionReason;
		} else {
			$rejectionReasonValue = null;
		}

		if ($batchReference !== '') {
			$batchReferenceValue = $batchReference;
		} else {
			$batchReferenceValue = null;
		}

		$line = [
			'grnId' => $grnRecordId,
			'poLineId' => $poLineId,
			'quantityReceived' => $quantityReceived,
			'quantityAccepted' => $quantityAccepted,
			'quantityRejected' => $quantityRejected,
			'rejectionReason' => $rejectionReasonValue,
			'inspector' => $inspector,
			'batchReference' => $batchReferenceValue,
			'administrationId' => $administrationId,
		];

		return $this->saveObject(schema: self::SCHEMA_GRN_LINE, object: $line);
	}//end addGRNLine()

	/**
	 * Transition the GRN to "quality_checked" (REQ-GRN-002).
	 *
	 * Only callable from the "received" state; any other source state is a
	 * conflict and surfaces a RuntimeException so the controller maps it to
	 * a 409.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $grnId GRN id.
	 *
	 * @return array<string,mixed> The persisted GoodsReceiptNote payload.
	 *
	 * @throws \RuntimeException When the GRN is missing or the source state is
	 *                           not "received".
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
	 */
	public function qualityCheckPass(string $administrationId, string $grnId): array {
		$this->assertAccess(administrationId: $administrationId);

		$grn = $this->findOne(
			schema: self::SCHEMA_GRN,
			filters: [
				'id' => $grnId,
				'administrationId' => $administrationId,
			]
		);
		if ($grn === null) {
			throw new RuntimeException('Goods receipt note not found');
		}

		$statusCode = (string)($grn['statusCode'] ?? '');
		if ($statusCode !== 'received') {
			throw new RuntimeException('Quality check requires statusCode=received');
		}

		$grn['statusCode'] = 'quality_checked';
		$grn['qualityCheckPassed'] = true;

		return $this->saveObject(schema: self::SCHEMA_GRN, object: $grn);
	}//end qualityCheckPass()

	/**
	 * Accept the GRN, post StockMove credits, and update originating PO(s)
	 * lifecycle (REQ-PO3W-003 / REQ-GRN-001).
	 *
	 * Side effects:
	 *  - lifecycle transitions to "accepted" (callable from "received" or
	 *    "quality_checked"; the quality-check step is optional per REQ-GRN-002);
	 *  - for every GoodsReceiptLine whose quantity_accepted > 0, a StockMove
	 *    row is persisted with lifecycleState=posted, movementType=receipt,
	 *    movementReason=normal, referenceDocumentUri pointing at the
	 *    originating PO, and unitCost copied from the PO line (so downstream
	 *    valuation in member 09 + inventory-valuation has the data it needs);
	 *  - rejected quantities NEVER produce a StockMove — they are recorded on
	 *    the GoodsReceiptLine.rejectionReason but do not mutate stock;
	 *  - the originating PO(s) lifecycle is recomputed: if every line is
	 *    fully received the PO transitions to "fully_received" otherwise to
	 *    "partial_received".
	 *
	 * The GR/IR clearing GL posting on accept is specified in member 09; this
	 * service merely fires the lifecycle transition so the chain stays atomic
	 * per member.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $grnId GRN id.
	 *
	 * @return array<string,mixed> The persisted GoodsReceiptNote payload.
	 *
	 * @throws \RuntimeException When the GRN is missing or the source state is
	 *                           terminal.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
	 */
	public function acceptGRN(string $administrationId, string $grnId): array {
		$this->assertAccess(administrationId: $administrationId);

		$grn = $this->findOne(
			schema: self::SCHEMA_GRN,
			filters: [
				'id' => $grnId,
				'administrationId' => $administrationId,
			]
		);
		if ($grn === null) {
			throw new RuntimeException('Goods receipt note not found');
		}

		$statusCode = (string)($grn['statusCode'] ?? '');
		if (in_array($statusCode, ['accepted', 'rejected'], true) === true) {
			throw new RuntimeException('Goods receipt note is in a terminal state');
		}

		$grnRecordId = (string)($grn['id'] ?? ($grn['@self']['id'] ?? $grnId));
		$grnLines = $this->findAll(
			schema: self::SCHEMA_GRN_LINE,
			filters: [
				'grnId' => $grnRecordId,
				'administrationId' => $administrationId,
			]
		);

		// Post a StockMove credit per accepted line (rejected quantities never
		// mutate stock per the slice-04 design D3).
		foreach ($grnLines as $line) {
			$accepted = (float)($line['quantityAccepted'] ?? 0);
			if ($accepted <= 0.0) {
				continue;
			}

			$this->postReceiptStockMove(
				administrationId: $administrationId,
				grn: $grn,
				grnLine: $line
			);
		}

		$grn['statusCode'] = 'accepted';
		$persisted = $this->saveObject(schema: self::SCHEMA_GRN, object: $grn);

		// Update originating PO lifecycle: every PO referenced by the GRN
		// (multi-PO supported per REQ-GRN-001) is set to partial_received or
		// fully_received depending on whether every PO-line has now been
		// fully received.
		$poIds = $this->stringArray(input: ($persisted['poIds'] ?? $grn['poIds'] ?? []));
		foreach ($poIds as $poId) {
			$this->updatePurchaseOrderReceiptLifecycle(
				administrationId: $administrationId,
				poId: $poId
			);
		}

		return $persisted;
	}//end acceptGRN()

	/**
	 * Append docudesk file-id references to the GRN's photos[] array.
	 *
	 * Photos are stored in docudesk with the receiving user's context (slice
	 * 04 design — Security/ADR-005); this service only persists the FK array.
	 * Existing photos are preserved.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $grnId GRN id.
	 * @param array<int,string> $photoFileIds File ids returned by docudesk.
	 *
	 * @return array<string,mixed> The persisted GoodsReceiptNote payload.
	 *
	 * @throws \RuntimeException When the GRN is missing.
	 *
	 * @spec openspec/changes/bookkeeping-purchase-order-3way-04-goods-receipt-note/tasks.md
	 */
	public function uploadPhotos(string $administrationId, string $grnId, array $photoFileIds): array {
		$this->assertAccess(administrationId: $administrationId);

		$grn = $this->findOne(
			schema: self::SCHEMA_GRN,
			filters: [
				'id' => $grnId,
				'administrationId' => $administrationId,
			]
		);
		if ($grn === null) {
			throw new RuntimeException('Goods receipt note not found');
		}

		$existing = $this->stringArray(input: ($grn['photos'] ?? []));
		$incoming = $this->stringArray(input: $photoFileIds);
		$merged = array_values(array_unique(array_merge($existing, $incoming)));

		$grn['photos'] = $merged;

		return $this->saveObject(schema: self::SCHEMA_GRN, object: $grn);
	}//end uploadPhotos()

	/**
	 * Persist a posted StockMove credit for one accepted GoodsReceiptLine.
	 *
	 * The StockMove is created in the `posted` state (locked=true) so it
	 * counts immediately against the inventory-stock-movement-ledger
	 * aggregation (REQ-SM-005) — receiving warehouse stock is "credited"
	 * straight away the moment a GRN is accepted. The movementNumber is a
	 * deterministic concatenation of the GRN number + the line id so a
	 * double-accept is rejected by the (administrationId, movementNumber)
	 * unique index on StockMove.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $grn The GRN being accepted.
	 * @param array<string,mixed> $grnLine The GRN line being credited.
	 *
	 * @return void
	 */
	private function postReceiptStockMove(string $administrationId, array $grn, array $grnLine): void {
		$poLineId = trim((string)($grnLine['poLineId'] ?? ''));
		if ($poLineId === '') {
			return;
		}

		$poLine = $this->findOne(
			schema: self::SCHEMA_PO_LINE,
			filters: [
				'id' => $poLineId,
				'administrationId' => $administrationId,
			]
		);
		if ($poLine === null) {
			// Defensive: a missing PO line at this point should not happen —
			// addGRNLine already validated. Skip the StockMove rather than
			// crashing the accept transaction; the caller still sees a logged
			// warning so the divergence surfaces.
			$this->logger->warning(
				'GoodsReceiptNoteService: PO line missing on accept; StockMove skipped',
				[
					'poLineId' => $poLineId,
					'grnId' => ($grn['id'] ?? null),
				]
			);
			return;
		}

		$itemId = (string)($poLine['productOrServiceCode'] ?? '');
		$unitPriceCents = (int)($poLine['unitPrice'] ?? 0);
		$unitCost = ((float)$unitPriceCents) / 100.0;
		$quantity = (float)($grnLine['quantityAccepted'] ?? 0);

		// UnitCost is integer cents on the PO line (ADR-022) — converting back
		// to euro for the StockMove which uses multipleOf 0.01.
		$grnNumber = (string)($grn['grnNumber'] ?? '');
		$grnLineId = (string)($grnLine['id'] ?? ($grnLine['@self']['id'] ?? ''));
		$movementNumber = $this->buildMovementNumber(grnNumber: $grnNumber, grnLineId: $grnLineId);
		$poId = (string)($poLine['poId'] ?? '');
		$referenceUri = self::REFERENCE_DOCUMENT_URI_PREFIX . $poId;
		$destinationCode = trim((string)($grnLine['destinationLocationId'] ?? ($grn['destinationLocationId'] ?? '')));

		if ($destinationCode !== '') {
			$destinationLocationId = $destinationCode;
		} else {
			$destinationLocationId = null;
		}

		$stockMove = [
			'movementNumber' => $movementNumber,
			'itemId' => $itemId,
			'quantity' => $quantity,
			'unitCost' => $unitCost,
			'movementType' => self::STOCK_MOVE_TYPE_RECEIPT,
			'sourceLocationId' => null,
			'destinationLocationId' => $destinationLocationId,
			'referenceDocumentUri' => $referenceUri,
			'movementReason' => self::STOCK_MOVE_REASON_NORMAL,
			'notes' => 'Receipt for ' . $grnNumber . ' / PO line ' . $poLineId,
			'draftedAt' => $this->nowIso(),
			'postedAt' => $this->nowIso(),
			'cancelledAt' => null,
			'administrationId' => $administrationId,
			'locked' => true,
			'glTransactionId' => null,
			'offsetOfMoveId' => null,
			'lifecycleState' => 'posted',
		];

		$this->saveObject(schema: self::SCHEMA_STOCK_MOVE, object: $stockMove);

	}//end postReceiptStockMove()

	/**
	 * Update a single PurchaseOrder's lifecycle after a GRN accept.
	 *
	 * Looks up every PurchaseOrderLine + the cumulative quantityAccepted from
	 * every GoodsReceiptLine across every GRN that targets this PO; if every
	 * line has been fully received the PO transitions to "fully_received"
	 * otherwise "partial_received".
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $poId PurchaseOrder id.
	 *
	 * @return void
	 */
	private function updatePurchaseOrderReceiptLifecycle(string $administrationId, string $poId): void {
		$po = $this->findOne(
			schema: self::SCHEMA_PO,
			filters: [
				'id' => $poId,
				'administrationId' => $administrationId,
			]
		);
		if ($po === null) {
			return;
		}

		$poLines = $this->findAll(
			schema: self::SCHEMA_PO_LINE,
			filters: [
				'poId' => $poId,
				'administrationId' => $administrationId,
			]
		);

		// Total accepted per PO-line across every GRN line that targets it.
		$acceptedByPoLine = [];
		$allGrnLines = $this->findAll(
			schema: self::SCHEMA_GRN_LINE,
			filters: [
				'administrationId' => $administrationId,
			]
		);
		foreach ($allGrnLines as $grnLine) {
			$linePoLineId = (string)($grnLine['poLineId'] ?? '');
			if ($linePoLineId === '') {
				continue;
			}

			$thousandths = $this->thousandths(value: (float)($grnLine['quantityAccepted'] ?? 0));
			$acceptedByPoLine[$linePoLineId] = (($acceptedByPoLine[$linePoLineId] ?? 0) + $thousandths);
		}

		$allFullyReceived = true;
		$anyReceived = false;
		foreach ($poLines as $line) {
			$lineId = (string)($line['id'] ?? ($line['@self']['id'] ?? ''));
			$orderedAmount = $this->thousandths(value: (float)($line['quantityOrdered'] ?? 0));
			$acceptedAmount = (int)($acceptedByPoLine[$lineId] ?? 0);

			if ($acceptedAmount > 0) {
				$anyReceived = true;
			}

			if ($acceptedAmount < $orderedAmount) {
				$allFullyReceived = false;
			}
		}

		if ($poLines === []) {
			// Nothing to update; PO lifecycle stays in its current state.
			return;
		}

		$newLifecycle = $po['lifecycleState'] ?? '';
		if ($allFullyReceived === true) {
			$newLifecycle = 'fully_received';
		} elseif ($anyReceived === true) {
			$newLifecycle = 'partial_received';
		}

		if ($newLifecycle === ($po['lifecycleState'] ?? '')) {
			return;
		}

		$po['lifecycleState'] = $newLifecycle;
		$this->saveObject(schema: self::SCHEMA_PO, object: $po);

	}//end updatePurchaseOrderReceiptLifecycle()

	/**
	 * Build the per-line StockMove movement number for the GRN-accept credit.
	 *
	 * Format: GRN number with the "GRN" prefix replaced by "SM" + the GRN
	 * line id suffix. Deterministic so re-accepting the same GRN line yields
	 * the same movementNumber and is rejected by the (administrationId,
	 * movementNumber) unique index on StockMove.
	 *
	 * @param string $grnNumber GRN number (e.g. GRN-2026-adm-1-000001).
	 * @param string $grnLineId GRN line record id.
	 *
	 * @return string
	 */
	private function buildMovementNumber(string $grnNumber, string $grnLineId): string {
		$base = $grnNumber;
		if (str_starts_with($grnNumber, 'GRN-') === true) {
			$base = 'SM-' . substr($grnNumber, 4);
		}

		$suffix = preg_replace('/[^A-Za-z0-9]/', '', $grnLineId) ?? '';
		if ($suffix === '') {
			return $base;
		}

		return $base . '-' . strtoupper(substr($suffix, 0, 12));
	}//end buildMovementNumber()

	/**
	 * Generate a per-administration GRN number for the current year.
	 *
	 * Format: GRN-{year}-{administrationCode}-{6-digit-sequence}.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return string
	 */
	private function generateGrnNumber(string $administrationId): string {
		$year = (int)date('Y');
		$existing = $this->findAll(
			schema: self::SCHEMA_GRN,
			filters: ['administrationId' => $administrationId]
		);

		$thisYear = 0;
		foreach ($existing as $row) {
			$receivedAt = (string)($row['receivedAt'] ?? '');
			if ($receivedAt !== '' && (int)substr($receivedAt, 0, 4) === $year) {
				$thisYear++;
			}
		}

		$sequence = str_pad((string)($thisYear + 1), 6, '0', STR_PAD_LEFT);

		return sprintf('GRN-%d-%s-%s', $year, $administrationId, $sequence);
	}//end generateGrnNumber()

	/**
	 * Validate the per-PO-id array and trim each entry to a non-empty string.
	 *
	 * @param mixed $rawIds Raw input (array or scalar).
	 *
	 * @return array<int,string>
	 */
	private function normalisePoIds(mixed $rawIds): array {
		if (is_array($rawIds) === false) {
			return [];
		}

		$ids = [];
		foreach ($rawIds as $entry) {
			$id = trim((string)$entry);
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}//end normalisePoIds()

	/**
	 * Verify the caller can see every supplied PurchaseOrder under the
	 * administration scope. Cross-tenant refs are masked as 404.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<int,string> $poIds PO ids to validate.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When any PO id is missing or cross-tenant.
	 */
	private function assertPurchaseOrdersAccessible(string $administrationId, array $poIds): void {
		foreach ($poIds as $poId) {
			$po = $this->findOne(
				schema: self::SCHEMA_PO,
				filters: [
					'id' => $poId,
					'administrationId' => $administrationId,
				]
			);
			if ($po === null) {
				throw new RuntimeException('Purchase order not found');
			}
		}

	}//end assertPurchaseOrdersAccessible()

	/**
	 * Coerce any string-array input to a sanitised string array.
	 *
	 * @param mixed $input Raw input.
	 *
	 * @return array<int,string>
	 */
	private function stringArray(mixed $input): array {
		if (is_array($input) === false) {
			return [];
		}

		$out = [];
		foreach ($input as $entry) {
			$value = trim((string)$entry);
			if ($value !== '') {
				$out[] = $value;
			}
		}

		return $out;
	}//end stringArray()

	/**
	 * Validate + normalise the cold-chain temperature log entries.
	 *
	 * Each entry must carry a recordedAt timestamp + temperatureC reading;
	 * malformed entries are silently dropped (the GRN ledger is best-effort
	 * cold-chain evidence, not a hard guard).
	 *
	 * @param mixed $input Raw input.
	 *
	 * @return array<int,array{recordedAt:string,temperatureC:float}>
	 */
	private function temperatureLog(mixed $input): array {
		if (is_array($input) === false) {
			return [];
		}

		$out = [];
		foreach ($input as $entry) {
			if (is_array($entry) === false) {
				continue;
			}

			$recordedAt = trim((string)($entry['recordedAt'] ?? ''));
			if ($recordedAt === '') {
				continue;
			}

			$out[] = [
				'recordedAt' => $recordedAt,
				'temperatureC' => (float)($entry['temperatureC'] ?? 0),
			];
		}

		return $out;
	}//end temperatureLog()

	/**
	 * Coerce a quantity input to a non-negative float with multipleOf 0.001.
	 *
	 * @param mixed $value Raw input.
	 *
	 * @return float
	 */
	private function normaliseQuantity(mixed $value): float {
		$float = (float)$value;
		if ($float < 0.0) {
			return 0.0;
		}

		// Round to thousandths so multipleOf 0.001 enforcement is exact.
		return (((float)round($float * 1000.0)) / 1000.0);
	}//end normaliseQuantity()

	/**
	 * Convert a quantity float to integer thousandths (multipleOf 0.001) so
	 * the partial-receipt allocator stays bit-exact.
	 *
	 * @param float $value Quantity value.
	 *
	 * @return int Thousandths.
	 */
	private function thousandths(float $value): int {
		return (int)round(($value * 1000.0), 0, PHP_ROUND_HALF_UP);
	}//end thousandths()

	/**
	 * Persist an object via OpenRegister's real ObjectService API
	 * (saveObject).
	 *
	 * @param string $schema OR schema slug.
	 * @param array<string,mixed> $object The object to persist.
	 *
	 * @return array<string,mixed> The persisted record (id stamped by OR).
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
				'GoodsReceiptNoteService: failed to persist object',
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
				'GoodsReceiptNoteService: failed to query OpenRegister',
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
	 * Resolve the OpenRegister register slug from app config (defaults to
	 * "shillinq").
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
	 * Assert the caller can access the requested administration; mask as
	 * not-found per ADR-005.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the administration is not accessible.
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
	 * Resolve the validated session user — the server-authoritative
	 * receivedBy / inspector identity.
	 *
	 * @return string
	 *
	 * @throws \RuntimeException When no session user is available.
	 */
	private function requireSessionUser(): string {
		$userId = (string)$this->administrationContext->currentUserId();
		if ($userId === '') {
			throw new RuntimeException('Authenticated user is required');
		}

		return $userId;
	}//end requireSessionUser()

	/**
	 * Current timestamp in ISO-8601 (Y-m-d\TH:i:sP) — server-authoritative
	 * for receivedAt / postedAt.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return date('c');
	}//end nowIso()
}//end class
