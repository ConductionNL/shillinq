<?php

/**
 * Inventory Scan Service
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Server-authoritative business logic for the mobile-scanner warehouse
 * operations (receive, transfer, pick, count) per the inventory-mobile-scanner
 * change. All stock mutations happen here, against the OpenRegister
 * InventoryStock ledger, never on the client. The client uploads
 * MobileScanOperation records (with a client-generated transactionId); this
 * service:
 *   - deduplicates on transactionId so a retried/duplicated upload is applied
 *     at most once (REQ-SYNC-001/002, idempotency);
 *   - mutates InventoryStock for the (sku, location) pair, clamping at zero and
 *     never persisting a negative quantity (REQ-OFFLINE-001 invariant);
 *   - writes the matching audit record (GoodsReceipt / InventoryTransfer /
 *     InventoryCount) (REQ-PERM-002);
 *   - resolves a barcode to an InventoryItem SKU (REQ-SKU-001);
 *   - returns the delta of InventoryStock records changed since a timestamp for
 *     last-write-wins client sync (REQ-OFFLINE-002/003).
 *
 * Uses only the real OpenRegister ObjectService API (find/findAll/saveObject)
 * per ADR-022.
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
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\AppIdentity;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Server-authoritative inventory operations for the mobile scanner.
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class InventoryScanService {
	/**
	 * Construct the service with DI dependencies.
	 *
	 * @param IAppConfig $appConfig App config for register slug resolution.
	 * @param LoggerInterface $logger Logger.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service, injected per ADR-083.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Return the configured register slug, falling back to 'shillinq'.
	 *
	 * @return string
	 *
	 * @spec exclude Configuration plumbing — resolves the OR register slug from
	 * app config; no observable business behaviour of its own.
	 */
	public function getRegisterSlug(): string {
		$slug = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', 'shillinq');
		if ($slug === '') {
			return 'shillinq';
		}

		return $slug;
	}//end getRegisterSlug()

	/**
	 * Resolve a barcode (or raw SKU) to its InventoryItem (REQ-SKU-001).
	 *
	 * Tries an exact barcode match first, then falls back to an exact SKU match
	 * so the same endpoint serves both scanned barcodes and manually-entered SKUs
	 * (REQ-BARCODE-002 manual fallback).
	 *
	 * @param string $barcode The scanned barcode or typed SKU/barcode value.
	 * @param string $administrationId Administration scope for the lookup.
	 *
	 * @return array<string, mixed>|null The matching InventoryItem, or null when not found.
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T2.4
	 */
	public function resolveBarcode(string $barcode, string $administrationId): ?array {
		$barcode = trim($barcode);
		if ($barcode === '') {
			return null;
		}

		$byBarcode = $this->findOne(
			schema: 'InventoryItem',
			filters: $this->scopeFilters(filters: ['barcode' => $barcode], administrationId: $administrationId)
		);
		if ($byBarcode !== null) {
			return $byBarcode;
		}

		return $this->findOne(
			schema: 'InventoryItem',
			filters: $this->scopeFilters(filters: ['sku' => $barcode], administrationId: $administrationId)
		);

	}//end resolveBarcode()

	/**
	 * Return InventoryStock records modified since the given ISO-8601 timestamp,
	 * for client delta sync (REQ-OFFLINE-002). When no timestamp is supplied, all
	 * stock in scope is returned (full initial sync).
	 *
	 * @param string|null $since ISO-8601 UTC timestamp, or null for a full sync.
	 * @param string $administrationId Administration scope.
	 *
	 * @return array<int, array<string, mixed>> Stock records modified since the cut-off.
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.3
	 */
	public function getStockDelta(?string $since, string $administrationId): array {
		$records = $this->findMany(
			schema: 'InventoryStock',
			filters: $this->scopeFilters(filters: [], administrationId: $administrationId)
		);

		if ($since === null || trim($since) === '') {
			return $records;
		}

		$cutoff = $this->parseTimestamp(value: $since);
		if ($cutoff === null) {
			return $records;
		}

		$delta = [];
		foreach ($records as $record) {
			$modified = $this->parseTimestamp(value: (string)($record['lastModified'] ?? ''));
			if ($modified === null || $modified >= $cutoff) {
				$delta[] = $record;
			}
		}

		return $delta;
	}//end getStockDelta()

	/**
	 * Apply one validated, already-authorised mobile-scan operation
	 * server-side and return the outcome.
	 *
	 * Idempotent: if an operation with the same transactionId was already
	 * applied, the prior outcome is returned without re-mutating stock
	 * (REQ-SYNC-001).
	 *
	 * @param array<string, mixed> $operation The operation payload (type,
	 *                                        sku, location, toLocation,
	 *                                        quantity, transactionId).
	 * @param string $userId The acting Nextcloud user id (IDOR scope).
	 * @param string $administrationId Administration scope.
	 *
	 * @return array{status: string, transactionId: string, resultingQuantity?: float, variance?: float, syncedAt: string, message?: string}
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
	 */
	public function applyOperation(array $operation, string $userId, string $administrationId): array {
		$scan = new ScanOperation(
			payload: $operation,
			userId: $userId,
			administrationId: $administrationId,
			now: gmdate('Y-m-d\TH:i:s\Z')
		);

		// REQ-SYNC-001 — deduplicate on transactionId. A repeat upload returns the
		// recorded outcome without applying the mutation twice.
		$existing = $this->findRecordedOperation(scan: $scan);
		if ($existing !== null) {
			return $this->duplicateOutcome(scan: $scan, existing: $existing);
		}

		return $this->applyAndRecord(scan: $scan);
	}//end applyOperation()

	/**
	 * Find the MobileScanOperation already recorded for the operation's transactionId.
	 *
	 * @param ScanOperation $scan The operation.
	 *
	 * @return array<string, mixed>|null The recorded operation, or null.
	 */
	private function findRecordedOperation(ScanOperation $scan): ?array {
		return $this->findOne(
			schema: 'MobileScanOperation',
			filters: $this->scopeFilters(filters: ['transactionId' => $scan->transactionId()], administrationId: $scan->administrationId)
		);
	}//end findRecordedOperation()

	/**
	 * The outcome of a repeat upload: the recorded sync time, nothing applied.
	 *
	 * @param ScanOperation $scan The operation.
	 * @param array<string, mixed> $existing The recorded MobileScanOperation.
	 *
	 * @return array{status: string, transactionId: string, syncedAt: string, message: string}
	 */
	private function duplicateOutcome(ScanOperation $scan, array $existing): array {
		return [
			'status' => 'duplicate',
			'transactionId' => $scan->transactionId(),
			'syncedAt' => (string)($existing['syncedAt'] ?? $scan->now),
			'message' => 'Operation already processed (idempotent).',
		];
	}//end duplicateOutcome()

	/**
	 * Apply the operation, record its audit row and stamp the outcome.
	 *
	 * @param ScanOperation $scan The operation.
	 *
	 * @return array<string, mixed> The outcome with transactionId and syncedAt.
	 */
	private function applyAndRecord(ScanOperation $scan): array {
		$result = $this->applyByType(scan: $scan);
		$this->recordOperation(scan: $scan, outcome: $result);

		$result['transactionId'] = $scan->transactionId();
		$result['syncedAt'] = $scan->now;
		return $result;
	}//end applyAndRecord()

	/**
	 * Apply the operation by its type; an unknown type is rejected.
	 *
	 * @param ScanOperation $scan The operation.
	 *
	 * @return array<string, mixed> The outcome (status plus resultingQuantity, variance or message).
	 */
	private function applyByType(ScanOperation $scan): array {
		return match ($scan->type()) {
			'receive' => $this->applyReceive(scan: $scan),
			'transfer' => $this->applyTransfer(scan: $scan),
			'pick' => $this->applyPick(scan: $scan),
			'count' => $this->applyCount(scan: $scan),
			default => ['status' => 'rejected', 'message' => 'Unknown operation type.'],
		};
	}//end applyByType()

	/**
	 * Apply a receive: increment stock and write a GoodsReceipt (REQ-INVENTORY-001).
	 *
	 * @param ScanOperation $scan The operation.
	 *
	 * @return array{status: string, resultingQuantity: float}
	 */
	private function applyReceive(ScanOperation $scan): array {
		$resulting = $this->mutateStock(scan: $scan, mutation: new StockMutation(location: $scan->location(), delta: $scan->quantity()));
		$this->saveObject(schema: 'GoodsReceipt', object: $this->goodsReceiptRecord(scan: $scan, resulting: $resulting));

		return ['status' => 'applied', 'resultingQuantity' => $resulting];
	}//end applyReceive()

	/**
	 * Build the GoodsReceipt row of a receive.
	 *
	 * @param ScanOperation $scan The operation.
	 * @param float $resulting The resulting on-hand quantity.
	 *
	 * @return array<string, mixed>
	 */
	private function goodsReceiptRecord(ScanOperation $scan, float $resulting): array {
		return [
			'administrationId' => $scan->administrationId,
			'sku' => $scan->sku(),
			'location' => $scan->location(),
			'quantity' => $scan->quantity(),
			'resultingQuantity' => $resulting,
			'receivedBy' => $scan->userId,
			'receivedAt' => $scan->now,
			'transactionId' => $scan->transactionId(),
		];
	}//end goodsReceiptRecord()

	/**
	 * Apply a transfer: decrement source, increment destination, write an
	 * InventoryTransfer (REQ-INVENTORY-002).
	 *
	 * @param ScanOperation $scan The operation.
	 *
	 * @return array{status: string, resultingQuantity: float}
	 */
	private function applyTransfer(ScanOperation $scan): array {
		$remaining = $this->mutateStock(scan: $scan, mutation: new StockMutation(location: $scan->location(), delta: -$scan->quantity()));
		$this->mutateStock(scan: $scan, mutation: new StockMutation(location: $scan->toLocation(), delta: $scan->quantity()));
		$this->saveObject(schema: 'InventoryTransfer', object: $this->transferRecord(scan: $scan));

		return ['status' => 'applied', 'resultingQuantity' => $remaining];
	}//end applyTransfer()

	/**
	 * Build the InventoryTransfer row of a transfer.
	 *
	 * @param ScanOperation $scan The operation.
	 *
	 * @return array<string, mixed>
	 */
	private function transferRecord(ScanOperation $scan): array {
		return [
			'administrationId' => $scan->administrationId,
			'sku' => $scan->sku(),
			'fromLocation' => $scan->location(),
			'toLocation' => $scan->toLocation(),
			'quantity' => $scan->quantity(),
			'transferredBy' => $scan->userId,
			'transferredAt' => $scan->now,
			'transactionId' => $scan->transactionId(),
		];
	}//end transferRecord()

	/**
	 * Apply a pick: decrement stock (REQ-INVENTORY-003). The order-line mark-picked
	 * is deferred (no order schema in this app yet — see tasks.md).
	 *
	 * The acting user and transactionId are captured on the MobileScanOperation
	 * audit record (see recordOperation), so the pick itself only needs the stock
	 * coordinates.
	 *
	 * @param ScanOperation $scan The operation.
	 *
	 * @return array{status: string, resultingQuantity: float}
	 */
	private function applyPick(ScanOperation $scan): array {
		$remaining = $this->mutateStock(scan: $scan, mutation: new StockMutation(location: $scan->location(), delta: -$scan->quantity()));
		return ['status' => 'applied', 'resultingQuantity' => $remaining];
	}//end applyPick()

	/**
	 * Apply a count: compute variance and write an InventoryCount; do NOT auto-apply
	 * to stock (REQ-INVENTORY-004 requires manual approval).
	 *
	 * @param ScanOperation $scan The operation; its quantity is the physically counted quantity.
	 *
	 * @return array{status: string, variance: float}
	 */
	private function applyCount(ScanOperation $scan): array {
		$stock = $this->findStock(scan: $scan, location: $scan->location());
		$count = $this->countRecord(scan: $scan, systemQty: (float)($stock['quantity'] ?? 0));
		$this->saveObject(schema: 'InventoryCount', object: $count);

		return ['status' => 'applied', 'variance' => $count['variance']];
	}//end applyCount()

	/**
	 * Build the InventoryCount row of a count, with its variance against the system quantity.
	 *
	 * @param ScanOperation $scan The operation.
	 * @param float $systemQty The on-hand quantity the system holds.
	 *
	 * @return array<string, mixed>
	 */
	private function countRecord(ScanOperation $scan, float $systemQty): array {
		return [
			'administrationId' => $scan->administrationId,
			'sku' => $scan->sku(),
			'location' => $scan->location(),
			'systemQuantity' => $systemQty,
			'physicalQuantity' => $scan->quantity(),
			'variance' => ($scan->quantity() - $systemQty),
			'countedBy' => $scan->userId,
			'countedAt' => $scan->now,
			'applied' => false,
			'transactionId' => $scan->transactionId(),
		];
	}//end countRecord()

	/**
	 * Find the InventoryStock record of the operation's SKU at a location.
	 *
	 * @param ScanOperation $scan The operation.
	 * @param string $location Location code.
	 *
	 * @return array<string, mixed>|null
	 */
	private function findStock(ScanOperation $scan, string $location): ?array {
		return $this->findOne(
			schema: 'InventoryStock',
			filters: $this->scopeFilters(filters: ['sku' => $scan->sku(), 'location' => $location], administrationId: $scan->administrationId)
		);
	}//end findStock()

	/**
	 * Mutate (or create) the InventoryStock record for (sku, location) by delta,
	 * clamping at zero so on-hand never goes negative. Returns the new quantity.
	 *
	 * @param ScanOperation $scan The operation; its time is stamped as lastModified.
	 * @param StockMutation $mutation The location and signed quantity change.
	 *
	 * @return float The resulting on-hand quantity.
	 */
	private function mutateStock(ScanOperation $scan, StockMutation $mutation): float {
		$stock = $this->findStock(scan: $scan, location: $mutation->location);
		$next = $this->clampedQuantity(stock: $stock, delta: $mutation->delta);

		$object = ($stock ?? []);
		$object['administrationId'] = $scan->administrationId;
		$object['sku'] = $scan->sku();
		$object['location'] = $mutation->location;
		$object['quantity'] = $next;
		$object['lastModified'] = $scan->now;
		$object['status'] = ($object['status'] ?? 'active');

		$this->saveObject(schema: 'InventoryStock', object: $object);

		return $next;
	}//end mutateStock()

	/**
	 * The on-hand quantity after a change, clamped at zero.
	 *
	 * @param array<string, mixed>|null $stock The current InventoryStock record, if any.
	 * @param float $delta Signed quantity change.
	 *
	 * @return float
	 */
	private function clampedQuantity(?array $stock, float $delta): float {
		$next = ((float)($stock['quantity'] ?? 0) + $delta);
		if ($next < 0.0) {
			$next = 0.0;
		}

		return $next;
	}//end clampedQuantity()

	/**
	 * Persist the MobileScanOperation audit record with its server-decided outcome
	 * (REQ-PERM-002 audit trail).
	 *
	 * @param ScanOperation $scan The operation.
	 * @param array<string, mixed> $outcome The outcome (status, message).
	 *
	 * @return void
	 */
	private function recordOperation(ScanOperation $scan, array $outcome): void {
		$this->saveObject(schema: 'MobileScanOperation', object: $this->operationRecord(scan: $scan, outcome: $outcome));

	}//end recordOperation()

	/**
	 * Build the MobileScanOperation audit row: the raw payload fields, the actor, the sync time and the outcome.
	 *
	 * @param ScanOperation $scan The operation.
	 * @param array<string, mixed> $outcome The outcome (status, message).
	 *
	 * @return array<string, mixed>
	 */
	private function operationRecord(ScanOperation $scan, array $outcome): array {
		return ['administrationId' => $scan->administrationId] + $this->payloadFields(payload: $scan->payload) + [
			'performedBy' => $scan->userId,
			'clientTimestamp' => (string)($scan->payload['clientTimestamp'] ?? ''),
			'syncedAt' => $scan->now,
			'state' => $this->operationState(outcome: $outcome),
			'rejectionReason' => (string)($outcome['message'] ?? ''),
		];
	}//end operationRecord()

	/**
	 * The payload fields the audit row carries as uploaded (untrimmed).
	 *
	 * @param array<string, mixed> $payload The original operation payload.
	 *
	 * @return array<string, mixed>
	 */
	private function payloadFields(array $payload): array {
		return [
			'type' => (string)($payload['type'] ?? ''),
			'transactionId' => (string)($payload['transactionId'] ?? ''),
			'sku' => (string)($payload['sku'] ?? ''),
			'location' => (string)($payload['location'] ?? ''),
			'toLocation' => (string)($payload['toLocation'] ?? ''),
			'quantity' => (float)($payload['quantity'] ?? 0),
			'role' => (string)($payload['role'] ?? ''),
		];
	}//end payloadFields()

	/**
	 * The audit state of an outcome: applied, rejected or duplicate; anything else is rejected.
	 *
	 * @param array<string, mixed> $outcome The outcome (status, message).
	 *
	 * @return string
	 */
	private function operationState(array $outcome): string {
		$state = (string)($outcome['status'] ?? 'applied');
		if (in_array($state, ['applied', 'rejected', 'duplicate'], true) === false) {
			$state = 'rejected';
		}

		return $state;
	}//end operationState()

	/**
	 * Add the administration scope to a filter set when present.
	 *
	 * @param array<string, mixed> $filters Base filters.
	 * @param string $administrationId Administration scope (may be empty).
	 *
	 * @return array<string, mixed> The scoped filter set.
	 */
	private function scopeFilters(array $filters, string $administrationId): array {
		if ($administrationId !== '') {
			$filters['administrationId'] = $administrationId;
		}

		return $filters;
	}//end scopeFilters()

	/**
	 * Save an object into the configured register via the real ObjectService API.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $object Object data.
	 *
	 * @return void
	 */
	private function saveObject(string $schema, array $object): void {
		$this->objectService->saveObject(
			object: $object,
			register: $this->getRegisterSlug(),
			schema: $schema,
		);

	}//end saveObject()

	/**
	 * Find the first record matching exact filters in the configured register.
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 *
	 * @return array<string, mixed>|null First matching record, or null.
	 */
	private function findOne(string $schema, array $filters): ?array {
		$records = $this->findMany(schema: $schema, filters: $filters, limit: 1);
		if (count($records) === 0) {
			return null;
		}

		return reset($records);
	}//end findOne()

	/**
	 * Find records by exact-match filters in the configured register. Returns an
	 * empty array when the schema is unavailable (T1 state).
	 *
	 * @param string $schema Schema name.
	 * @param array<string, mixed> $filters Exact-match filters.
	 * @param int|null $limit Optional result limit.
	 *
	 * @return array<int, array<string, mixed>> Matching records.
	 */
	private function findMany(string $schema, array $filters, ?int $limit = null): array {
		try {
			$params = ['filters' => $filters];
			if ($limit !== null) {
				$params['limit'] = $limit;
			}

			$result = $this->objectService
				->setRegister(register: $this->getRegisterSlug())
				->setSchema(schema: $schema)
				->findAll($params);

			return $result;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'InventoryScanService: schema lookup unavailable (T1 state) — treating as empty',
				['schema' => $schema, 'exception' => $e->getMessage()]
			);
			return [];
		}//end try

	}//end findMany()

	/**
	 * Parse an ISO-8601 timestamp into epoch seconds, or null when unparseable.
	 *
	 * @param string $value Timestamp string.
	 *
	 * @return int|null Epoch seconds, or null.
	 */
	private function parseTimestamp(string $value): ?int {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		$epoch = strtotime($value);
		if ($epoch === false) {
			return null;
		}

		return $epoch;
	}//end parseTimestamp()
}//end class
