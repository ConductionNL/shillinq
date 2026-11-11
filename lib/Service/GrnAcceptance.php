<?php

/**
 * Shillinq GrnAcceptance
 *
 * A goods receipt note on its way to accepted: the administration it is
 * scoped to, the note as loaded and its record id. Built once by
 * GoodsReceiptNoteService::acceptGRN() for the stock-move credits of its lines.
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
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A goods receipt note being accepted, in its administration.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class GrnAcceptance {
	/**
	 * Hold the acceptance.
	 *
	 * @param string $administrationId Administration scope.
	 * @param array<string,mixed> $grn The GRN being accepted, as loaded.
	 * @param string $grnRecordId The GRN's record id (its lines reference it).
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly array $grn,
		public readonly string $grnRecordId,
	) {
	}//end __construct()
}//end class
