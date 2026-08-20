<?php

/**
 * Lot-tracking goods-receipt guard — ADR-031 exception-path guard.
 *
 * Enforces REQ-LOT-008 / REQ-LOT-012: when a Product (the shillinq catalogue
 * slug for the spec's 'InventoryItem' entity) carries
 * `requiresLotTracking: true`, any GoodsReceipt of that SKU MUST reference a
 * corresponding InventoryLot. The check sits on the GoodsReceipt save path
 * because the cross-schema lookup ("does any InventoryLot exist for this
 * receipt's SKU + goodsReceiptId?") cannot be expressed in the declarative
 * lifecycle DSL without coupling InventoryLot creation order to the receipt.
 *
 * Single public method, pure validation, no persistence per ADR-031
 * §"PHP guards remain a legitimate seam".
 *
 * Exception documented in
 * openspec/changes/inventory-lot-batch-expiry/design.md §D2.
 *
 * @category Lifecycle
 * @package  OCA\Shillinq\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inventory-lot-batch-expiry/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

use InvalidArgumentException;

/**
 * Validates that GoodsReceipts of lot-tracked Products carry a lot reference.
 *
 * Caller passes the receipt under save, the Product master record for the
 * receipt's SKU, and the list of InventoryLot records bearing the receipt's
 * id as `goodsReceiptId`. The guard rejects the save when the product is
 * marked `requiresLotTracking: true` and no lot references the receipt.
 *
 * @spec openspec/specs/inventory-lot-batch-expiry/spec.md
 */
class LotTrackingReceiptGuard {
	/**
	 * Validate a GoodsReceipt save against the lot-tracking requirement.
	 *
	 * @param array<string,mixed> $receipt Save payload of the GoodsReceipt.
	 * @param array<string,mixed>|null $product Product master record for the receipt SKU (null = not found).
	 * @param array<int,array<string,mixed>> $lots InventoryLot records referencing this receipt's id.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the product requires lot tracking and no lot is supplied.
	 *
	 * @spec openspec/specs/inventory-lot-batch-expiry/spec.md
	 */
	public function validate(array $receipt, ?array $product, array $lots): void {
		if ($product === null) {
			// Unknown product — leave to the existing FK validator; not our concern.
			return;
		}

		$requiresLot = (bool)($product['requiresLotTracking'] ?? false);
		if ($requiresLot === false) {
			return;
		}

		$sku = (string)($receipt['sku'] ?? '');
		foreach ($lots as $lot) {
			$lotSku = (string)($lot['productSku'] ?? '');
			if ($lotSku === $sku && $lotSku !== '') {
				return;
			}
		}

		throw new InvalidArgumentException(
			sprintf(
				'Lot number required for tracked item: Product "%s" has requiresLotTracking=true; receipt MUST reference an InventoryLot.',
				$sku
			)
		);

	}//end validate()
}//end class
