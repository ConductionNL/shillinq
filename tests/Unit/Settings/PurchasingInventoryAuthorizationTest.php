<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Tests\Unit\Settings;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every purchasing and inventory schema is protected by an authorization block OpenRegister enforces.
 */
final class PurchasingInventoryAuthorizationTest extends TestCase {
	use SchemaAuthorizationAssertions;

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function schemas(): array {
		return [
			'APInvoice' => ['APInvoice'],
			'ApprovalStep' => ['ApprovalStep'],
			'Barcode' => ['Barcode'],
			'Commitment' => ['Commitment'],
			'CommitmentBudget' => ['CommitmentBudget'],
			'CommitmentLine' => ['CommitmentLine'],
			'CommitmentMovement' => ['CommitmentMovement'],
			'ExpiryAlert' => ['ExpiryAlert'],
			'FrameworkAgreement' => ['FrameworkAgreement'],
			'GoodsReceipt' => ['GoodsReceipt'],
			'GoodsReceiptLine' => ['GoodsReceiptLine'],
			'GoodsReceiptNote' => ['GoodsReceiptNote'],
			'InboundPayable' => ['InboundPayable'],
			'InventoryCount' => ['InventoryCount'],
			'InventoryCycleCount' => ['InventoryCycleCount'],
			'InventoryCycleCountLine' => ['InventoryCycleCountLine'],
			'InventoryGLConfig' => ['InventoryGLConfig'],
			'InventoryLot' => ['InventoryLot'],
			'InventoryReorderRule' => ['InventoryReorderRule'],
			'InventoryStock' => ['InventoryStock'],
			'InventoryStockTransfer' => ['InventoryStockTransfer'],
			'InventoryTransfer' => ['InventoryTransfer'],
			'InventoryValuation' => ['InventoryValuation'],
			'InventoryVarianceReason' => ['InventoryVarianceReason'],
			'Location' => ['Location'],
			'MobileScannerSyncBatch' => ['MobileScannerSyncBatch'],
			'OrderFulfilment' => ['OrderFulfilment'],
			'OrderPick' => ['OrderPick'],
			'PurchaseOrder' => ['PurchaseOrder'],
			'PurchaseOrderLine' => ['PurchaseOrderLine'],
			'Receipt' => ['Receipt'],
			'Requisition' => ['Requisition'],
			'RequisitionLine' => ['RequisitionLine'],
			'SpendingMandate' => ['SpendingMandate'],
			'StockMove' => ['StockMove'],
			'SupplierInvoice' => ['SupplierInvoice'],
			'SupplierQualification' => ['SupplierQualification'],
			'SvcReceipt' => ['SvcReceipt'],
			'SvcReceiptLine' => ['SvcReceiptLine'],
			'TenderNedProcurement' => ['TenderNedProcurement'],
			'ThreeWayMatch' => ['ThreeWayMatch'],
			'ToleranceProfile' => ['ToleranceProfile'],
			'VendorPerformance' => ['VendorPerformance'],
		];
	}

	#[DataProvider('schemas')]
	public function testSchemaIsAuthorized(string $name): void {
		$this->assertSchemaAuthorized($name);
	}
}
