<?php

/**
 * Contract of InventoryScanService
 *
 * The public surface of InventoryScanService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Shillinq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel\Contracts;

/**
 * Contract of InventoryScanService.
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/shillinq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InventoryScanServiceInterface {

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
	public function applyOperation(array $operation, string $userId, string $administrationId): array;

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
	public function getStockDelta(?string $since, string $administrationId): array;

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
	public function resolveBarcode(string $barcode, string $administrationId): ?array;
}//end interface
