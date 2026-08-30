<?php

/**
 * FEFO (First-Expiry-First-Out) sort — ADR-031 exception-path guard.
 *
 * Invoked by the lot-list endpoint when OpenRegister's declarative
 * x-openregister-sort directive is treated as advisory rather than enforced
 * at the API query layer (Risk 1 in
 * openspec/changes/inventory-lot-batch-expiry/proposal.md). Single public
 * method, no persistence, pure ordering per ADR-031 §"PHP guards remain a
 * legitimate seam". Lots without an expiryDate sort after dated lots
 * (NULL-last semantics per REQ-LOT-005).
 *
 * Exception documented in
 * openspec/changes/inventory-lot-batch-expiry/design.md §D2.
 *
 * @category Sort
 * @package  OCA\Shillinq\Sort
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

namespace OCA\Shillinq\Sort;

/**
 * ADR-031 exception guard applying FEFO order to a lot list.
 *
 * The declarative x-openregister-sort directive on InventoryLot is the
 * canonical FEFO source. This guard is only invoked when the directive
 * is not enforced at the API query layer; the spec stays shape-neutral
 * per design.md D2.
 *
 * @spec openspec/specs/inventory-lot-batch-expiry/spec.md
 */
class FefoSort {
	/**
	 * Sort a list of InventoryLot records by FEFO order (expiryDate ASC NULLS LAST).
	 *
	 * Stable for equal-expiry lots: original input order is preserved per
	 * PHP's usort guarantee for tied comparisons. Records without an
	 * `expiryDate` (null or empty string) sort after all dated records per
	 * REQ-LOT-005 NULL-last semantics.
	 *
	 * @param array<int,array<string,mixed>> $lots Raw InventoryLot rows.
	 *
	 * @return array<int,array<string,mixed>> The same rows reordered FEFO.
	 *
	 * @spec openspec/specs/inventory-lot-batch-expiry/spec.md
	 */
	public function sortLots(array $lots): array {
		usort(
			$lots,
			static function (array $left, array $right): int {
				$leftExpiry = ($left['expiryDate'] ?? null);
				$rightExpiry = ($right['expiryDate'] ?? null);
				$leftMissing = ($leftExpiry === null || $leftExpiry === '');
				$rightMissing = ($rightExpiry === null || $rightExpiry === '');
				if ($leftMissing === true && $rightMissing === true) {
					return 0;
				}

				if ($leftMissing === true) {
					return 1;
				}

				if ($rightMissing === true) {
					return -1;
				}

				return strcmp((string)$leftExpiry, (string)$rightExpiry);
			}
		);

		return $lots;
	}//end sortLots()
}//end class
