<?php

/**
 * Inventory Mobile Scanner Service
 *
 * Server-side sync core for the Shillinq inventory warehouse PWA. Implements
 * the two halves of the delta-sync protocol declared by REQ-OFFLINE-002 /
 * REQ-SYNC-001 / REQ-SYNC-002:
 *
 *   - downloadDeltas(since): returns InventoryStock records modified after the
 *     supplied ISO 8601 timestamp so the client can merge server-side changes
 *     into its IndexedDB cache (LWW per REQ-OFFLINE-003).
 *   - uploadOperations(batch): applies a batch of warehouse operations
 *     (receive / transfer / pick / count) atomically. Each operation is
 *     keyed by a client transactionId; duplicates within the dedup window
 *     return the original ACK without re-applying mutations.
 *
 * Permission gates per REQ-PERM-001 live inside uploadOperations(): the
 * caller MUST supply the resolved user role(s). Operations without a matching
 * role are recorded as rejected and reported back to the client so the PWA
 * can surface the "you don't have permission" toast (T5.2).
 *
 * The service is intentionally OpenRegister-agnostic at construction time:
 * the ObjectService instance is lazy-resolved via the container so unit tests
 * can substitute the standard Psr container mock.
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
 * @spec openspec/specs/inventory-mobile-scanner/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Server-side sync logic for the inventory mobile scanner PWA.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class InventoryMobileScannerService {

	/**
	 * Dedup window in seconds. Re-uploads of the same transactionId within
	 * this window return the cached ACK without re-applying the mutation
	 * per REQ-SYNC-001.
	 */
	public const DEDUP_WINDOW_SECONDS = 86400;

	/**
	 * Map of warehouse operation type to the role(s) that may submit it
	 * per REQ-PERM-001. Group memberships are checked at sync time; admins
	 * are unconditionally allowed (cluster admin/dev/break-glass).
	 *
	 * @var array<string,list<string>>
	 */
	private const OPERATION_ROLES = [
		'receive' => ['warehouse_manager'],
		'transfer' => ['inventory_operator'],
		'pick' => ['inventory_operator'],
		'count' => ['counter'],
	];

	/**
	 * Construct the sync service with DI dependencies.
	 *
	 * @param SettingsService $settings Provides register slug + OR availability check.
	 * @param ContainerInterface $container DI container for lazy ObjectService resolution.
	 * @param ITimeFactory $time Time provider for server-side timestamps.
	 * @param LoggerInterface $logger Logger for fail-closed diagnostics.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return InventoryStock records modified strictly after the supplied
	 * ISO 8601 timestamp. An empty / null timestamp returns the full set
	 * (initial sync) up to the configured limit.
	 *
	 * Records are scoped to the supplied administrationId so multi-tenant
	 * cross-leaks cannot occur (ADR-005 / ADR-022).
	 *
	 * @param string|null $since ISO 8601 UTC timestamp, e.g. "2026-05-21T14:23:00Z".
	 * @param string $administrationId Tenant scope.
	 * @param int $limit Maximum records to return per page.
	 *
	 * @return array{deltas: list<array<string,mixed>>, serverTimestamp: string}
	 */
	public function downloadDeltas(?string $since, string $administrationId, int $limit = 500): array {
		$serverTimestamp = $this->nowIso();
		$deltas = [];

		if ($this->settings->isOpenRegisterAvailable() === false) {
			$this->logger->warning('Shillinq mobile scanner: OpenRegister unavailable; returning empty deltas');
			return ['deltas' => [], 'serverTimestamp' => $serverTimestamp];
		}

		if ($administrationId === '') {
			$this->logger->warning('Shillinq mobile scanner: empty administrationId on download; returning empty deltas');
			return ['deltas' => [], 'serverTimestamp' => $serverTimestamp];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();

			$filters = ['administrationId' => $administrationId];

			$records = $objectService
				->setRegister($registerSlug)
				->setSchema('InventoryStock')
				->findAll(
					[
						'filters' => $filters,
						'limit' => $limit,
					]
				);

			foreach ($records as $record) {
				$row = $this->stockToArray(record: $record);
				if ($since !== null && $since !== '' && $this->isStrictlyAfter(since: $since, candidate: ($row['lastModified'] ?? '')) === true) {
					continue;
				}

				$deltas[] = $row;
			}
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq mobile scanner: failed to download deltas',
				['exception' => $e->getMessage()]
			);
			return ['deltas' => [], 'serverTimestamp' => $serverTimestamp];
		}//end try

		return ['deltas' => $deltas, 'serverTimestamp' => $serverTimestamp];
	}//end downloadDeltas()

	/**
	 * Apply a batch of warehouse operations from the mobile PWA.
	 *
	 * Each operation is checked for:
	 *   1. Schema validity (type, sku, location, quantity, transactionId).
	 *   2. transactionId deduplication (within DEDUP_WINDOW_SECONDS).
	 *   3. Role-based permission (REQ-PERM-001).
	 *   4. Atomic stock mutation + audit record creation.
	 *
	 * The method MUST be called with a resolved $userId (Nextcloud auth) and
	 * a list of $roles that the user holds. The caller is responsible for
	 * mapping group memberships (Nextcloud groups warehouse_manager /
	 * inventory_operator / counter) to these role strings.
	 *
	 * @param array<int,array<string,mixed>> $operations Client-submitted operations.
	 * @param string $userId Nextcloud user id of the submitter.
	 * @param list<string> $roles Role strings the user holds.
	 * @param string $administrationId Tenant scope.
	 *
	 * @return array{results: list<array<string,mixed>>, serverTimestamp: string}
	 */
	public function uploadOperations(
		array $operations,
		string $userId,
		array $roles,
		string $administrationId,
	): array {
		$serverTimestamp = $this->nowIso();
		$results = [];

		if ($this->settings->isOpenRegisterAvailable() === false) {
			foreach ($operations as $op) {
				$results[] = [
					'transactionId' => (string)($op['transactionId'] ?? ''),
					'status' => 'rejected_validation',
					'reason' => 'OpenRegister unavailable',
				];
			}

			return ['results' => $results, 'serverTimestamp' => $serverTimestamp];
		}

		if ($administrationId === '' || $userId === '') {
			foreach ($operations as $op) {
				$results[] = [
					'transactionId' => (string)($op['transactionId'] ?? ''),
					'status' => 'rejected_validation',
					'reason' => 'missing tenant or user',
				];
			}

			return ['results' => $results, 'serverTimestamp' => $serverTimestamp];
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$registerSlug = $this->settings->getRegisterSlug();
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq mobile scanner: failed to acquire ObjectService',
				['exception' => $e->getMessage()]
			);
			foreach ($operations as $op) {
				$results[] = [
					'transactionId' => (string)($op['transactionId'] ?? ''),
					'status' => 'rejected_validation',
					'reason' => 'sync backend unavailable',
				];
			}

			return ['results' => $results, 'serverTimestamp' => $serverTimestamp];
		}

		foreach ($operations as $op) {
			$results[] = $this->processOperation(
				op: $op,
				userId: $userId,
				roles: $roles,
				administrationId: $administrationId,
				objectService: $objectService,
				registerSlug: $registerSlug,
				serverTimestamp: $serverTimestamp,
			);
		}

		return ['results' => $results, 'serverTimestamp' => $serverTimestamp];
	}//end uploadOperations()

	/**
	 * Process a single operation from a sync batch.
	 *
	 * @param array<string,mixed> $op Client-submitted operation row.
	 * @param string $userId Authenticated user id.
	 * @param list<string> $roles User roles.
	 * @param string $administrationId Tenant scope.
	 * @param object $objectService Lazy-resolved OR ObjectService.
	 * @param string $registerSlug Shillinq register slug.
	 * @param string $serverTimestamp ISO 8601 server timestamp.
	 *
	 * @return array<string,mixed> Per-operation ACK.
	 */
	private function processOperation(
		array $op,
		string $userId,
		array $roles,
		string $administrationId,
		object $objectService,
		string $registerSlug,
		string $serverTimestamp,
	): array {
		$transactionId = (string)($op['transactionId'] ?? '');
		$type = (string)($op['type'] ?? '');

		$validation = $this->validateOperation(op: $op);
		if ($validation !== null) {
			return [
				'transactionId' => $transactionId,
				'status' => 'rejected_validation',
				'reason' => $validation,
			];
		}

		$duplicate = $this->findDuplicateBatch(
			objectService: $objectService,
			registerSlug: $registerSlug,
			transactionId: $transactionId,
			administrationId: $administrationId,
		);

		if ($duplicate !== null) {
			return [
				'transactionId' => $transactionId,
				'status' => 'duplicate',
				'serverAckedAt' => ($duplicate['ackedAt'] ?? $serverTimestamp),
				'reason' => 'transactionId already processed',
			];
		}

		if ($this->isPermitted(type: $type, roles: $roles) === false) {
			$this->recordSyncBatch(
				objectService: $objectService,
				registerSlug: $registerSlug,
				op: $op,
				userId: $userId,
				administrationId: $administrationId,
				serverTimestamp: $serverTimestamp,
				status: 'rejected_permission',
				rejectionReason: 'role required: ' . implode(',', (self::OPERATION_ROLES[$type] ?? [])),
			);

			return [
				'transactionId' => $transactionId,
				'status' => 'rejected_permission',
				'reason' => 'role required: ' . implode(',', (self::OPERATION_ROLES[$type] ?? [])),
			];
		}

		try {
			$this->applyMutation(
				objectService: $objectService,
				registerSlug: $registerSlug,
				op: $op,
				userId: $userId,
				administrationId: $administrationId,
				serverTimestamp: $serverTimestamp,
			);

			$this->recordSyncBatch(
				objectService: $objectService,
				registerSlug: $registerSlug,
				op: $op,
				userId: $userId,
				administrationId: $administrationId,
				serverTimestamp: $serverTimestamp,
				status: 'accepted',
				rejectionReason: null,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Shillinq mobile scanner: mutation failed',
				['exception' => $e->getMessage(), 'transactionId' => $transactionId]
			);
			return [
				'transactionId' => $transactionId,
				'status' => 'rejected_validation',
				'reason' => 'mutation failed: ' . $e->getMessage(),
			];
		}//end try

		return [
			'transactionId' => $transactionId,
			'status' => 'accepted',
			'serverAckedAt' => $serverTimestamp,
		];

	}//end processOperation()

	/**
	 * Validate the shape of a single client operation.
	 *
	 * @param array<string,mixed> $op Operation row.
	 *
	 * @return string|null Null on valid; rejection reason on invalid.
	 */
	private function validateOperation(array $op): ?string {
		$transactionId = (string)($op['transactionId'] ?? '');
		if ($transactionId === '') {
			return 'missing transactionId';
		}

		$type = (string)($op['type'] ?? '');
		if (isset(self::OPERATION_ROLES[$type]) === false) {
			return 'unknown operation type';
		}

		$sku = (string)($op['sku'] ?? '');
		if ($sku === '') {
			return 'missing sku';
		}

		$location = (string)($op['location'] ?? '');
		if ($location === '') {
			return 'missing location';
		}

		if ($type === 'transfer') {
			$toLocation = (string)($op['toLocation'] ?? '');
			if ($toLocation === '') {
				return 'transfer requires toLocation';
			}

			if ($toLocation === $location) {
				return 'transfer source and destination must differ';
			}
		}

		$quantity = ($op['quantity'] ?? null);
		if (is_numeric($quantity) === false) {
			return 'quantity must be numeric';
		}

		if (in_array($type, ['receive', 'transfer', 'pick'], true) === true && (float)$quantity <= 0.0) {
			return 'quantity must be strictly positive';
		}

		if ($type === 'count') {
			$physical = ($op['physicalQuantity'] ?? null);
			if (is_numeric($physical) === false || (float)$physical < 0.0) {
				return 'physicalQuantity must be a non-negative number';
			}
		}

		return null;
	}//end validateOperation()

	/**
	 * Locate a previously-processed sync batch for the supplied transactionId,
	 * scoped to the supplied administrationId, that falls within the dedup
	 * window per REQ-SYNC-001.
	 *
	 * @param object $objectService OR ObjectService.
	 * @param string $registerSlug Shillinq register slug.
	 * @param string $transactionId Client transactionId.
	 * @param string $administrationId Tenant scope.
	 *
	 * @return array<string,mixed>|null Matching batch row, or null if none.
	 */
	private function findDuplicateBatch(
		object $objectService,
		string $registerSlug,
		string $transactionId,
		string $administrationId,
	): ?array {
		try {
			$existing = $objectService
				->setRegister($registerSlug)
				->setSchema('MobileScannerSyncBatch')
				->findAll(
					[
						'filters' => [
							'transactionId' => $transactionId,
							'administrationId' => $administrationId,
						],
						'limit' => 1,
					]
				);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Shillinq mobile scanner: dedup lookup failed; proceeding as non-duplicate',
				['exception' => $e->getMessage(), 'transactionId' => $transactionId]
			);
			return null;
		}

		if (empty($existing) === true) {
			return null;
		}

		$row = $this->toArray(record: $existing[0]);

		$occurredAt = (string)($row['occurredAt'] ?? '');
		if ($occurredAt === '') {
			// Defensive: a row without a timestamp is treated as a duplicate so
			// we never re-apply a possibly-recent mutation.
			return $row;
		}

		$occurredEpoch = strtotime($occurredAt);
		if ($occurredEpoch === false) {
			return $row;
		}

		$nowEpoch = $this->time->getTime();
		if (($nowEpoch - $occurredEpoch) > self::DEDUP_WINDOW_SECONDS) {
			return null;
		}

		return $row;
	}//end findDuplicateBatch()

	/**
	 * Determine whether the supplied roles permit the supplied operation
	 * type. Admin role is unconditionally permitted; otherwise the role
	 * list must include any role from OPERATION_ROLES[type].
	 *
	 * @param string $type Operation type.
	 * @param list<string> $roles User roles.
	 *
	 * @return bool True when permitted, false otherwise.
	 */
	private function isPermitted(string $type, array $roles): bool {
		if (in_array('admin', $roles, true) === true) {
			return true;
		}

		if (in_array('administrator', $roles, true) === true) {
			return true;
		}

		$required = (self::OPERATION_ROLES[$type] ?? []);
		foreach ($required as $role) {
			if (in_array($role, $roles, true) === true) {
				return true;
			}
		}

		return false;
	}//end isPermitted()

	/**
	 * Apply the warehouse mutation for a validated, permitted operation:
	 * update InventoryStock and write the corresponding audit record
	 * (GoodsReceipt / InventoryTransfer / OrderPick / InventoryCount).
	 *
	 * @param object $objectService OR ObjectService.
	 * @param string $registerSlug Shillinq register slug.
	 * @param array<string,mixed> $op Validated client operation.
	 * @param string $userId Authenticated user id.
	 * @param string $administrationId Tenant scope.
	 * @param string $serverTimestamp ISO 8601 server timestamp.
	 *
	 * @return void
	 */
	private function applyMutation(
		object $objectService,
		string $registerSlug,
		array $op,
		string $userId,
		string $administrationId,
		string $serverTimestamp,
	): void {
		$type = (string)($op['type'] ?? '');
		$sku = (string)($op['sku'] ?? '');
		$location = (string)($op['location'] ?? '');
		$quantity = (float)($op['quantity'] ?? 0);
		$transactionId = (string)($op['transactionId'] ?? '');

		if ($type === 'receive') {
			$this->mutateStock(
				objectService: $objectService,
				registerSlug: $registerSlug,
				administrationId: $administrationId,
				sku: $sku,
				location: $location,
				delta: $quantity,
				serverTimestamp: $serverTimestamp,
			);

			$objectService->saveObject(
				object: [
					'administrationId' => $administrationId,
					'sku' => $sku,
					'location' => $location,
					'quantity' => $quantity,
					'userId' => $userId,
					'occurredAt' => $serverTimestamp,
					'transactionId' => $transactionId,
				],
				register: $registerSlug,
				schema: 'GoodsReceipt',
			);
			return;
		}//end if

		if ($type === 'transfer') {
			$toLocation = (string)($op['toLocation'] ?? '');
			$this->mutateStock(
				objectService: $objectService,
				registerSlug: $registerSlug,
				administrationId: $administrationId,
				sku: $sku,
				location: $location,
				delta: (-1 * $quantity),
				serverTimestamp: $serverTimestamp,
			);
			$this->mutateStock(
				objectService: $objectService,
				registerSlug: $registerSlug,
				administrationId: $administrationId,
				sku: $sku,
				location: $toLocation,
				delta: $quantity,
				serverTimestamp: $serverTimestamp,
			);

			$objectService->saveObject(
				object: [
					'administrationId' => $administrationId,
					'sku' => $sku,
					'fromLocation' => $location,
					'toLocation' => $toLocation,
					'quantity' => $quantity,
					'userId' => $userId,
					'occurredAt' => $serverTimestamp,
					'transactionId' => $transactionId,
				],
				register: $registerSlug,
				schema: 'InventoryTransfer',
			);
			return;
		}//end if

		if ($type === 'pick') {
			$this->mutateStock(
				objectService: $objectService,
				registerSlug: $registerSlug,
				administrationId: $administrationId,
				sku: $sku,
				location: $location,
				delta: (-1 * $quantity),
				serverTimestamp: $serverTimestamp,
			);

			$objectService->saveObject(
				object: [
					'administrationId' => $administrationId,
					'sku' => $sku,
					'location' => $location,
					'quantity' => $quantity,
					'orderLineId' => ($op['orderLineId'] ?? null),
					'userId' => $userId,
					'occurredAt' => $serverTimestamp,
					'transactionId' => $transactionId,
				],
				register: $registerSlug,
				schema: 'OrderPick',
			);
			return;
		}//end if

		if ($type === 'count') {
			$physical = (float)($op['physicalQuantity'] ?? 0);
			$reconcile = (bool)($op['reconcile'] ?? false);
			$systemQty = $this->readSystemQuantity(
				objectService: $objectService,
				registerSlug: $registerSlug,
				administrationId: $administrationId,
				sku: $sku,
				location: $location,
			);

			$objectService->saveObject(
				object: [
					'administrationId' => $administrationId,
					'sku' => $sku,
					'location' => $location,
					'systemQuantity' => $systemQty,
					'physicalQuantity' => $physical,
					'variance' => ($physical - $systemQty),
					'reconciled' => $reconcile,
					'userId' => $userId,
					'occurredAt' => $serverTimestamp,
					'transactionId' => $transactionId,
				],
				register: $registerSlug,
				schema: 'InventoryCount',
			);

			if ($reconcile === true) {
				$this->mutateStock(
					objectService: $objectService,
					registerSlug: $registerSlug,
					administrationId: $administrationId,
					sku: $sku,
					location: $location,
					delta: ($physical - $systemQty),
					serverTimestamp: $serverTimestamp,
				);
			}
		}//end if

	}//end applyMutation()

	/**
	 * Apply a delta to the InventoryStock record for (administrationId, sku, location).
	 * Creates a new record at delta if none exists yet (initial receive).
	 *
	 * @param object $objectService OR ObjectService.
	 * @param string $registerSlug Shillinq register slug.
	 * @param string $administrationId Tenant scope.
	 * @param string $sku Stock-keeping unit.
	 * @param string $location Location code.
	 * @param float $delta Signed quantity change.
	 * @param string $serverTimestamp ISO 8601 server timestamp.
	 *
	 * @return void
	 */
	private function mutateStock(
		object $objectService,
		string $registerSlug,
		string $administrationId,
		string $sku,
		string $location,
		float $delta,
		string $serverTimestamp,
	): void {
		$existing = $objectService
			->setRegister($registerSlug)
			->setSchema('InventoryStock')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'sku' => $sku,
						'location' => $location,
					],
					'limit' => 1,
				]
			);

		if (empty($existing) === true) {
			$objectService->saveObject(
				object: [
					'administrationId' => $administrationId,
					'sku' => $sku,
					'location' => $location,
					'quantity' => max(0.0, $delta),
					'lastModified' => $serverTimestamp,
					'status' => 'active',
				],
				register: $registerSlug,
				schema: 'InventoryStock',
			);
			return;
		}

		$current = $this->toArray(record: $existing[0]);
		$currentQty = (float)($current['quantity'] ?? 0);
		$nextQty = max(0.0, ($currentQty + $delta));
		$current['quantity'] = $nextQty;
		$current['lastModified'] = $serverTimestamp;

		$objectService->saveObject(
			object: $current,
			register: $registerSlug,
			schema: 'InventoryStock',
		);

	}//end mutateStock()

	/**
	 * Read the current system quantity for (administrationId, sku, location).
	 * Returns 0.0 when no record exists yet.
	 *
	 * @param object $objectService OR ObjectService.
	 * @param string $registerSlug Shillinq register slug.
	 * @param string $administrationId Tenant scope.
	 * @param string $sku Stock-keeping unit.
	 * @param string $location Location code.
	 *
	 * @return float
	 */
	private function readSystemQuantity(
		object $objectService,
		string $registerSlug,
		string $administrationId,
		string $sku,
		string $location,
	): float {
		$existing = $objectService
			->setRegister($registerSlug)
			->setSchema('InventoryStock')
			->findAll(
				[
					'filters' => [
						'administrationId' => $administrationId,
						'sku' => $sku,
						'location' => $location,
					],
					'limit' => 1,
				]
			);

		if (empty($existing) === true) {
			return 0.0;
		}

		$row = $this->toArray(record: $existing[0]);
		return (float)($row['quantity'] ?? 0);
	}//end readSystemQuantity()

	/**
	 * Record a MobileScannerSyncBatch audit row for an operation that was
	 * accepted, rejected for permission reasons, or rejected for validation
	 * reasons. The row enables the dedup window check on retries and is the
	 * audit-trail source (REQ-PERM-002).
	 *
	 * @param object $objectService OR ObjectService.
	 * @param string $registerSlug Shillinq register slug.
	 * @param array<string,mixed> $op Original client operation.
	 * @param string $userId Authenticated user id.
	 * @param string $administrationId Tenant scope.
	 * @param string $serverTimestamp ISO 8601 server timestamp.
	 * @param string $status Disposition.
	 * @param string|null $rejectionReason Rejection reason, if any.
	 *
	 * @return void
	 */
	private function recordSyncBatch(
		object $objectService,
		string $registerSlug,
		array $op,
		string $userId,
		string $administrationId,
		string $serverTimestamp,
		string $status,
		?string $rejectionReason,
	): void {
		$type = (string)($op['type'] ?? '');
		$quantity = ($op['quantity'] ?? null);
		$delta = null;
		if (is_numeric($quantity) === true) {
			$delta = (float)$quantity;
			if ($type === 'pick') {
				$delta = (-1 * $delta);
			}
		}

		$objectService->saveObject(
			object: [
				'administrationId' => $administrationId,
				'transactionId' => (string)($op['transactionId'] ?? ''),
				'operationType' => $type,
				'sku' => ($op['sku'] ?? null),
				'location' => ($op['location'] ?? null),
				'toLocation' => ($op['toLocation'] ?? null),
				'quantityDelta' => $delta,
				'userId' => $userId,
				'occurredAt' => (string)($op['timestamp'] ?? $serverTimestamp),
				'ackedAt' => $serverTimestamp,
				'status' => $status,
				'rejectionReason' => $rejectionReason,
			],
			register: $registerSlug,
			schema: 'MobileScannerSyncBatch',
		);

	}//end recordSyncBatch()

	/**
	 * Return ISO 8601 UTC timestamp for "now".
	 *
	 * @return string
	 */
	private function nowIso(): string {
		return gmdate('Y-m-d\TH:i:s\Z', $this->time->getTime());
	}//end nowIso()

	/**
	 * Return true when $candidate < $reference (strictly older), meaning the
	 * candidate timestamp should be filtered out of a since-delta payload.
	 *
	 * Robust against missing or unparseable timestamps: when either side is
	 * unparseable, the candidate is kept (false return).
	 *
	 * @param string $since The since= cut-off (client-supplied).
	 * @param string $candidate The record's lastModified timestamp.
	 *
	 * @return bool
	 */
	private function isStrictlyAfter(string $since, string $candidate): bool {
		$sinceEpoch = strtotime($since);
		$candidateEpoch = strtotime($candidate);
		if ($sinceEpoch === false || $candidateEpoch === false) {
			return false;
		}

		return $candidateEpoch <= $sinceEpoch;
	}//end isStrictlyAfter()

	/**
	 * Normalise an OR record (entity or array) to a flat associative array.
	 * Defensive helper for adapters that return either entities or arrays.
	 *
	 * @param mixed $record Record returned by ObjectService::findAll().
	 *
	 * @return array<string,mixed>
	 */
	private function toArray(mixed $record): array {
		if (is_array($record) === true) {
			return $record;
		}

		if (is_object($record) === true && method_exists($record, 'jsonSerialize') === true) {
			$serialised = $record->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		if (is_object($record) === true) {
			return (array)$record;
		}

		return [];
	}//end toArray()

	/**
	 * Project an InventoryStock OR record onto the delta-row shape that the
	 * mobile client expects.
	 *
	 * @param mixed $record OR record.
	 *
	 * @return array<string,mixed>
	 */
	private function stockToArray(mixed $record): array {
		$row = $this->toArray(record: $record);
		return [
			'sku' => (string)($row['sku'] ?? ''),
			'location' => (string)($row['location'] ?? ''),
			'quantity' => (float)($row['quantity'] ?? 0),
			'lastModified' => (string)($row['lastModified'] ?? ''),
			'status' => (string)($row['status'] ?? 'active'),
		];

	}//end stockToArray()
}//end class
