<?php

/**
 * Whether one purchase-order approval chain entry is approved
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Kernel
 * @package  OCA\Shillinq\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
 */

declare(strict_types=1);

namespace OCA\Shillinq\Kernel;

use OCA\Shillinq\Kernel\Contracts\PurchaseOrderApprovalServiceInterface;

/**
 * The approval-entry rule, readable from every component without naming
 * the approval service.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
 */
final class PurchaseOrderApprovalEntry {

	/**
	 * Whether a chain entry carries an approved decision with a decision time.
	 *
	 * @param mixed $entry One approval chain entry.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-core/spec.md
	 */
	public static function isApproved(mixed $entry): bool {
		if (is_array($entry) === false) {
			return false;
		}

		return (string)($entry['decision'] ?? '') === PurchaseOrderApprovalServiceInterface::DECISION_APPROVED
			&& trim((string)($entry['decidedAt'] ?? '')) !== '';
	}//end isApproved()
}//end class
