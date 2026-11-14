<?php

/**
 * One VatPreconditionGuard::validate request.
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
 * @spec openspec/changes/bookings-nl-btw-invoice/tasks.md#task-8
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Lifecycle;

/**
 * The invoice being issued, its administration and the resolved register.
 *
 * @spec openspec/changes/bookings-nl-btw-invoice/tasks.md#task-8
 */
final class VatPreconditionRun {

	/**
	 * Construct the run.
	 *
	 * @param string $invoiceId The ARInvoice id.
	 * @param string $administrationId The administration FK.
	 * @param string $register The register slug.
	 */
	public function __construct(
		public readonly string $invoiceId,
		public readonly string $administrationId,
		public readonly string $register,
	) {

	}//end __construct()
}//end class
