<?php

/**
 * Service Receipt Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Server-authoritative service-entry-sheet (prestatieverklaring) capture for
 * the 3-way-match Purchase Order sub-ledger (REQ-PO3W-011). Implements
 * member 12 of the bookkeeping-purchase-order-3way chain: the SvcReceipt +
 * SvcReceiptLine registers were declared in member 12's register.d
 * fragment; this service drives the receipt lifecycle
 * (draft → confirmed → accepted / rejected) so a service PurchaseOrderLine
 * — which will never have a physical GoodsReceiptNote — has a legitimate
 * third leg for {@see ThreeWayMatchingEngine}.
 *
 * Every read/write goes through the real OpenRegister ObjectService API
 * (find / findAll / saveObject — the methods findObject / createFromArray /
 * deleteFromId do NOT exist and are never used, ADR-022). Every read/write
 * is scoped to the caller's administrationId, validated by
 * AdministrationContextService (ADR-005, ADR-031 IDOR-safe). approver
 * identity is derived from the validated session — it is NEVER trusted
 * from the request body.
 *
 * SvcReceiptLine.quantityAccepted/quantityReceived are derived server-side
 * from one of three confirmation modes (percentageComplete /
 * quantityConfirmed / amountConfirmedCents) so the field names — and
 * therefore ThreeWayMatchingEngine::calculateDivergence() — are identical
 * to GoodsReceiptLine's; the matching engine needs zero receipt-type
 * branching to score a service line.
 *
 * Unlike GoodsReceiptNoteService, this service never posts a StockMove
 * (services never move inventory) and the lifecycle has no
 * quality_checked step (services have nothing to physically inspect).
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
 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Member 12 of bookkeeping-purchase-order-3way: SvcReceipt (prestatieverklaring)
 * lifecycle — the service-PO third leg for the matching engine.
 *
 * Public methods:
 * - createServiceReceipt(): validates the originating PO list + approver
 *   identity, generates a per-administration receipt number, persists the
 *   SvcReceipt with lifecycle state "draft";
 * - addServiceReceiptLine(): appends a SvcReceiptLine — derives
 *   quantityAccepted/quantityReceived from percentageComplete OR
 *   quantityConfirmed OR amountConfirmedCents, cross-validates the PO line
 *   belongs to one of the receipt's poIds;
 * - confirmServiceReceipt(): transitions the receipt to "confirmed";
 * - acceptServiceReceipt(): finalises the receipt, updates the originating
 *   PO(s) lifecycle to partial_received / fully_received (mirrors
 *   GoodsReceiptNoteService::acceptGRN() minus the StockMove posting).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Service touches multiple
 * registers (PurchaseOrder, PurchaseOrderLine, SvcReceipt, SvcReceiptLine);
 * decomposing further would only obscure the service-PO third leg.
 *
 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
 *
 * @SuppressWarnings(PHPMD.CyclomaticComplexity)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * Pre-existing debt (issue #506): inherent branch complexity in this
 * domain logic; deferred pending a dedicated refactor.
 */
class ServiceReceiptService {
	/**
	 * Schema slug for the SvcReceipt register (declared in member 12).
	 *
	 * @var string
	 */
	private const SCHEMA_SVC_RECEIPT = 'SvcReceipt';

