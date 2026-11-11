<?php

/**
 * Shillinq LeaseTermsChange
 *
 * The new contract terms of a lease modification (IFRS 16.44) and the
 * remeasurement approach the caller selected for it.
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
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

/**
 * A lease modification's field overrides and approach.
 *
 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
 */
final class LeaseTermsChange {
	/**
	 * Hold the change.
	 *
	 * @param array<string,mixed> $newTerms Field overrides to apply on the lease snapshot.
	 * @param string $approach catch-up-adjustment|prospective|separate-lease.
	 */
	public function __construct(
		public readonly array $newTerms,
		public readonly string $approach,
	) {
	}//end __construct()

	/**
	 * The approach, falling back to catch-up-adjustment for an unknown one.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/bookkeeping-lease-reassessment/spec.md
	 */
	public function validApproach(): string {
		if (in_array($this->approach, ['catch-up-adjustment', 'prospective', 'separate-lease'], true) === false) {
			return 'catch-up-adjustment';
		}

		return $this->approach;
	}//end validApproach()
}//end class
