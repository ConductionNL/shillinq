<?php

/**
 * Sales Dispatch Stock Issue Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Change inventory-sales-issue-cogs-trigger: the missing outbound trigger
 * between the sales funnel (bookkeeping-quote-order-invoice's Quote ->
 * SalesOrder -> Delivery -> Invoice model) and the existing
 * stock-movement-ledger + valuation + COGS pipeline
 * (inventory-stock-movement-ledger, inventory-valuation-fifo-avg,
 * inventory-cogs-posting). Two operations:
 *
 *   - issueForDelivery()   — invoked when a Delivery transitions
 *     draft -> confirmed. For every stock-tracked line (an InventoryStock
 *     row exists for (administrationId, sku=line.productReference)),
 *     creates one posted `StockMove` (movementType=issue) exactly as
 *     {@see \OCA\Shillinq\Service\GoodsReceiptNoteService} and
 *     {@see \OCA\Shillinq\Service\CycleCountService} already create
 *     receipt / variance moves — direct-posted creation, no draft step.
 *     That StockMove then feeds the existing
 *     {@see \OCA\Shillinq\Listener\StockMoveTransitionedListener} ->
 *     FifoValuationService / MovingAverageValuationService ->
 *     CogsPosterService pipeline UNMODIFIED. Idempotent: re-processing the
 *     same Delivery does not create a second StockMove for an
 *     already-issued line (checked via referenceDocumentUri).
 *
 *   - reverseForDelivery() — invoked when a Delivery transitions to
 *     cancelled. Finds every StockMove this delivery issued and
 *     transitions each through the EXISTING `StockMove.cancel` transition,
 *     which already materialises the offsetting move + GL reversal
 *     (StockMoveOffsetCreator) — no reversal logic is reimplemented here.
 *
 * Not stock-tracked lines (no InventoryStock row for the SKU in this
 * administration) are treated as service lines and silently skipped —
 * this is the only signal available without a cross-app Product schema
 * read (see design.md).
 *
 * ADR-031 exception path (design.md): fan-out over an inline array field
 * (Delivery.lines), a cross-schema stock-tracked check, and idempotent
 * existence checks are not expressible in the declarative
 * x-openregister-iterate-and-create dialect, which only iterates a
 * queryable source schema. This thin service is the ADR-031 exception,
 * following the same shape as GoodsReceiptNoteService / CycleCountService.
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
 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\Kernel\AppIdentity;
use OCA\Shillinq\Lifecycle\LotSellabilityGuard;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Shillinq\Util\ObjectIdentifier;

/**
 * Wires Delivery confirm/cancel into the existing StockMove issue/cancel
 * pipeline.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ElseExpression)         Pre-existing style debt (issue
 *     #506): early-return refactor deferred pending full behavioral
 *     verification of each branch.
 *
 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
 */
class SalesDispatchStockIssueService {
	/**
	 * URI scheme prefix used on StockMove.referenceDocumentUri to identify
	 * the originating Delivery + line index, and to detect prior issuance
	 * for idempotency (REQ-004).
	 */
	private const REFERENCE_URI_PREFIX = 'shillinq://delivery/';

	/**
	 * Construct the service.
	 *
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param IAppConfig $appConfig App config for the register slug.
	 * @param LoggerInterface $logger Logger for diagnostics; never logs full payloads.
	 * @param LotSellabilityGuard $lotGuard Decides whether a line may be issued from its lots.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly LotSellabilityGuard $lotGuard,
		private readonly ObjectServiceInterface $objectService,
	) {

	}//end __construct()

	/**
	 * Issue one posted StockMove per stock-tracked Delivery line
	 * (REQ-001, REQ-003, REQ-004).
	 *
	 * @param array<string,mixed> $delivery The confirmed Delivery.
	 *
	 * @return array<string,mixed> Result envelope: {issued: int, skipped: int, blocked: int, moves: array, blockedLines: array}.
	 *
	 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
	 * @spec openspec/specs/block-unsellable-stock-dispatch/spec.md
	 */
	public function issueForDelivery(array $delivery): array {
		$run = new DeliveryIssueRun(delivery: $delivery);
		$lines = ($delivery['lines'] ?? []);

		if ($run->deliveryId === '' || $run->administrationId === '' || is_array($lines) === false) {
			$this->logger->warning(
				'SalesDispatchStockIssueService: issueForDelivery denied — missing id/administrationId/lines',
				['deliveryId' => $run->deliveryId]
			);
			return $run->result();
		}

		foreach (array_values($lines) as $index => $line) {
			$this->issueLine(run: $run, line: new DeliveryIssueLine(index: $index, line: $line));
		}

		return $run->result();

	}//end issueForDelivery()