	/**
	 * Schema slug for the SvcReceiptLine register (declared in member 12).
	 *
	 * @var string
	 */
	private const SCHEMA_SVC_RECEIPT_LINE = 'SvcReceiptLine';

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
	 * Create a SvcReceipt against one or more PurchaseOrders.
	 *
	 * Server-authoritative:
	 *  - approver is derived from the validated session — never trusted
	 *    from the request body (ADR-005);
	 *  - the receiptNumber is generated server-side (per-administration
	 *    sequence: SVR-{year}-{administrationCode}-{sequence});
	 *  - the supplied po_ids[] are validated for tenant scope; cross-tenant
	 *    refs are masked as 404;
	 *  - lifecycle starts at "draft".
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param array<string,mixed> $payload Caller payload (poIds, periodStart,
	 *                                     periodEnd, notes, costCenter,
	 *                                     projectCode).
	 *
	 * @return array<string,mixed> The persisted SvcReceipt payload.
	 *
	 * @throws \RuntimeException When the caller lacks access, no valid PO is
	 *                           supplied, or a required field is missing.
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
	 */
	public function createServiceReceipt(string $administrationId, array $payload): array {
		$this->assertAccess(administrationId: $administrationId);
		$approver = $this->requireSessionUser();

		$poIds = $this->normalisePoIds(rawIds: ($payload['poIds'] ?? $payload['po_ids'] ?? []));
		if ($poIds === []) {
			throw new RuntimeException('At least one purchase order id is required');
		}

		$this->assertPurchaseOrdersAccessible(administrationId: $administrationId, poIds: $poIds);

		$receiptNumber = $this->generateReceiptNumber(administrationId: $administrationId);

		$periodStart = trim((string)($payload['periodStart'] ?? ''));
		if ($periodStart === '') {
			$periodStart = substr($this->nowIso(), 0, 10);
		}

		$periodEnd = trim((string)($payload['periodEnd'] ?? ''));
		if ($periodEnd === '') {
			$periodEnd = $periodStart;
		}

		$receipt = [
			'receiptNumber' => $receiptNumber,
			'poIds' => $poIds,
			'periodStart' => $periodStart,
			'periodEnd' => $periodEnd,
			'approver' => $approver,
			'notes' => trim((string)($payload['notes'] ?? '')),
			'costCenter' => trim((string)($payload['costCenter'] ?? '')),
			'projectCode' => trim((string)($payload['projectCode'] ?? '')),
			'statusCode' => 'draft',
			'administrationId' => $administrationId,
		];

		return $this->records()->saveObject(schema: self::SCHEMA_SVC_RECEIPT, object: $receipt);
	}//end createServiceReceipt()

	/**
	 * Append a SvcReceiptLine to an existing SvcReceipt.
	 *
	 * Validates that the PO line belongs to one of the receipt's poIds[]
	 * and that exactly one confirmation mode (percentageComplete OR
	 * quantityConfirmed OR amountConfirmedCents) is supplied. Derives
	 * quantityAccepted/quantityReceived from that mode so the matching
	 * engine reads the same field names it already reads off
	 * GoodsReceiptLine.
	 *
	 * @param string $administrationId Administration scope (server-resolved).
	 * @param string $receiptId Parent SvcReceipt id.
	 * @param array<string,mixed> $payload poLineId, percentageComplete,
	 *                                     quantityConfirmed, amountConfirmedCents,
	 *                                     periodStart, periodEnd, notes.
	 *
	 * @return array<string,mixed> The persisted SvcReceiptLine payload.
	 *
	 * @throws \RuntimeException When the receipt or PO line is missing, or
	 *                           validation fails.
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
	 */
	public function addServiceReceiptLine(string $administrationId, string $receiptId, array $payload): array {
		$this->assertAccess(administrationId: $administrationId);
		$approver = $this->requireSessionUser();

		$receipt = $this->records()->findOne(
			schema: self::SCHEMA_SVC_RECEIPT,
			filters: [
				'id' => $receiptId,
				'administrationId' => $administrationId,
			]
		);
		if ($receipt === null) {
			throw new RuntimeException('Service receipt not found');
		}

		$poLineId = trim((string)($payload['poLineId'] ?? $payload['po_line_id'] ?? ''));
		if ($poLineId === '') {
			throw new RuntimeException('poLineId is required');
		}

		$poLine = $this->records()->findOne(
			schema: self::SCHEMA_PO_LINE,
			filters: [
				'id' => $poLineId,
				'administrationId' => $administrationId,
			]
		);
		if ($poLine === null) {
			throw new RuntimeException('Purchase order line not found');
		}

		// Cross-validate: the PO line MUST belong to one of the receipt's po_ids.
		$parentPoId = (string)($poLine['poId'] ?? '');
		$receiptPoIds = $this->stringArray(input: ($receipt['poIds'] ?? []));
		if ($parentPoId === '' || in_array($parentPoId, $receiptPoIds, true) === false) {
			throw new RuntimeException('Purchase order line does not belong to this service receipt');
		}

		$effectiveQuantity = $this->deriveQuantity(payload: $payload, poLine: $poLine);

		$receiptRecordId = (string)($receipt['id'] ?? ($receipt['@self']['id'] ?? $receiptId));

		$line = [
			'serviceReceiptId' => $receiptRecordId,
			'poLineId' => $poLineId,
			'periodStart' => $this->nullableTrim(input: ($payload['periodStart'] ?? null)),
			'periodEnd' => $this->nullableTrim(input: ($payload['periodEnd'] ?? null)),
			'percentageComplete' => $this->nullableInt(input: ($payload['percentageComplete'] ?? null)),
			'quantityConfirmed' => $this->nullableFloat(input: ($payload['quantityConfirmed'] ?? null)),
			'amountConfirmedCents' => $this->nullableInt(input: ($payload['amountConfirmedCents'] ?? null)),
			'quantityReceived' => $effectiveQuantity,
			'quantityAccepted' => $effectiveQuantity,
			'approver' => $approver,
			'confirmedAt' => $this->nowIso(),
			'notes' => $this->nullableTrim(input: ($payload['notes'] ?? null)),
			'administrationId' => $administrationId,
		];

		return $this->records()->saveObject(schema: self::SCHEMA_SVC_RECEIPT_LINE, object: $line);
	}//end addServiceReceiptLine()

