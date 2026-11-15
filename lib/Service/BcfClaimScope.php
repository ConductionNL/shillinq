<?php

/**
 * The register, administration and quarter one BCF claim reads.
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
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * Scope of one compensable-VAT claim (REQ-BCF-012): the register the ledger
 * lives in, the server-resolved administration and the claim quarter.
 *
 * @spec openspec/specs/bookkeeping-bcf-vat-compensation/spec.md#req-bcf-004
 */
final class BcfClaimScope {

	/**
	 * Construct the scope.
	 *
	 * @param string $register The register slug.
	 * @param string $administrationId The administration.
	 * @param string $claimQuarter The claim quarter (GLLine.periodId).
	 */
	public function __construct(
		public readonly string $register,
		public readonly string $administrationId,
		public readonly string $claimQuarter,
	) {

	}//end __construct()
}//end class