	/**
	 * Issue one Delivery line, counting it as issued, skipped or blocked.
	 *
	 * @param DeliveryIssueRun  $run  The delivery issue run.
	 * @param DeliveryIssueLine $line The line.
	 *
	 * @return void
	 */
	private function issueLine(DeliveryIssueRun $run, DeliveryIssueLine $line): void {
		if ($this->isPendingStockLine(run: $run, line: $line) === false) {
			$run->skipped++;
			return;
		}

		if ($this->isBlockedByLots(run: $run, line: $line) === true) {
			$run->blocked++;
			$this->recordBlockedLine(run: $run, line: $line);
			return;
		}

		$run->countIssue(move: $this->issueLocatedLine(run: $run, line: $line));
	}//end issueLine()

	/**
	 * True when the line ships a stock-tracked sku that has not been issued
	 * yet; keeps the line's reference URI and stock rows.
	 *
	 * A line already issued (REQ-004 idempotency) or without InventoryStock
	 * rows (not stock-tracked — a service line, not an error) is skipped.
	 *
	 * @param DeliveryIssueRun  $run  The delivery issue run.
	 * @param DeliveryIssueLine $line The line.
	 *
	 * @return bool Whether the line is to be issued.
	 */
	private function isPendingStockLine(DeliveryIssueRun $run, DeliveryIssueLine $line): bool {
		if ($line->isShippable() === false) {
			return false;
		}

		$line->referenceUri = $this->referenceUri(deliveryId: $run->deliveryId, lineIndex: $line->index);
		if ($this->alreadyIssued(referenceUri: $line->referenceUri, administrationId: $run->administrationId) === true) {
			return false;
		}

		$line->stockRows = $this->inventoryStockRows(administrationId: $run->administrationId, sku: $line->sku);
		return count($line->stockRows) > 0;
	}//end isPendingStockLine()

	/**
	 * REQ-BLK-001/002: for a lot-controlled product (≥1 InventoryLot exists
	 * for the sku in this administration), refuse to issue when no SELLABLE
	 * lot can satisfy the line — quarantined / expired (by status or by
	 * date) / exhausted stock MUST NOT be dispatched. Fail CLOSED: the
	 * StockMove is never created, so no COGS is posted for unsellable stock.
	 * When sellable lots CAN cover the line, quarantined/expired siblings do
	 * not block it. Keeps the verdict on the line.
	 *
	 * @param DeliveryIssueRun  $run  The delivery issue run.
	 * @param DeliveryIssueLine $line The line.
	 *
	 * @return bool True when the line must not be dispatched.
	 */
	private function isBlockedByLots(DeliveryIssueRun $run, DeliveryIssueLine $line): bool {
		$lots = $this->inventoryLotRows(
			administrationId: $run->administrationId,
			sku: $line->sku,
			productId: $this->productIdFromStock(stockRows: $line->stockRows)
		);
		if (count($lots) === 0) {
			return false;
		}

		$line->verdict = $this->lotGuard->evaluate(
			lots: $lots,
			requiredQuantity: $line->quantity,
			today: gmdate('Y-m-d')
		);
		return $line->verdict['sellable'] === false;
	}//end isBlockedByLots()

