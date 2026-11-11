<?php

/**
 * Shillinq DunningWriteOff
 *
 * One write-off of an uncollectable invoice (OninbaarAfschrijving) as
 * DunningRunService materialises it: the invoice, the principal and VAT
 * written off, the target VAT period, the art. 29 OB declaration and evidence,
 * and the GL transaction the write-off is booked under.
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

/**
 * A write-off with the GL transaction it is booked under ('' while none).
 *
 * @spec openspec/specs/bookkeeping-credit-control-dunning/spec.md
 */
final class DunningWriteOff {
	/**
	 * Hold the write-off.
	 *
	 * @param string $administrationId Administration scope.
	 * @param string $invoiceId Invoice FK.
	 * @param float $principal Principal written off (EUR).
	 * @param mixed $vatAmount The VAT written off as supplied (null when none).
	 * @param string $period Target VAT period (e.g. `2026-Q2`).
	 * @param string $entryId The GLTransaction id the write-off is booked under, '' while none.
	 * @param string $art29OBDeclaration The art. 29 OB declaration text.
	 * @param mixed $evidenceRef The evidence reference as supplied (null when none).
	 */
	public function __construct(
		public readonly string $administrationId,
		public readonly string $invoiceId,
		public readonly float $principal,
		public readonly mixed $vatAmount,
		public readonly string $period,
		public readonly string $entryId,
		public readonly string $art29OBDeclaration,
		public readonly mixed $evidenceRef,
	) {
	}//end __construct()

	/**
	 * The same write-off, booked under the given GL transaction.
	 *
	 * @param string $entryId The GLTransaction id.
	 *
	 * @return self
	 *
	 * @spec openspec/changes/bookkeeping-credit-control-dunning/tasks.md#task-26
	 */
	public function withEntryId(string $entryId): self {
		return new self(
			administrationId: $this->administrationId,
			invoiceId: $this->invoiceId,
			principal: $this->principal,
			vatAmount: $this->vatAmount,
			period: $this->period,
			entryId: $entryId,
			art29OBDeclaration: $this->art29OBDeclaration,
			evidenceRef: $this->evidenceRef,
		);
	}//end withEntryId()
}//end class
