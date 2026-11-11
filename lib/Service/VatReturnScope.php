<?php

/**
 * Shillinq VatReturnScope
 *
 * The VAT return and administration that a derivation writes its
 * declarations and lines under. Built once per derivation by
 * VATReturnService so the persistence helpers take the pair as one value.
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
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * The return a derivation writes to, and the administration it is scoped to.
 *
 * @spec openspec/specs/bookkeeping-vat-btw-filing/spec.md
 */
final class VatReturnScope {
	/**
	 * Hold the return and its administration.
	 *
	 * @param string $returnId The parent VATReturn id.
	 * @param string $administrationId The administration scope.
	 */
	public function __construct(
		public readonly string $returnId,
		public readonly string $administrationId,
	) {
	}//end __construct()
}//end class