	/**
	 * Log a blocked line with its offending lots by name and report it in
	 * the run's blocked lines.
	 *
	 * @param DeliveryIssueRun  $run  The delivery issue run.
	 * @param DeliveryIssueLine $line The blocked line.
	 *
	 * @return void
	 */
	private function recordBlockedLine(DeliveryIssueRun $run, DeliveryIssueLine $line): void {
		$this->logger->error(
			'SalesDispatchStockIssueService: dispatch BLOCKED — no sellable lot can '
			. 'satisfy delivery line; unsellable stock MUST NOT be dispatched.',
			$this->blockedLineLogContext(run: $run, line: $line)
		);
		$run->blockedLines[] = [
			'lineIndex' => $line->index,
			'sku' => $line->sku,
			'quantityRequired' => $line->quantity,
			'sellableAvailable' => $line->verdict['availableSellable'],
			'offendingLots' => $line->verdict['offendingLots'],
		];
	}//end recordBlockedLine()

	/**
	 * The log context of a blocked line, naming each offending lot with its
	 * reason.
	 *
	 * @param DeliveryIssueRun  $run  The delivery issue run.
	 * @param DeliveryIssueLine $line The blocked line.
	 *
	 * @return array<string,mixed> The log context.
	 */
	private function blockedLineLogContext(DeliveryIssueRun $run, DeliveryIssueLine $line): array {
		$named = array_map(
			static fn (array $lot): string => ($lot['lotNumber'] . ' (' . $lot['reason'] . ')'),
			$line->verdict['offendingLots']
		);

		return [
			'deliveryId' => $run->deliveryId,
			'lineIndex' => $line->index,
			'sku' => $line->sku,
			'quantityRequired' => $line->quantity,
			'sellableAvailable' => $line->verdict['availableSellable'],
			'offendingLots' => $named,
		];
	}//end blockedLineLogContext()

	/**
	 * Resolve the line's source warehouse and issue its StockMove; a line
	 * without a resolvable warehouse is skipped with a warning.
	 *
	 * @param DeliveryIssueRun  $run  The delivery issue run.
	 * @param DeliveryIssueLine $line The line.
	 *
	 * @return array<string,mixed>|null The posted StockMove, or null when skipped.
	 */
	private function issueLocatedLine(DeliveryIssueRun $run, DeliveryIssueLine $line): ?array {
		$line->locationId = $this->resolveLocation(
			line: (array)$line->row,
			delivery: $run->delivery,
			stockRows: $line->stockRows
		);
		if ($line->locationId === '') {
			$this->logger->warning(
				'SalesDispatchStockIssueService: issue skipped — no resolvable warehouse',
				['deliveryId' => $run->deliveryId, 'lineIndex' => $line->index, 'sku' => $line->sku]
			);
			return null;
		}

		return $this->saveIssueMove(run: $run, line: $line);
	}//end issueLocatedLine()

	/**
	 * Extract the first non-empty productId from the InventoryStock rows for a
	 * sku — used to link the line to its InventoryLot rows (the lot register
	 * keys on productId; productSku is a transitional alias).
	 *
	 * @param array<int,array<string,mixed>> $stockRows InventoryStock rows for the sku.
	 *
	 * @return string The productId, or '' when none is present.
	 */
	private function productIdFromStock(array $stockRows): string {
		foreach ($stockRows as $row) {
			$productId = trim((string)($row['productId'] ?? ''));
			if ($productId !== '') {
				return $productId;
			}
		}

		return '';
	}//end productIdFromStock()

