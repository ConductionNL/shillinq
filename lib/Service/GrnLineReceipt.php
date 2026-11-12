<?php

/**
 * Shillinq GrnLineReceipt
 *
 * One accepted goods receipt line together with the purchase order line it
 * receives against: the pair a StockMove credit is built from.
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
 * An accepted GRN line with the PO line it receives against.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class GrnLineReceipt {
	/**
	 * Hold the line pair.
	 *
	 * @param array<string,mixed> $grnLine The GRN line being credited.
	 * @param array<string,mixed> $poLine The PO line it receives against.
	 */
	public function __construct(
		public readonly array $grnLine,
		public readonly array $poLine,
	) {
	}//end __construct()

	/**
	 * The trimmed PO line id the GRN line references.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function poLineId(): string {
		return trim((string)($this->grnLine['poLineId'] ?? ''));
	}//end poLineId()

	/**
	 * The GRN line's record id.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
	 */
	public function grnLineId(): string {
		return (string)($this->grnLine['id'] ?? ($this->grnLine['@self']['id'] ?? ''));
	}//end grnLineId()
}//end class
