<?php

/**
 * Inventory Scan Service
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCA\Shillinq\AppInfo\Application;
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
		$slug = $this->appConfig->getValueString(Application::APP_ID, 'register', 'shillinq');
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
		$transactionId = trim((string)($operation['transactionId'] ?? ''));
		$type = (string)($operation['type'] ?? '');
		$now = gmdate('Y-m-d\TH:i:s\Z');

		// REQ-SYNC-001 — deduplicate on transactionId. A repeat upload returns the
		// recorded outcome without applying the mutation twice.
		$existing = $this->findOne(
			schema: 'MobileScanOperation',
			filters: $this->scopeFilters(filters: ['transactionId' => $transactionId], administrationId: $administrationId)
		);
		if ($existing !== null) {
			return [
				'status' => 'duplicate',
				'transactionId' => $transactionId,
				'syncedAt' => (string)($existing['syncedAt'] ?? $now),
				'message' => 'Operation already processed (idempotent).',
			];
		}

		$sku = trim((string)($operation['sku'] ?? ''));
		$location = trim((string)($operation['location'] ?? ''));
		$quantity = (float)($operation['quantity'] ?? 0);

		$toLocation = trim((string)($operation['toLocation'] ?? ''));

		switch ($type) {
			case 'receive':
				$result = $this->applyReceive(
					sku: $sku,
					location: $location,
					quantity: $quantity,
					userId: $userId,
					administrationId: $administrationId,
					transactionId: $transactionId,
					now: $now
				);
				break;
			case 'transfer':
				$result = $this->applyTransfer(
					sku: $sku,
					fromLocation: $location,
					toLocation: $toLocation,
					quantity: $quantity,
					userId: $userId,
					administrationId: $administrationId,
					transactionId: $transactionId,
					now: $now
				);
				break;
			case 'pick':
				$result = $this->applyPick(
					sku: $sku,
					location: $location,
					quantity: $quantity,
					administrationId: $administrationId,
					now: $now
				);
				break;
			case 'count':
				$result = $this->applyCount(
					sku: $sku,
					location: $location,
					physicalQuantity: $quantity,
					userId: $userId,
					administrationId: $administrationId,
					transactionId: $transactionId,
					now: $now
				);
				break;
			default:
				$result = ['status' => 'rejected', 'message' => 'Unknown operation type.'];
				break;
		}//end switch

		$this->recordOperation(
			operation: $operation,
			userId: $userId,
			administrationId: $administrationId,
			outcome: $result,
			now: $now
		);

		$result['transactionId'] = $transactionId;
		$result['syncedAt'] = $now;
		return $result;
	}//end applyOperation()

	/**
	 * Apply a receive: increment stock and write a GoodsReceipt (REQ-INVENTORY-001).
	 *
	 * @param string $sku SKU.
	 * @param string $location Location code.
	 * @param float $quantity Quantity received.
	 * @param string $userId Acting user id.
	 * @param string $administrationId Administration scope.
	 * @param string $transactionId Idempotency key.
	 * @param string $now ISO-8601 UTC timestamp.
	 *
	 * @return array{status: string, resultingQuantity: float}
	 */
	private function applyReceive(
		string $sku,
		string $location,
		float $quantity,
		string $userId,
		string $administrationId,
		string $transactionId,
		string $now,
	): array {
		$resulting = $this->mutateStock(sku: $sku, location: $location, delta: $quantity, administrationId: $administrationId, now: $now);

		$this->saveObject(
			schema: 'GoodsReceipt',
			object: [
				'administrationId' => $administrationId,
				'sku' => $sku,
				'location' => $location,
				'quantity' => $quantity,
				'resultingQuantity' => $resulting,
				'receivedBy' => $userId,
				'receivedAt' => $now,
				'transactionId' => $transactionId,
			]
		);

		return ['status' => 'applied', 'resultingQuantity' => $resulting];
	}//end applyReceive()

	/**
	 * Apply a transfer: decrement source, increment destination, write an
	 * InventoryTransfer (REQ-INVENTORY-002).
	 *
	 * @param string $sku SKU.
	 * @param string $fromLocation Source location code.
	 * @param string $toLocation Destination location code.
	 * @param float $quantity Quantity moved.
	 * @param string $userId Acting user id.
	 * @param string $administrationId Administration scope.
	 * @param string $transactionId Idempotency key.
	 * @param string $now ISO-8601 UTC timestamp.
	 *
	 * @return array{status: string, resultingQuantity: float}
	 */
	private function applyTransfer(
		string $sku,
		string $fromLocation,
		string $toLocation,
		float $quantity,
		string $userId,
		string $administrationId,
		string $transactionId,
		string $now,
	): array {
		$remaining = $this->mutateStock(sku: $sku, location: $fromLocation, delta: -$quantity, administrationId: $administrationId, now: $now);
		$this->mutateStock(sku: $sku, location: $toLocation, delta: $quantity, administrationId: $administrationId, now: $now);

		$this->saveObject(
			schema: 'InventoryTransfer',
			object: [
				'administrationId' => $administrationId,
				'sku' => $sku,
				'fromLocation' => $fromLocation,
				'toLocation' => $toLocation,
				'quantity' => $quantity,
				'transferredBy' => $userId,
				'transferredAt' => $now,
				'transactionId' => $transactionId,
			]
		);

		return ['status' => 'applied', 'resultingQuantity' => $remaining];
	}//end applyTransfer()

	/**
	 * Apply a pick: decrement stock (REQ-INVENTORY-003). The order-line mark-picked
	 * is deferred (no order schema in this app yet — see tasks.md).
	 *
	 * The acting user and transactionId are captured on the MobileScanOperation
	 * audit record (see recordOperation), so the pick itself only needs the stock
	 * coordinates.
	 *
	 * @param string $sku SKU.
	 * @param string $location Location code.
	 * @param float $quantity Quantity picked.
	 * @param string $administrationId Administration scope.
	 * @param string $now ISO-8601 UTC timestamp.
	 *
	 * @return array{status: string, resultingQuantity: float}
	 */
	private function applyPick(
		string $sku,
		string $location,
		float $quantity,
		string $administrationId,
		string $now,
	): array {
		$remaining = $this->mutateStock(
			sku: $sku,
			location: $location,
			delta: -$quantity,
			administrationId: $administrationId,
			now: $now
		);
		return ['status' => 'applied', 'resultingQuantity' => $remaining];
	}//end applyPick()

	/**
	 * Apply a count: compute variance and write an InventoryCount; do NOT auto-apply
	 * to stock (REQ-INVENTORY-004 requires manual approval).
	 *
	 * @param string $sku SKU.
	 * @param string $location Location code.
	 * @param float $physicalQuantity Physically counted quantity.
	 * @param string $userId Acting user id.
	 * @param string $administrationId Administration scope.
	 * @param string $transactionId Idempotency key.
	 * @param string $now ISO-8601 UTC timestamp.
	 *
	 * @return array{status: string, variance: float}
	 */
	private function applyCount(
		string $sku,
		string $location,
		float $physicalQuantity,
		string $userId,
		string $administrationId,
		string $transactionId,
		string $now,
	): array {
		$stock = $this->findOne(
			schema: 'InventoryStock',
			filters: $this->scopeFilters(filters: ['sku' => $sku, 'location' => $location], administrationId: $administrationId)
		);
		$systemQty = (float)($stock['quantity'] ?? 0);
		$variance = ($physicalQuantity - $systemQty);

		$this->saveObject(
			schema: 'InventoryCount',
			object: [
				'administrationId' => $administrationId,
				'sku' => $sku,
				'location' => $location,
				'systemQuantity' => $systemQty,
				'physicalQuantity' => $physicalQuantity,
				'variance' => $variance,
				'countedBy' => $userId,
				'countedAt' => $now,
				'applied' => false,
				'transactionId' => $transactionId,
			]
		);

		return ['status' => 'applied', 'variance' => $variance];
	}//end applyCount()

	/**
	 * Mutate (or create) the InventoryStock record for (sku, location) by delta,
	 * clamping at zero so on-hand never goes negative. Returns the new quantity.
	 *
	 * @param string $sku SKU.
	 * @param string $location Location code.
	 * @param float $delta Signed quantity change.
	 * @param string $administrationId Administration scope.
	 * @param string $now ISO-8601 UTC timestamp to stamp as lastModified.
	 *
	 * @return float The resulting on-hand quantity.
	 */
	private function mutateStock(string $sku, string $location, float $delta, string $administrationId, string $now): float {
		$stock = $this->findOne(
			schema: 'InventoryStock',
			filters: $this->scopeFilters(filters: ['sku' => $sku, 'location' => $location], administrationId: $administrationId)
		);
		$current = (float)($stock['quantity'] ?? 0);
		$next = ($current + $delta);
		if ($next < 0.0) {
			$next = 0.0;
		}

		$object = ($stock ?? []);
		$object['administrationId'] = $administrationId;
		$object['sku'] = $sku;
		$object['location'] = $location;
		$object['quantity'] = $next;
		$object['lastModified'] = $now;
		if (isset($object['status']) === false) {
			$object['status'] = 'active';
		}

		$this->saveObject(schema: 'InventoryStock', object: $object);

		return $next;
	}//end mutateStock()

	/**
	 * Persist the MobileScanOperation audit record with its server-decided outcome
	 * (REQ-PERM-002 audit trail).
	 *
	 * @param array<string, mixed> $operation The original operation payload.
	 * @param string $userId Acting user id.
	 * @param string $administrationId Administration scope.
	 * @param array<string, mixed> $outcome The outcome (status, message).
	 * @param string $now ISO-8601 UTC timestamp.
	 *
	 * @return void
	 */
	private function recordOperation(array $operation, string $userId, string $administrationId, array $outcome, string $now): void {
		$state = (string)($outcome['status'] ?? 'applied');
		if (in_array($state, ['applied', 'rejected', 'duplicate'], true) === false) {
			$state = 'rejected';
		}

		$this->saveObject(
			schema: 'MobileScanOperation',
			object: [
				'administrationId' => $administrationId,
				'type' => (string)($operation['type'] ?? ''),
				'transactionId' => (string)($operation['transactionId'] ?? ''),
				'sku' => (string)($operation['sku'] ?? ''),
				'location' => (string)($operation['location'] ?? ''),
				'toLocation' => (string)($operation['toLocation'] ?? ''),
				'quantity' => (float)($operation['quantity'] ?? 0),
				'role' => (string)($operation['role'] ?? ''),
				'performedBy' => $userId,
				'clientTimestamp' => (string)($operation['clientTimestamp'] ?? ''),
				'syncedAt' => $now,
				'state' => $state,
				'rejectionReason' => (string)($outcome['message'] ?? ''),
			]
		);

	}//end recordOperation()

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