	/**
	 * Transition the SvcReceipt to "confirmed" (approver confirms delivery).
	 *
	 * Only callable from the "draft" state; any other source state is a
	 * conflict and surfaces a RuntimeException so the controller maps it to
	 * a 409.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $receiptId SvcReceipt id.
	 *
	 * @return array<string,mixed> The persisted SvcReceipt payload.
	 *
	 * @throws \RuntimeException When the receipt is missing or the source
	 *                           state is not "draft".
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
	 */
	public function confirmServiceReceipt(string $administrationId, string $receiptId): array {
		$this->assertAccess(administrationId: $administrationId);

		$receipt = $this->records()->findOne(
			schema: self::SCHEMA_SVC_RECEIPT,
			filters: [
				'id' => $receiptId,
				'administrationId' => $administrationId,
			]
		);
		if ($receipt === null) {
			throw new RuntimeException('Service receipt not found');
		}

		$statusCode = (string)($receipt['statusCode'] ?? '');
		if ($statusCode !== 'draft') {
			throw new RuntimeException('Confirmation requires statusCode=draft');
		}

		$receipt['statusCode'] = 'confirmed';
		$receipt['confirmedAt'] = $this->nowIso();

		return $this->records()->saveObject(schema: self::SCHEMA_SVC_RECEIPT, object: $receipt);
	}//end confirmServiceReceipt()

	/**
	 * Accept the SvcReceipt and update originating PO(s) lifecycle
	 * (REQ-PO3W-011).
	 *
	 * Side effects:
	 *  - lifecycle transitions to "accepted" (callable only from
	 *    "confirmed" — an unconfirmed receipt cannot feed the matching
	 *    engine);
	 *  - the originating PO(s) lifecycle is recomputed: if every line is
	 *    fully confirmed the PO transitions to "fully_received" otherwise
	 *    to "partial_received" — the same accumulation
	 *    GoodsReceiptNoteService::updatePurchaseOrderReceiptLifecycle()
	 *    already performs for goods.
	 *
	 * Unlike acceptGRN(), this never posts a StockMove — services never
	 * move inventory.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $receiptId SvcReceipt id.
	 *
	 * @return array<string,mixed> The persisted SvcReceipt payload.
	 *
	 * @throws \RuntimeException When the receipt is missing or the source
	 *                           state is not "confirmed".
	 *
	 * @spec openspec/changes/prestatieverklaring-service-receipt/tasks.md
	 */
	public function acceptServiceReceipt(string $administrationId, string $receiptId): array {
		$this->assertAccess(administrationId: $administrationId);

		$receipt = $this->records()->findOne(
			schema: self::SCHEMA_SVC_RECEIPT,
			filters: [
				'id' => $receiptId,
				'administrationId' => $administrationId,
			]
		);
		if ($receipt === null) {
			throw new RuntimeException('Service receipt not found');
		}

		$statusCode = (string)($receipt['statusCode'] ?? '');
		if ($statusCode !== 'confirmed') {
			throw new RuntimeException('Acceptance requires statusCode=confirmed');
		}

		$receipt['statusCode'] = 'accepted';
		$persisted = $this->records()->saveObject(schema: self::SCHEMA_SVC_RECEIPT, object: $receipt);

		$poIds = $this->stringArray(input: ($persisted['poIds'] ?? $receipt['poIds'] ?? []));
		foreach ($poIds as $poId) {
			$this->updatePurchaseOrderReceiptLifecycle(
				administrationId: $administrationId,
				poId: $poId
			);
		}

		return $persisted;
	}//end acceptServiceReceipt()

