<?php

/**
 * A request to accrue statutory or commercial interest on an overdue principal.
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
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Service;

use DateTimeImmutable;

/**
 * The party, principal, accrual window and optional flat rate overrides
 * {@see BIKStaffelCalculator::rente()} accrues interest for.
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
final class InterestAccrualRequest {
	/**
	 * Constructor.
	 *
	 * @param string            $partyType     'B2B' or 'B2C' (anything else is treated as B2B).
	 * @param float             $principal     Outstanding principal in EUR.
	 * @param DateTimeImmutable $effectiveDate First day the interest accrues.
	 * @param DateTimeImmutable $calculatedOn  Calculation date.
	 * @param float|null        $rateB2B       Flat B2B handelsrente override (skips the table).
	 * @param float|null        $rateB2C       Flat B2C wettelijke rente override (skips the table).
	 */
	public function __construct(
		public readonly string $partyType,
		public readonly float $principal,
		public readonly DateTimeImmutable $effectiveDate,
		public readonly DateTimeImmutable $calculatedOn,
		public readonly float|null $rateB2B = null,
		public readonly float|null $rateB2C = null,
	) {

	}//end __construct()

	/**
	 * Whether the wettelijke rente for consumers (art. 6:119 BW) applies.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
	 */
	public function isConsumer(): bool {
		return $this->partyType === 'B2C';

	}//end isConsumer()
}//end class
