<?php

/**
 * Receipt Names
 *
 * How a receipt controller names its receipt in a refusal: the error for a
 * malformed receipt id and the masked not-found error.
 *
 * @category Controller
 * @package  OCA\Shillinq\Controller
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

namespace OCA\Shillinq\Controller;

/**
 * The refusal messages that name one kind of receipt.
 *
 * @spec openspec/specs/bookkeeping-purchase-order-3way/spec.md#req-po3w-003
 */
final class ReceiptNames {
	/**
	 * Constructor.
	 *
	 * @param string $invalidId The 400 error for a malformed receipt id.
	 * @param string $notFound  The masked 404 error for a receipt outside the caller's administrations.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $invalidId,
		public readonly string $notFound,
	) {
	}//end __construct()
}//end class
