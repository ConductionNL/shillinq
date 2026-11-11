<?php

/**
 * Shillinq ScanOperation
 *
 * One mobile-scan operation as InventoryScanService applies it: the payload
 * the scanner uploaded, the acting user, the administration it is scoped to
 * and the server time it is synced at. The trimmed stock coordinates are read
 * from the payload through the methods below.
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

/**
 * A mobile-scan operation with its actor, administration and sync time.
 *
 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
 */
final class ScanOperation {
	/**
	 * Hold the operation.
	 *
	 * @param array<string, mixed> $payload The operation payload (type, sku, location, toLocation, quantity, transactionId).
	 * @param string $userId The acting Nextcloud user id.
	 * @param string $administrationId The administration scope.
	 * @param string $now ISO-8601 UTC timestamp the operation is synced at.
	 */
	public function __construct(
		public readonly array $payload,
		public readonly string $userId,
		public readonly string $administrationId,
		public readonly string $now,
	) {
	}//end __construct()

	/**
	 * The operation type (receive, transfer, pick or count).
	 *
	 * @return string
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
	 */
	public function type(): string {
		return (string)($this->payload['type'] ?? '');
	}//end type()

	/**
	 * The trimmed idempotency key.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
	 */
	public function transactionId(): string {
		return trim((string)($this->payload['transactionId'] ?? ''));
	}//end transactionId()

	/**
	 * The trimmed SKU.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
	 */
	public function sku(): string {
		return trim((string)($this->payload['sku'] ?? ''));
	}//end sku()

	/**
	 * The trimmed (source) location code.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
	 */
	public function location(): string {
		return trim((string)($this->payload['location'] ?? ''));
	}//end location()

	/**
	 * The trimmed destination location code of a transfer.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
	 */
	public function toLocation(): string {
		return trim((string)($this->payload['toLocation'] ?? ''));
	}//end toLocation()

	/**
	 * The quantity received, moved, picked or counted.
	 *
	 * @return float
	 *
	 * @spec openspec/changes/inventory-mobile-scanner/tasks.md#T1.4
	 */
	public function quantity(): float {
		return (float)($this->payload['quantity'] ?? 0);
	}//end quantity()
}//end class
