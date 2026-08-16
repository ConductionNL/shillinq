<?php

/**
 * Sales Dispatch Stock Issue Service
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
use OCA\Shillinq\Lifecycle\LotSellabilityGuard;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

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
		$deliveryId = (string)($delivery['id'] ?? ($delivery['@self']['id'] ?? ''));
		$administrationId = trim((string)($delivery['administrationId'] ?? ''));
		$lines = ($delivery['lines'] ?? []);

		if ($deliveryId === '' || $administrationId === '' || is_array($lines) === false) {
			$this->logger->warning(
				'SalesDispatchStockIssueService: issueForDelivery denied — missing id/administrationId/lines',
				['deliveryId' => $deliveryId]
			);
			return [
				'issued' => 0,
				'skipped' => 0,
				'blocked' => 0,
				'moves' => [],
				'blockedLines' => [],
			];
		}

		$issued = 0;
		$skipped = 0;
		$blocked = 0;
		$moves = [];
		$blockedLines = [];

		foreach (array_values($lines) as $index => $line) {
			if (is_array($line) === false) {
				$skipped++;
				continue;
			}

			$sku = trim((string)($line['productReference'] ?? ''));
			$quantity = (float)($line['quantityShipped'] ?? 0);
			if ($sku === '' || $quantity <= 0) {
				$skipped++;
				continue;
			}

			$referenceUri = $this->referenceUri(deliveryId: $deliveryId, lineIndex: $index);

			if ($this->alreadyIssued(referenceUri: $referenceUri, administrationId: $administrationId) === true) {
				// REQ-004 idempotency — already issued for this line.
				$skipped++;
				continue;
			}

			$stockRows = $this->inventoryStockRows(administrationId: $administrationId, sku: $sku);
			if (count($stockRows) === 0) {
				// Not stock-tracked — service line. Not an error.
				$skipped++;
				continue;
			}

			// REQ-BLK-001/002: for a lot-controlled product (≥1 InventoryLot
			// exists for the sku in this administration), refuse to issue when
			// no SELLABLE lot can satisfy the line — quarantined / expired
			// (by status or by date) / exhausted stock MUST NOT be dispatched.
			// Fail CLOSED: the StockMove is never created, so no COGS is
			// posted for unsellable stock. When sellable lots CAN cover the
			// line, quarantined/expired siblings do not block it.
			$productId = $this->productIdFromStock(stockRows: $stockRows);
			$lots = $this->inventoryLotRows(
				administrationId: $administrationId,
				sku: $sku,
				productId: $productId
			);
			if (count($lots) > 0) {
				$verdict = $this->lotGuard->evaluate(
					lots: $lots,
					requiredQuantity: $quantity,
					today: gmdate('Y-m-d')
				);
				if ($verdict['sellable'] === false) {
					$blocked++;
					$offending = $verdict['offendingLots'];
					$named = array_map(
						static fn (array $lot): string => ($lot['lotNumber'] . ' (' . $lot['reason'] . ')'),
						$offending
					);
					$this->logger->error(
						'SalesDispatchStockIssueService: dispatch BLOCKED — no sellable lot can '
						. 'satisfy delivery line; unsellable stock MUST NOT be dispatched.',
						[
							'deliveryId' => $deliveryId,
							'lineIndex' => $index,
							'sku' => $sku,
							'quantityRequired' => $quantity,
							'sellableAvailable' => $verdict['availableSellable'],
							'offendingLots' => $named,
						]
					);
					$blockedLines[] = [
						'lineIndex' => $index,
						'sku' => $sku,
						'quantityRequired' => $quantity,
						'sellableAvailable' => $verdict['availableSellable'],
						'offendingLots' => $offending,
					];
					continue;
				}//end if
			}//end if

			$locationId = $this->resolveLocation(
				line: $line,
				delivery: $delivery,
				stockRows: $stockRows
			);
			if ($locationId === '') {
				$this->logger->warning(
					'SalesDispatchStockIssueService: issue skipped — no resolvable warehouse',
					['deliveryId' => $deliveryId, 'lineIndex' => $index, 'sku' => $sku]
				);
				$skipped++;
				continue;
			}

			$move = $this->saveIssueMove(
				administrationId: $administrationId,
				deliveryId: $deliveryId,
				lineIndex: $index,
				sku: $sku,
				quantity: $quantity,
				locationId: $locationId,
				referenceUri: $referenceUri
			);

			if ($move === null) {
				$skipped++;
				continue;
			}

			$issued++;
			$moves[] = $move;
		}//end foreach

		return [
			'issued' => $issued,
			'skipped' => $skipped,
			'blocked' => $blocked,
			'moves' => $moves,
			'blockedLines' => $blockedLines,
		];

	}//end issueForDelivery()

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
	 * @param string $administrationId Tenant scope.
	 * @param string $deliveryId The Delivery id.
	 * @param int $lineIndex The line's ordinal index in Delivery.lines.
	 * @param string $sku The product SKU (StockMove.itemId).
	 * @param float $quantity Quantity shipped.
	 * @param string $locationId Resolved source warehouse.
	 * @param string $referenceUri The idempotency-carrying reference URI.
	 *
	 * @return array<string,mixed>|null The persisted StockMove, or null on failure.
	 */
	private function saveIssueMove(
		string $administrationId,
		string $deliveryId,
		int $lineIndex,
		string $sku,
		float $quantity,
		string $locationId,
		string $referenceUri,
	): ?array {
		try {
			$now = gmdate('Y-m-d\TH:i:s\Z');
			$movementNumber = sprintf('SM-DLV-%s-%03d', substr(md5($deliveryId), 0, 8), $lineIndex);

			$move = [
				'movementNumber' => $movementNumber,
				'itemId' => $sku,
				'quantity' => $quantity,
				'unitCost' => 0,
				'movementType' => 'issue',
				'sourceLocationId' => $locationId,
				'destinationLocationId' => null,
				'referenceDocumentUri' => $referenceUri,
				'movementReason' => 'normal',
				'notes' => 'Sale dispatch for delivery ' . $deliveryId . ' / line ' . $lineIndex,
				'draftedAt' => $now,
				'postedAt' => null,
				'cancelledAt' => null,
				'administrationId' => $administrationId,
				'locked' => false,
				'glTransactionId' => null,
				'offsetOfMoveId' => null,
				'lifecycleState' => 'draft',
			];

			$objectService = $this->objectService();
			$saved = $this->asArray(
				row: $objectService
					->setRegister($this->register())
					->setSchema('StockMove')
					->saveObject($move)
			);

			$moveId = (string)($saved['id'] ?? ($saved['@self']['id'] ?? ''));
			if ($moveId === '') {
				$this->logger->error(
					'SalesDispatchStockIssueService: draft StockMove save returned no id',
					['deliveryId' => $deliveryId, 'lineIndex' => $lineIndex]
				);
				return null;
			}

			if ($this->transitionStockMove(moveId: $moveId, action: 'post') === true) {
				$refreshed = $objectService
					->setRegister($this->register())
					->setSchema('StockMove')
					->find($moveId);
				return $this->asArray(row: $refreshed);
			}

			// TransitionEngine unavailable — fall back to the pre-existing
			// direct-posted-create convention so behaviour never regresses.
			$move['postedAt'] = $now;
			$move['locked'] = true;
			$move['lifecycleState'] = 'posted';
			$fallback = $objectService
				->setRegister($this->register())
				->setSchema('StockMove')
				->updateObject(id: $moveId, object: $move, register: $this->register(), schema: 'StockMove');

			return $this->asArray(row: $fallback);
		} catch (\Throwable $e) {
			$this->logger->error(
				'SalesDispatchStockIssueService: saveIssueMove failed',
				[
					'deliveryId' => $deliveryId,
					'lineIndex' => $lineIndex,
					'sku' => $sku,
					'exception' => $e->getMessage(),
				]
			);
			return null;
		}//end try

	}//end saveIssueMove()

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
		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
		if ($register === '') {
			return 'shillinq';
		}

		return $register;
	}//end register()
}//end class