	/**
	 * Fetch every InventoryLot row for the line's product in this
	 * administration. Matches by productId (canonical FK) when known, and
	 * additionally by the transitional productSku alias, merged and
	 * de-duplicated by id — so a lot-controlled product is detected whichever
	 * key its lots carry. Presence of ≥1 row marks the product lot-controlled.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $sku Product SKU (Delivery line productReference).
	 * @param string $productId Canonical product FK, or '' when unknown.
	 *
	 * @return array<int,array<string,mixed>> Matching InventoryLot rows.
	 */
	private function inventoryLotRows(string $administrationId, string $sku, string $productId): array {
		$merged = [];
		$seen = [];

		$collect = function (array $filters) use (&$merged, &$seen): void {
			try {
				$rows = $this->objectService()
					->setRegister($this->register())
					->setSchema('InventoryLot')
					->findAll(['filters' => $filters]);
			} catch (\Throwable $e) {
				$this->logger->debug(
					'SalesDispatchStockIssueService: inventoryLotRows lookup failed',
					['exception' => $e->getMessage()]
				);
				return;
			}

			if (is_array($rows) === false) {
				return;
			}

			foreach ($rows as $row) {
				$rowArray = $this->asArray(row: $row);
				$id = (string)($rowArray['id'] ?? ($rowArray['@self']['id'] ?? ''));
				$key = 'lot-' . count($seen);
				if ($id !== '') {
					$key = $id;
				}

				if (isset($seen[$key]) === true) {
					continue;
				}

				$seen[$key] = true;
				$merged[] = $rowArray;
			}
		};

		if ($productId !== '') {
			$collect(['administrationId' => $administrationId, 'productId' => $productId]);
		}

		$collect(['administrationId' => $administrationId, 'productSku' => $sku]);

		return $merged;
	}//end inventoryLotRows()

	/**
	 * Reverse every StockMove this Delivery issued via the existing
	 * StockMove.cancel transition (REQ-006). Fail-soft per line: a failure
	 * on one move is logged and does not block reversing the others.
	 *
	 * @param array<string,mixed> $delivery The cancelled Delivery.
	 *
	 * @return array<string,mixed> Result envelope: {reversed: int, failed: int}.
	 *
	 * @spec openspec/specs/inventory-sales-issue-cogs-trigger/spec.md
	 */
	public function reverseForDelivery(array $delivery): array {
		$deliveryId = (string)($delivery['id'] ?? ($delivery['@self']['id'] ?? ''));
		$administrationId = trim((string)($delivery['administrationId'] ?? ''));

		if ($deliveryId === '' || $administrationId === '') {
			return [
				'reversed' => 0,
				'failed' => 0,
			];
		}

		$moves = $this->issuedMovesForDelivery(deliveryId: $deliveryId, administrationId: $administrationId);

		$reversed = 0;
		$failed = 0;
		foreach ($moves as $move) {
			$moveId = (string)($move['id'] ?? ($move['@self']['id'] ?? ''));
			if ($moveId === '' || (string)($move['lifecycleState'] ?? '') !== 'posted') {
				// Already cancelled/offset, or malformed — nothing to do.
				continue;
			}

			if ($this->transitionStockMove(moveId: $moveId, action: 'cancel') === true) {
				$reversed++;
			} else {
				$failed++;
			}
		}//end foreach

		return [
			'reversed' => $reversed,
			'failed' => $failed,
		];

	}//end reverseForDelivery()