	/**
	 * Derive the effective quantity confirmed from whichever confirmation
	 * mode the caller supplied (D2 in design.md): quantityConfirmed wins
	 * when set; else percentageComplete × poLine.quantityOrdered; else
	 * amountConfirmedCents ÷ poLine.unitPrice.
	 *
	 * @param array<string,mixed> $payload Caller payload.
	 * @param array<string,mixed> $poLine The matched PurchaseOrderLine.
	 *
	 * @return float
	 *
	 * @throws \RuntimeException When no confirmation mode is supplied.
	 */
	private function deriveQuantity(array $payload, array $poLine): float {
		if (isset($payload['quantityConfirmed']) === true && $payload['quantityConfirmed'] !== '') {
			return $this->normaliseQuantity(value: $payload['quantityConfirmed']);
		}

		$orderedQuantity = (float)($poLine['quantityOrdered'] ?? 0);

		if (isset($payload['percentageComplete']) === true && $payload['percentageComplete'] !== '') {
			$basisPoints = (int)$payload['percentageComplete'];
			return $this->normaliseQuantity(value: (($orderedQuantity * $basisPoints) / 10000.0));
		}

		$amountSupplied = (isset($payload['amountConfirmedCents']) === true
			&& $payload['amountConfirmedCents'] !== '');
		if ($amountSupplied === true) {
			$unitPriceCents = (int)($poLine['unitPrice'] ?? 0);
			if ($unitPriceCents <= 0) {
				throw new RuntimeException('amountConfirmedCents requires the purchase order line to carry a positive unitPrice');
			}

			$amountCents = (int)$payload['amountConfirmedCents'];
			return $this->normaliseQuantity(value: ($amountCents / $unitPriceCents));
		}

		throw new RuntimeException('One of quantityConfirmed, percentageComplete or amountConfirmedCents is required');
	}//end deriveQuantity()

	/**
	 * Update a single PurchaseOrder's lifecycle after a SvcReceipt accept.
	 *
	 * Looks up every PurchaseOrderLine + the cumulative quantityAccepted
	 * from every SvcReceiptLine across every accepted SvcReceipt that
	 * targets this PO; if every line has been fully confirmed the PO
	 * transitions to "fully_received" otherwise "partial_received".
	 * Mirrors GoodsReceiptNoteService::updatePurchaseOrderReceiptLifecycle()
	 * so a mixed goods+service PO converges to the same lifecycle
	 * semantics regardless of which receipt type settled which line.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $poId PurchaseOrder id.
	 *
	 * @return void
	 */
	private function updatePurchaseOrderReceiptLifecycle(string $administrationId, string $poId): void {
		$po = $this->records()->findOne(
			schema: self::SCHEMA_PO,
			filters: [
				'id' => $poId,
				'administrationId' => $administrationId,
			]
		);
		if ($po === null) {
			return;
		}

		$poLines = $this->records()->findAll(
			schema: self::SCHEMA_PO_LINE,
			filters: [
				'poId' => $poId,
				'administrationId' => $administrationId,
			]
		);
		$received = $this->receivedQuantities(
			administrationId: $administrationId,
			acceptedReceiptIds: $this->acceptedReceiptIds(administrationId: $administrationId)
		);
		$lifecycle = $received->lifecycleFor(po: $po, poLines: $poLines);
		if ($lifecycle !== null) {
			$po['statusCode'] = $lifecycle;
			$this->records()->saveObject(schema: self::SCHEMA_PO, object: $po);
		}

	}//end updatePurchaseOrderReceiptLifecycle()