	/**
	 * Drive a named StockMove transition through OpenRegister's
	 * TransitionEngine — the same engine the schema's own `post` transition
	 * runs through, so the existing declarative offset + GL-reversal
	 * machinery on `StockMove.cancel` fires unmodified (REQ-006). Mirrors
	 * openbuild's ExportJobService::transitionJob() version-gate: never
	 * falls back to a direct field write, which would bypass the
	 * declarative contract; logs and reports failure instead.
	 *
	 * @param string $moveId The StockMove id.
	 * @param string $action The transition action name.
	 *
	 * @return bool True when the transition fired.
	 */
	private function transitionStockMove(string $moveId, string $action): bool {
		$engineClass = 'OCA\\OpenRegister\\Service\\Lifecycle\\TransitionEngine';

		if ($this->container->has($engineClass) === false) {
			$this->logger->warning(
				'SalesDispatchStockIssueService: OR TransitionEngine unavailable — '
				. 'StockMove transition "' . $action . '" SKIPPED.',
				['stockMoveId' => $moveId]
			);
			return false;
		}

		try {
			$engine = $this->container->get($engineClass);
			if (method_exists($engine, 'transition') === false) {
				$this->logger->warning(
					'SalesDispatchStockIssueService: OR TransitionEngine present but transition() missing.',
					['stockMoveId' => $moveId]
				);
				return false;
			}

			$engine->transition($moveId, $action);
			return true;
		} catch (\Throwable $e) {
			$this->logger->error(
				'SalesDispatchStockIssueService: StockMove transition failed',
				['stockMoveId' => $moveId, 'action' => $action, 'exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end transitionStockMove()

	/**
	 * Resolve the source warehouse for a line per REQ-003: line override,
	 * then Delivery header, then the InventoryStock row with the largest
	 * available quantity.
	 *
	 * @param array<string,mixed> $line The delivery line.
	 * @param array<string,mixed> $delivery The delivery header.
	 * @param array<int,array<string,mixed>> $stockRows Candidate InventoryStock rows for the sku.
	 *
	 * @return string Resolved locationId, or '' when unresolvable.
	 */
	private function resolveLocation(array $line, array $delivery, array $stockRows): string {
		$lineLocation = trim((string)($line['sourceLocationId'] ?? ''));
		if ($lineLocation !== '') {
			return $lineLocation;
		}

		$headerLocation = trim((string)($delivery['sourceLocationId'] ?? ''));
		if ($headerLocation !== '') {
			return $headerLocation;
		}

		$bestLocation = '';
		$bestAvailable = -1.0;
		foreach ($stockRows as $row) {
			$available = ((float)($row['quantity'] ?? 0) - (float)($row['reservedQuantity'] ?? 0));
			if ($available > $bestAvailable) {
				$bestAvailable = $available;
				$bestLocation = (string)($row['locationId'] ?? '');
			}
		}

		return $bestLocation;
	}//end resolveLocation()

	/**
	 * Create a `draft` `issue` StockMove for one delivery line, then drive
	 * it to `posted` through OR's TransitionEngine (REQ-001) — the same
	 * engine that dispatches `ObjectTransitionedEvent` and runs the
	 * schema's declarative `post` actions (commit-stock-reservation,
	 * materialise-gl-transaction). Create-then-transition (rather than
	 * creating directly at `posted`, the convention
	 * {@see \OCA\Shillinq\Service\GoodsReceiptNoteService} and
	 * {@see \OCA\Shillinq\Service\CycleCountService} use) guarantees the
	 * transition actually fires regardless of whether a direct
	 * non-initial-state create is itself detected as a transition by the
	 * installed OR build; if TransitionEngine is unavailable, this falls
	 * back to that same direct-posted-create convention so behaviour never
	 * regresses below the pre-existing baseline.
	 *
	 * @param DeliveryIssueRun  $run  The delivery issue run.
	 * @param DeliveryIssueLine $line The located line.
	 *
	 * @return array<string,mixed>|null The persisted StockMove, or null on failure.
	 */
	private function saveIssueMove(DeliveryIssueRun $run, DeliveryIssueLine $line): ?array {
		try {
			return $this->draftAndPostIssueMove(run: $run, line: $line);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SalesDispatchStockIssueService: saveIssueMove failed',
				[
					'deliveryId' => $run->deliveryId,
					'lineIndex' => $line->index,
					'sku' => $line->sku,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

	}//end saveIssueMove()

	/**
	 * Save the draft issue StockMove and drive it to posted.
	 *
	 * @param DeliveryIssueRun  $run  The delivery issue run.
	 * @param DeliveryIssueLine $line The located line.
	 *
	 * @return array<string,mixed>|null The persisted StockMove, or null when the draft has no id.
	 */
	private function draftAndPostIssueMove(DeliveryIssueRun $run, DeliveryIssueLine $line): ?array {
		$line->draftedAt = gmdate('Y-m-d\TH:i:s\Z');
		$move = $this->draftIssueMove(run: $run, line: $line);

		$saved = $this->saveDraftMove(move: $move);
		$moveId = (string)($saved['id'] ?? ($saved['@self']['id'] ?? ''));
		if ($moveId === '') {
			$this->logger->error(
				'SalesDispatchStockIssueService: draft StockMove save returned no id',
				['deliveryId' => $run->deliveryId, 'lineIndex' => $line->index]
			);
			return null;
		}

		return $this->postIssueMove(move: $move, moveId: $moveId);
	}//end draftAndPostIssueMove()

	/**
	 * Save a draft StockMove and read it back as an array.
	 *
	 * @param array<string,mixed> $move The draft StockMove payload.
	 *
	 * @return array<string,mixed> The saved StockMove.
	 */
	private function saveDraftMove(array $move): array {
		return $this->asArray(
			row: $this->objectService()
				->setRegister($this->register())
				->setSchema('StockMove')
				->saveObject($move)
		);
	}//end saveDraftMove()

	/**
	 * Drive a saved draft to posted through the TransitionEngine and read it
	 * back, or — TransitionEngine unavailable — fall back to the pre-existing
	 * direct-posted-create convention so behaviour never regresses.
	 *
	 * @param array<string,mixed> $move   The draft StockMove payload.
	 * @param string              $moveId The saved draft's id.
	 *
	 * @return array<string,mixed> The posted StockMove.
	 */
	private function postIssueMove(array $move, string $moveId): array {
		$objectService = $this->objectService();
		if ($this->transitionStockMove(moveId: $moveId, action: 'post') === true) {
			$refreshed = ObjectIdentifier::findScoped(
				scoped: $objectService->setRegister($this->register())->setSchema('StockMove'),
				id: $moveId
			);
			return $this->asArray(row: $refreshed);
		}

		$move['postedAt'] = $move['draftedAt'];
		$move['locked'] = true;
		$move['lifecycleState'] = 'posted';
		$fallback = $objectService
			->setRegister($this->register())
			->setSchema('StockMove')
			->updateObject(id: $moveId, object: $move, register: $this->register(), schema: 'StockMove');

		return $this->asArray(row: $fallback);
	}//end postIssueMove()

	/**
	 * The draft `issue` StockMove payload for a located line.
	 *
	 * @param DeliveryIssueRun  $run  The delivery issue run.
	 * @param DeliveryIssueLine $line The located line.
	 *
	 * @return array<string,mixed> The draft StockMove.
	 */
	private function draftIssueMove(DeliveryIssueRun $run, DeliveryIssueLine $line): array {
		return [
			'movementNumber' => sprintf('SM-DLV-%s-%03d', substr(md5($run->deliveryId), 0, 8), $line->index),
			'itemId' => $line->sku,
			'quantity' => $line->quantity,
			'unitCost' => 0,
			'movementType' => 'issue',
			'sourceLocationId' => $line->locationId,
			'destinationLocationId' => null,
			'referenceDocumentUri' => $line->referenceUri,
			'movementReason' => 'normal',
			'notes' => 'Sale dispatch for delivery ' . $run->deliveryId . ' / line ' . $line->index,
			'draftedAt' => $line->draftedAt,
			'postedAt' => null,
			'cancelledAt' => null,
			'administrationId' => $run->administrationId,
			'locked' => false,
			'glTransactionId' => null,
			'offsetOfMoveId' => null,
			'lifecycleState' => 'draft',
		];
	}//end draftIssueMove()

	/**
	 * Build the idempotency-carrying reference URI for a delivery line.
	 *
	 * @param string $deliveryId The Delivery id.
	 * @param int $lineIndex The line's ordinal index.
	 *
	 * @return string The reference URI.
	 */
	private function referenceUri(string $deliveryId, int $lineIndex): string {
		return self::REFERENCE_URI_PREFIX . $deliveryId . '#line-' . $lineIndex;
	}//end referenceUri()

	/**
	 * Returns true iff a non-cancelled StockMove already carries this
	 * referenceDocumentUri (REQ-004 idempotency).
	 *
	 * @param string $referenceUri The reference URI to check.
	 * @param string $administrationId Tenant scope.
	 *
	 * @return bool True when already issued.
	 */
	private function alreadyIssued(string $referenceUri, string $administrationId): bool {
		try {
			$rows = $this->objectService()
				->setRegister($this->register())
				->setSchema('StockMove')
				->findAll(
					[
						'filters' => [
							'referenceDocumentUri' => $referenceUri,
							'administrationId' => $administrationId,
						],
					]
				);

			return is_array($rows) === true && count($rows) > 0;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'SalesDispatchStockIssueService: alreadyIssued check failed; proceeding',
				['exception' => $e->getMessage()]
			);
			return false;
		}//end try

	}//end alreadyIssued()

	/**
	 * Fetch every StockMove issued by this delivery (by referenceDocumentUri
	 * prefix match, emulated via findAll + in-PHP filter since the ObjectService
	 * filter API matches exact values only).
	 *
	 * @param string $deliveryId The Delivery id.
	 * @param string $administrationId Tenant scope.
	 *
	 * @return array<int,array<string,mixed>> Matching StockMove rows.
	 */
	private function issuedMovesForDelivery(string $deliveryId, string $administrationId): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->register())
				->setSchema('StockMove')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'movementType' => 'issue',
						],
					]
				);

			if (is_array($rows) === false) {
				return [];
			}

			$prefix = self::REFERENCE_URI_PREFIX . $deliveryId . '#';
			$result = [];
			foreach ($rows as $row) {
				$rowArray = $this->asArray(row: $row);
				if (str_starts_with((string)($rowArray['referenceDocumentUri'] ?? ''), $prefix) === true) {
					$result[] = $rowArray;
				}
			}

			return $result;
		} catch (\Throwable $e) {
			$this->logger->error(
				'SalesDispatchStockIssueService: issuedMovesForDelivery failed',
				['deliveryId' => $deliveryId, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end issuedMovesForDelivery()

	/**
	 * Fetch every InventoryStock row for (administrationId, sku) — presence
	 * of any row is this service's stock-tracked signal.
	 *
	 * @param string $administrationId Tenant scope.
	 * @param string $sku Product SKU.
	 *
	 * @return array<int,array<string,mixed>> Matching InventoryStock rows.
	 */
	private function inventoryStockRows(string $administrationId, string $sku): array {
		try {
			$rows = $this->objectService()
				->setRegister($this->register())
				->setSchema('InventoryStock')
				->findAll(
					[
						'filters' => [
							'administrationId' => $administrationId,
							'sku' => $sku,
						],
					]
				);

			if (is_array($rows) === false) {
				return [];
			}

			return array_map(fn (mixed $row): array => $this->asArray(row: $row), $rows);
		} catch (\Throwable $e) {
			$this->logger->debug(
				'SalesDispatchStockIssueService: inventoryStockRows lookup failed',
				['sku' => $sku, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end inventoryStockRows()

	/**
	 * Normalise an ObjectService row (array or entity-like object) to a plain array.
	 *
	 * @param mixed $row Raw row from ObjectService.
	 *
	 * @return array<string,mixed>
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$out = $row->jsonSerialize();
			if (is_array($out) === true) {
				return $out;
			}
		}

		if (is_object($row) === true && method_exists($row, 'getObject') === true) {
			$out = $row->getObject();
			if (is_array($out) === true) {
				return $out;
			}
		}

		return [];
	}//end asArray()

	/**
	 * Lazily resolve the OpenRegister ObjectService from the container.
	 *
	 * @return object
	 */
	private function objectService(): object {
		return $this->objectService;
	}//end objectService()

	/**
	 * Resolve the OR register slug, defaulting to 'shillinq'.
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
}//end class