	/**
	 * The ids of the administration's accepted receipts. Only accepted
	 * receipts contribute — a confirmed-but-not-yet-accepted line has not
	 * settled anything yet.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<string,true> Accepted receipt ids as keys.
	 */
	private function acceptedReceiptIds(string $administrationId): array {
		$acceptedReceiptIds = [];
		$receipts = $this->records()->findAll(
			schema: self::SCHEMA_SVC_RECEIPT,
			filters: [
				'administrationId' => $administrationId,
			]
		);
		foreach ($receipts as $receiptRow) {
			$id = (string)($receiptRow['id'] ?? ($receiptRow['@self']['id'] ?? ''));
			if ((string)($receiptRow['statusCode'] ?? '') === 'accepted' && $id !== '') {
				$acceptedReceiptIds[$id] = true;
			}
		}

		return $acceptedReceiptIds;
	}//end acceptedReceiptIds()

	/**
	 * Total accepted per PO-line across every accepted receipt's lines.
	 *
	 * @param string             $administrationId   Administration scope.
	 * @param array<string,true> $acceptedReceiptIds Accepted receipt ids as keys.
	 *
	 * @return ReceivedQuantities The accepted quantities.
	 */
	private function receivedQuantities(string $administrationId, array $acceptedReceiptIds): ReceivedQuantities {
		$received = new ReceivedQuantities();
		$allReceiptLines = $this->records()->findAll(
			schema: self::SCHEMA_SVC_RECEIPT_LINE,
			filters: [
				'administrationId' => $administrationId,
			]
		);
		foreach ($allReceiptLines as $receiptLine) {
			if (isset($acceptedReceiptIds[(string)($receiptLine['serviceReceiptId'] ?? '')]) === true) {
				$received->add(poLineId: (string)($receiptLine['poLineId'] ?? ''), quantityAccepted: ($receiptLine['quantityAccepted'] ?? 0));
			}
		}

		return $received;
	}//end receivedQuantities()

	/**
	 * The record store on the app's register, logging as this service.
	 *
	 * @return SchemaRecordStore The record store.
	 */
	private function records(): SchemaRecordStore {
		return new SchemaRecordStore(
			binding: new RegisterBinding(objectService: $this->objectService, appConfig: $this->appConfig),
			log: new RecordStoreLog(logger: $this->logger, source: 'ServiceReceiptService')
		);
	}//end records()

	/**
	 * Generate a per-administration service-receipt number for the current
	 * year.
	 *
	 * Format: SVR-{year}-{administrationCode}-{6-digit-sequence}.
	 *
	 * @param string $administrationId Administration scope.
	 *
	 * @return string
	 */
	private function generateReceiptNumber(string $administrationId): string {
		$year = (int)date('Y');
		$existing = $this->records()->findAll(
			schema: self::SCHEMA_SVC_RECEIPT,
			filters: ['administrationId' => $administrationId]
		);

		$thisYear = 0;
		foreach ($existing as $row) {
			$receiptNumber = (string)($row['receiptNumber'] ?? '');
			if (str_contains($receiptNumber, '-' . $year . '-') === true) {
				$thisYear++;
			}
		}

		$sequence = str_pad((string)($thisYear + 1), 6, '0', STR_PAD_LEFT);

		return sprintf('SVR-%d-%s-%s', $year, $administrationId, $sequence);
	}//end generateReceiptNumber()

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
			$po = $this->records()->findOne(
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
	 * Trim a nullable string input, returning null instead of ''.
	 *
	 * @param mixed $input Raw input.
	 *
	 * @return string|null
	 */
	private function nullableTrim(mixed $input): ?string {
		if ($input === null) {
			return null;
		}

		$value = trim((string)$input);
		if ($value === '') {
			return null;
		}

		return $value;
	}//end nullableTrim()

	/**
	 * Coerce a nullable int input.
	 *
	 * @param mixed $input Raw input.
	 *
	 * @return int|null
	 */
	private function nullableInt(mixed $input): ?int {
		if ($input === null || $input === '') {
			return null;
		}

		return (int)$input;
	}//end nullableInt()

	/**
	 * Coerce a nullable float input.
	 *
	 * @param mixed $input Raw input.
	 *
	 * @return float|null
	 */
	private function nullableFloat(mixed $input): ?float {
		if ($input === null || $input === '') {
			return null;
		}

		return (float)$input;
	}//end nullableFloat()

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
	 * approver identity.
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
	 * for confirmedAt.
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return date('c');
	}//end nowIso()
}//end class
